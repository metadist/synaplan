package relay

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/metadist/synaplan-watchdog/internal/token"
	"github.com/redis/go-redis/v9"
)

const attachType = "application/vnd.synaplan.attach+json"

func Enabled(getenv func(string) string) bool {
	switch strings.ToLower(strings.TrimSpace(getenv("SYNAPLAN_RELAY"))) {
	case "1", "true", "yes", "on":
		return true
	default:
		return false
	}
}

type Config struct {
	Listen   string
	Upstream string
	Secret   string
	Attach   string
	Prefix   string
	RedisURL string
	Slots    int
}

func ConfigFrom(getenv func(string) string, prefix string) Config {
	slots := 320
	if raw := getenv("WATCHDOG_STREAM_SLOTS"); raw != "" {
		if parsed, err := strconv.Atoi(raw); err == nil && parsed > 0 {
			slots = parsed
		}
	}
	return Config{
		Listen: "127.0.0.1:8097",
		// 9080 is loopback PHP. Port 80 is Caddy, which sends these routes back here.
		Upstream: first(getenv("SYNAPLAN_PHP_UPSTREAM"), "http://127.0.0.1:9080"),
		Secret:   getenv("SYNAPLAN_RELAY_SECRET"),
		Attach:   getenv("SYNAPLAN_ATTACH_SECRET"),
		Prefix:   prefix,
		RedisURL: first(getenv("REDIS_URL"), getenv("REDIS_DSN")),
		Slots:    slots,
	}
}

type Server struct {
	cfg   Config
	php   *http.Client
	redis *redis.Client
	mu    sync.Mutex
	busy  int
}

func New(cfg Config) *Server {
	s := &Server{
		cfg: cfg,
		php: &http.Client{Transport: upstreamTransport(), Timeout: 0, CheckRedirect: func(*http.Request, []*http.Request) error {
			return http.ErrUseLastResponse
		}},
	}
	if cfg.RedisURL != "" {
		if options, err := redis.ParseURL(cfg.RedisURL); err == nil {
			s.redis = redis.NewClient(options)
		}
	}
	return s
}

func (s *Server) ListenAndServe(ctx context.Context) error {
	ln, err := net.Listen("tcp", s.cfg.Listen)
	if err != nil {
		return err
	}
	srv := &http.Server{
		Handler:           s,
		ReadHeaderTimeout: 10 * time.Second,
	}
	errCh := make(chan error, 1)
	go func() {
		errCh <- srv.Serve(ln)
	}()
	select {
	case <-ctx.Done():
		shut, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()
		_ = srv.Shutdown(shut)
		return nil
	case err := <-errCh:
		if err == http.ErrServerClosed {
			return nil
		}
		return err
	}
}

func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if !s.takeSlot() {
		writeBusy(w, r.URL.Path)
		return
	}
	defer s.freeSlot()

	upstream, err := http.NewRequestWithContext(r.Context(), r.Method, s.cfg.Upstream+r.URL.RequestURI(), r.Body)
	if err != nil {
		http.Error(w, "bad request", http.StatusBadRequest)
		return
	}
	copyHeaders(upstream.Header, r.Header)
	if s.cfg.Secret != "" {
		upstream.Header.Set("X-Synaplan-Relay", s.cfg.Secret)
	}
	resp, err := s.php.Do(upstream)
	if err != nil {
		http.Error(w, "upstream unavailable", http.StatusBadGateway)
		return
	}
	defer resp.Body.Close()

	if strings.Contains(resp.Header.Get("Content-Type"), attachType) {
		s.hold(w, r, resp)
		return
	}
	if resp.StatusCode == http.StatusServiceUnavailable && strings.Contains(strings.ToLower(resp.Header.Get("Content-Type")), "text/plain") {
		copyHeaders(w.Header(), resp.Header)
		writeBusy(w, r.URL.Path)
		return
	}
	copyUpstream(w, resp)
}

func upstreamTransport() *http.Transport {
	return &http.Transport{Proxy: nil, IdleConnTimeout: 30 * time.Second}
}

func (s *Server) hold(w http.ResponseWriter, r *http.Request, resp *http.Response) {
	body, err := io.ReadAll(io.LimitReader(resp.Body, 1<<16))
	var raw struct {
		RunID          string `json:"runId"`
		Token          string `json:"token"`
		FromSeq        int64  `json:"fromSeq"`
		SSEIDs         bool   `json:"sseIds"`
		Terminal       string `json:"terminal"`
		IdleTimeoutSec int    `json:"idleTimeoutSec"`
	}
	if err != nil || json.Unmarshal(body, &raw) != nil || raw.RunID == "" || s.cfg.Attach == "" {
		http.Error(w, "bad directive", http.StatusBadGateway)
		return
	}
	runID, owner, seq, _, err := token.Peek(raw.Token)
	if err != nil || runID != raw.RunID || seq != raw.FromSeq || token.Verify(s.cfg.Attach, raw.Token, runID, owner, seq, time.Now().Unix()) != nil {
		http.Error(w, "invalid attach token", http.StatusForbidden)
		return
	}
	if s.redis == nil {
		writeRaw(w, resp.Header, raw.Terminal)
		return
	}
	s.copy(r.Context(), w, resp.Header, raw.RunID, raw.FromSeq, Directive{SSEIDs: raw.SSEIDs, Terminal: raw.Terminal, Idle: raw.IdleTimeoutSec, KeepAlive: 15})
}

