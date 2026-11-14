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

// Enabled is true when SYNAPLAN_RELAY is on.
func Enabled(getenv func(string) string) bool {
	switch strings.ToLower(strings.TrimSpace(getenv("SYNAPLAN_RELAY"))) {
	case "1", "true", "yes", "on":
		return true
	default:
		return false
	}
}

// Config is the relay's boot settings.
type Config struct {
	Listen   string
	Upstream string
	Secret   string
	Attach   string
	Prefix   string
	RedisURL string
	Slots    int
}

// ConfigFrom reads the relay environment. The listener is loopback only.
func ConfigFrom(getenv func(string) string, prefix string) Config {
	slots := 320
	if raw := getenv("WATCHDOG_STREAM_SLOTS"); raw != "" {
		if parsed, err := strconv.Atoi(raw); err == nil && parsed > 0 {
			slots = parsed
		}
	}
	return Config{
		Listen: "127.0.0.1:8097",
		// 9080 is the loopback PHP listener. Port 80 is Caddy, which sends
		// these same routes back here when the relay is on.
		Upstream: first(getenv("SYNAPLAN_PHP_UPSTREAM"), "http://127.0.0.1:9080"),
		Secret:   getenv("SYNAPLAN_RELAY_SECRET"),
		Attach:   getenv("SYNAPLAN_ATTACH_SECRET"),
		Prefix:   prefix,
		RedisURL: first(getenv("REDIS_URL"), getenv("REDIS_DSN")),
		Slots:    slots,
	}
}

// Server forwards long routes to PHP and, when PHP hands back an attach
// directive, copies the run stream itself.
type Server struct {
	cfg   Config
	php   *http.Client
	redis *redis.Client
	mu    sync.Mutex
	busy  int
}

// New builds the relay. A missing Redis URL leaves the copy path unable to
// read frames; pass-through still works.
func New(cfg Config) *Server {
	transport := &http.Transport{
		Proxy:                 nil,
		ResponseHeaderTimeout: 60 * time.Second,
		IdleConnTimeout:       30 * time.Second,
	}
	s := &Server{
		cfg: cfg,
		php: &http.Client{Transport: transport, Timeout: 0, CheckRedirect: func(*http.Request, []*http.Request) error {
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

// ListenAndServe accepts only on loopback and stops when ctx is cancelled.
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

// ServeHTTP is one long route.
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
	copyUpstream(w, resp)
}

func (s *Server) hold(w http.ResponseWriter, r *http.Request, resp *http.Response) {
	body, err := io.ReadAll(io.LimitReader(resp.Body, 1<<16))
	if err != nil {
		http.Error(w, "bad directive", http.StatusBadGateway)
		return
	}
	var raw struct {
		RunID          string `json:"runId"`
		Token          string `json:"token"`
		FromSeq        int64  `json:"fromSeq"`
		SSEIDs         bool   `json:"sseIds"`
		Terminal       string `json:"terminal"`
		IdleTimeoutSec int    `json:"idleTimeoutSec"`
	}
	if err := json.Unmarshal(body, &raw); err != nil || raw.RunID == "" || s.cfg.Attach == "" {
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
	s.copy(r.Context(), w, resp.Header, raw.RunID, raw.FromSeq, Directive{
		SSEIDs:    raw.SSEIDs,
		Terminal:  raw.Terminal,
		Idle:      raw.IdleTimeoutSec,
		KeepAlive: 15,
	})
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
	if strings.HasPrefix(path, "/v1/messages") {
		_, _ = io.WriteString(w, `{"type":"error","error":{"type":"overloaded_error","message":"Synaplan is busy. Retry in a few seconds."}}`)
		return
	}
	if strings.HasPrefix(path, "/v1/") {
		_, _ = io.WriteString(w, `{"error":{"message":"Synaplan is busy. Retry in a few seconds.","type":"server_error","code":"server_busy"}}`)
		return
	}
	_, _ = io.WriteString(w, `{"error":"server_busy","retryAfter":5}`)
}

func writeRaw(w http.ResponseWriter, upstream http.Header, frame string) {
	applyStreamHeaders(w, upstream)
	w.WriteHeader(http.StatusOK)
	_, _ = io.WriteString(w, frame)
	if flusher, ok := w.(http.Flusher); ok {
		flusher.Flush()
	}
}

// copyUpstream writes PHP's response. An event stream is flushed per chunk
// so a small frame is not held until the answer ends.
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
		"Connection": true, "Keep-Alive": true, "Proxy-Authenticate": true,
		"Proxy-Authorization": true, "Te": true, "Trailers": true,
		"Transfer-Encoding": true, "Upgrade": true, "Content-Length": true,
	}
	for name, values := range src {
		if hop[name] {
			continue
		}
		for _, value := range values {
			dst.Add(name, value)
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
