// Package egress: per-run CONNECT + forward proxy (CP22).
//
// One Server instance serves exactly one run and dies with it. Every
// connection is checked against the pinned allow-list the run was accepted
// with: unknown host, wrong port, IP literal, or private-range IP gets 403
// and an audit refusal. Dialing uses pinned IPs only — the proxy never
// resolves DNS, so DNS cannot be abused as an exfil channel either.
package egress

import (
	"bufio"
	"context"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/textproto"
	"strconv"
	"strings"
	"time"

	"github.com/metadist/synaplan-compute/pkg/contract"
)

const (
	// DefaultPort is the proxy listen port inside the per-run container.
	DefaultPort = 3128
	// DefaultMaxBodyBytes caps a single proxied body in either direction.
	// Runs fetch small payloads (models, datasets go through artefacts);
	// anything larger is cut and audited instead of turning the sidecar
	// into a file hose.
	DefaultMaxBodyBytes = 256 << 20
	// DefaultMaxConns bounds concurrent tunnels per run.
	DefaultMaxConns = 16

	dialTimeout       = 10 * time.Second
	headerTimeout     = 30 * time.Second
	tunnelIdleTimeout = 120 * time.Second

	maxRefusalReason = 160
)

// Server is an http.Handler enforcing a pinned allow-list.
type Server struct {
	proxy   *Proxy
	maxBody int64
	sem     chan struct{}
	dialer  *net.Dialer
	// onRefused is called for every refused or cut connection (nil-safe).
	// Reason is a short machine string (unknown_host, ip_literal,
	// private_ip, bad_method, body_cap, ...).
	onRefused func(host string, port int, reason string)
}

// Option tunes a Server. Zero values select defaults.
type Option struct {
	// MaxBodyBytes caps one body per direction; <=0 selects the default.
	MaxBodyBytes int64
	// MaxConns bounds concurrent tunnels; <=0 selects the default.
	MaxConns int
	// OnRefused receives every refusal for audit; may be nil.
	OnRefused func(host string, port int, reason string)
}

// NewServer builds a proxy for exactly allow (already validated by
// CheckAllowList at run admission).
func NewServer(allow []contract.EgressHost, opt Option) *Server {
	maxBody := opt.MaxBodyBytes
	if maxBody <= 0 {
		maxBody = DefaultMaxBodyBytes
	}
	maxConns := opt.MaxConns
	if maxConns <= 0 {
		maxConns = DefaultMaxConns
	}
	return &Server{
		proxy:     NewProxy(allow),
		maxBody:   maxBody,
		sem:       make(chan struct{}, maxConns),
		dialer:    &net.Dialer{Timeout: dialTimeout},
		onRefused: opt.OnRefused,
	}
}

// ServeHTTP routes CONNECT tunnels and absolute-URI forwarding. Anything
// else is a misconfigured client, not an egress attempt.
//
// Deliberately no proxy authentication: run containers reach only their own
// proxy (single-purpose internal network, no route anywhere else), and the
// shared outbound network carries proxies only. A 407 challenge would break
// urllib (no auto-retry) — the primary script client — for no topological
// gain. See THREAT_MODEL.md.
func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	select {
	case s.sem <- struct{}{}:
		defer func() { <-s.sem }()
	default:
		http.Error(w, "too many proxied connections", http.StatusServiceUnavailable)
		return
	}
	if r.Method == http.MethodConnect {
		s.handleConnect(w, r)
		return
	}
	if r.URL != nil && r.URL.IsAbs() && strings.EqualFold(r.URL.Scheme, "http") {
		s.handleForward(w, r)
		return
	}
	http.Error(w, "proxy expects CONNECT or an absolute http:// URL", http.StatusBadRequest)
}

var forwardMethods = map[string]bool{
	http.MethodGet: true, http.MethodPost: true, http.MethodPut: true,
	http.MethodDelete: true, http.MethodHead: true, http.MethodOptions: true,
	http.MethodPatch: true,
}