func (s *Server) copy(ctx context.Context, w http.ResponseWriter, upstream http.Header, runID string, fromSeq int64, d Directive) {
	applyStreamHeaders(w, upstream)
	w.WriteHeader(http.StatusOK)
	flusher, _ := w.(http.Flusher)
	flush := func(text string) {
		if text == "" {
			return
		}
		_, _ = io.WriteString(w, text)
		if flusher != nil {
			flusher.Flush()
		}
	}
	key := s.cfg.Prefix + "chatrun:" + runID + ":stream"
	aliveKey := s.cfg.Prefix + "pump:alive"
	last := fmt.Sprintf("0-%d", fromSeq)
	state := &State{}
	lastTick := time.Now()
	for {
		if ctx.Err() != nil {
			return
		}
		alive := false
		if n, err := s.redis.Exists(ctx, aliveKey).Result(); err == nil && n == 1 {
			alive = true
		}
		streams, err := s.redis.XRead(ctx, &redis.XReadArgs{
			Streams: []string{key, last},
			Count:   32,
			Block:   500 * time.Millisecond,
		}).Result()
		elapsed := int(time.Since(lastTick) / time.Millisecond)
		lastTick = time.Now()
		if err != nil {
			if ctx.Err() != nil {
				return
			}
			text, done := Step(state, nil, elapsed, d, alive)
			flush(text)
			if done {
				return
			}
			continue
		}
		for _, stream := range streams {
			for _, msg := range stream.Messages {
				last = msg.ID
				seq := seqOf(msg.ID)
				frame := &Frame{
					Seq:  seq,
					Kind: Kind(valueString(msg.Values["kind"])),
					Body: valueString(msg.Values["frame"]),
				}
				text, done := Step(state, frame, 0, d, alive)
				flush(text)
				if done {
					return
				}
			}
		}
	}
}

func (s *Server) takeSlot() bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.busy >= s.cfg.Slots {
		return false
	}
	s.busy++
	return true
}

func (s *Server) freeSlot() {
	s.mu.Lock()
	s.busy--
	s.mu.Unlock()
}

func writeBusy(w http.ResponseWriter, path string) {
	w.Header().Set("Retry-After", "5")
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusServiceUnavailable)
	body := `{"error":"server_busy","retryAfter":5}`
	if strings.HasPrefix(path, "/v1/messages") {
		body = `{"type":"error","error":{"type":"overloaded_error","message":"Synaplan is busy. Retry in a few seconds."}}`
	} else if strings.HasPrefix(path, "/v1/") {
		body = `{"error":{"message":"Synaplan is busy. Retry in a few seconds.","type":"server_error","code":"server_busy"}}`
	}
	_, _ = io.WriteString(w, body)
}

func writeRaw(w http.ResponseWriter, upstream http.Header, frame string) {
	applyStreamHeaders(w, upstream)
	w.WriteHeader(http.StatusOK)
	_, _ = io.WriteString(w, frame)
	if flusher, ok := w.(http.Flusher); ok {
		flusher.Flush()
	}
}

func copyUpstream(w http.ResponseWriter, resp *http.Response) {
	copyHeaders(w.Header(), resp.Header)
	w.WriteHeader(resp.StatusCode)
	if !strings.Contains(resp.Header.Get("Content-Type"), "text/event-stream") {
		_, _ = io.Copy(w, resp.Body)
		return
	}
	flusher, _ := w.(http.Flusher)
	buf := make([]byte, 1024)
	for {
		n, err := resp.Body.Read(buf)
		if n > 0 {
			_, _ = w.Write(buf[:n])
			if flusher != nil {
				flusher.Flush()
			}
		}
		if err != nil {
			return
		}
	}
}

func applyStreamHeaders(w http.ResponseWriter, upstream http.Header) {
	if upstream != nil {
		copyHeaders(w.Header(), upstream)
	}
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("X-Accel-Buffering", "no")
}

func copyHeaders(dst, src http.Header) {
	hop := map[string]bool{
		"Connection": true, "Keep-Alive": true, "Proxy-Authenticate": true, "Proxy-Authorization": true,
		"Te": true, "Trailers": true, "Transfer-Encoding": true, "Upgrade": true, "Content-Length": true,
	}
	for name, values := range src {
		if !hop[name] {
			dst[name] = append(dst[name], values...)
		}
	}
}

func seqOf(id string) int64 {
	_, seq, _ := strings.Cut(id, "-")
	parsed, _ := strconv.ParseInt(seq, 10, 64)
	return parsed
}

func valueString(value interface{}) string {
	switch typed := value.(type) {
	case string:
		return typed
	case []byte:
		return string(typed)
	default:
		return ""
	}
}

func first(value, fallback string) string {
	if value == "" {
		return fallback
	}
	return value
}