var hopHeaders = map[string]bool{
	"connection": true, "proxy-authenticate": true, "proxy-authorization": true,
	"te": true, "trailer": true, "transfer-encoding": true, "upgrade": true,
	"keep-alive": true,
}

// resolve checks host:port against the pin map and returns dialable IPs.
// IP literals are always refused: callers name hosts, the proxy owns DNS.
func (s *Server) resolve(host string, port int) ([]net.IP, string) {
	host = strings.ToLower(strings.TrimSuffix(strings.TrimSpace(host), "."))
	if host == "" || port <= 0 || port > 65535 {
		return nil, "bad_target"
	}
	if net.ParseIP(host) != nil {
		return nil, "ip_literal"
	}
	pins := s.proxy.Pins(host, port)
	if len(pins) == 0 {
		return nil, "unknown_host"
	}
	// Copy: pins aliases the shared pin map and concurrent requests must
	// never mutate it.
	out := make([]net.IP, 0, len(pins))
	for _, ip := range pins {
		if privateIP(ip) {
			return nil, "private_ip"
		}
		out = append(out, ip)
	}
	return out, ""
}

func (s *Server) refuse(w http.ResponseWriter, host string, port int, reason string) {
	if len(reason) > maxRefusalReason {
		reason = reason[:maxRefusalReason]
	}
	if s.onRefused != nil {
		s.onRefused(host, port, reason)
	}
	http.Error(w, "egress refused: "+reason, http.StatusForbidden)
}

// handleConnect tunnels TLS (or raw TCP) to a pinned IP after a 200.
func (s *Server) handleConnect(w http.ResponseWriter, r *http.Request) {
	host, portStr, err := net.SplitHostPort(r.Host)
	if err != nil {
		http.Error(w, "CONNECT needs host:port", http.StatusBadRequest)
		return
	}
	port, err := strconv.Atoi(portStr)
	if err != nil {
		http.Error(w, "CONNECT needs a numeric port", http.StatusBadRequest)
		return
	}
	pins, reason := s.resolve(host, port)
	if reason != "" {
		s.refuse(w, host, port, reason)
		return
	}
	upstream, err := s.dialPinned(r.Context(), pins, port)
	if err != nil {
		http.Error(w, "upstream unreachable", http.StatusBadGateway)
		return
	}
	hj, ok := w.(http.Hijacker)
	if !ok {
		_ = upstream.Close()
		http.Error(w, "hijack unsupported", http.StatusInternalServerError)
		return
	}
	client, rw, err := hj.Hijack()
	if err != nil {
		_ = upstream.Close()
		http.Error(w, "hijack failed", http.StatusInternalServerError)
		return
	}
	_ = client.SetDeadline(time.Now().Add(tunnelIdleTimeout))
	if _, err := client.Write([]byte("HTTP/1.1 200 Connection established\r\n\r\n")); err != nil {
		_ = client.Close()
		_ = upstream.Close()
		return
	}
	s.relay(r.Context(), host, port, client, rw.Reader, upstream)
}

// handleForward proxies one absolute-URI http:// request.
func (s *Server) handleForward(w http.ResponseWriter, r *http.Request) {
	if !forwardMethods[r.Method] {
		s.refuse(w, r.URL.Hostname(), 0, "bad_method")
		return
	}
	port := 80
	if p := r.URL.Port(); p != "" {
		var err error
		port, err = strconv.Atoi(p)
		if err != nil {
			http.Error(w, "bad port", http.StatusBadRequest)
			return
		}
	}
	pins, reason := s.resolve(r.URL.Hostname(), port)
	if reason != "" {
		s.refuse(w, r.URL.Hostname(), port, reason)
		return
	}
	var body io.Reader
	if r.Body != nil {
		body = http.MaxBytesReader(w, r.Body, s.maxBody)
	}
	out, err := http.NewRequestWithContext(r.Context(), r.Method, r.URL.String(), body)
	if err != nil {
		http.Error(w, "bad request", http.StatusBadRequest)
		return
	}
	removeHopHeaders(r.Header)
	out.Header = r.Header.Clone()
	out.Close = true
	resp, err := s.roundTrip(out, pins, port)
	if err != nil {
		http.Error(w, "upstream unreachable", http.StatusBadGateway)
		return
	}
	defer resp.Body.Close()
	removeHopHeaders(resp.Header)
	for k, vv := range resp.Header {
		for _, v := range vv {
			w.Header().Add(k, v)
		}
	}
	w.WriteHeader(resp.StatusCode)
	_, _ = io.CopyN(w, resp.Body, s.maxBody+1)
}

// roundTrip dials a pinned IP and performs one exchange.
func (s *Server) roundTrip(req *http.Request, pins []net.IP, port int) (*http.Response, error) {
	ctx, cancel := context.WithTimeout(req.Context(), headerTimeout)
	defer cancel()
	req = req.WithContext(ctx)
	var lastErr error
	for _, ip := range pins {
		conn, err := s.dialer.DialContext(req.Context(), "tcp", net.JoinHostPort(ip.String(), strconv.Itoa(port)))
		if err != nil {
			lastErr = err
			continue
		}
		tr := &http.Transport{
			DialContext: func(context.Context, string, string) (net.Conn, error) { return conn, nil },
		}
		resp, err := tr.RoundTrip(req)
		if err != nil {
			_ = conn.Close()
			lastErr = err
			continue
		}
		return resp, nil
	}
	if lastErr == nil {
		lastErr = fmt.Errorf("no pinned ip dialed")
	}
	return nil, lastErr
}

// dialPinned connects to the first reachable pinned IP.
func (s *Server) dialPinned(ctx context.Context, pins []net.IP, port int) (net.Conn, error) {
	var lastErr error
	for _, ip := range pins {
		conn, err := s.dialer.DialContext(ctx, "tcp", net.JoinHostPort(ip.String(), strconv.Itoa(port)))
		if err != nil {
			lastErr = err
			continue
		}
		return conn, nil
	}
	if lastErr == nil {
		lastErr = fmt.Errorf("no pinned ip dialed")
	}
	return nil, lastErr
}

// relay copies both directions until EOF, error, idle timeout, or the body
// cap. Exceeding the cap closes the tunnel and audits the cut.
func (s *Server) relay(ctx context.Context, host string, port int, client net.Conn, buffered *bufio.Reader, upstream net.Conn) {
	defer func() {
		_ = client.Close()
		_ = upstream.Close()
	}()
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	go func() {
		<-ctx.Done()
		_ = client.Close()
		_ = upstream.Close()
	}()
	done := make(chan error, 2)
	up := &cappedWriter{to: upstream, left: s.maxBody, capped: func() {
		s.cut(host, port)
	}}
	down := &cappedWriter{to: client, left: s.maxBody, capped: func() {
		s.cut(host, port)
	}}
	go func() {
		_, err := io.Copy(up, buffered)
		done <- err
	}()
	go func() {
		_, err := io.Copy(down, upstream)
		done <- err
	}()
	<-done
	<-done
}

func (s *Server) cut(host string, port int) {
	if s.onRefused != nil {
		s.onRefused(host, port, "body_cap")
	}
}

// cappedWriter errors after n bytes so tunnels cannot hose through the cap.
type cappedWriter struct {
	to     io.Writer
	left   int64
	capped func()
	fired  bool
}

func (w *cappedWriter) Write(p []byte) (int, error) {
	if int64(len(p)) > w.left {
		if !w.fired {
			w.fired = true
			if w.capped != nil {
				w.capped()
			}
		}
		return 0, fmt.Errorf("proxy body cap exceeded")
	}
	n, err := w.to.Write(p)
	w.left -= int64(n)
	return n, err
}

func removeHopHeaders(h http.Header) {
	for k := range h {
		if hopHeaders[strings.ToLower(textproto.TrimString(k))] {
			h.Del(k)
		}
	}
}
