// Package proxy is the installation CONNECT gateway. It dials pinned
// addresses only for a one-request grant, and resolves an allowlisted name
// itself. It never terminates TLS.
package proxy

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"strconv"
	"strings"
	"sync/atomic"
	"time"
)

const (
	dialTimeout = 10 * time.Second
	tunnelIdle  = 120 * time.Second
)

// Grant is one typed-URL permission. The id is not a field: callers must
// not log it.
type Grant struct {
	Host string   `json:"host"`
	Port int      `json:"port"`
	IPs  []string `json:"ips"`
}

// Store is Redis, or a fake in tests. Take deletes the grant.
type Store interface {
	Rules(ctx context.Context) ([]string, error)
	Take(ctx context.Context, id string) (Grant, bool, error)
}

// DialFunc dials one address. Tests record it.
type DialFunc func(ctx context.Context, network, address string) (net.Conn, error)

// ResolveFunc looks up an allowlisted name. Grant tunnels do not use it.
type ResolveFunc func(ctx context.Context, host string) ([]net.IP, error)

// Server is the proxy plus /healthz and /metrics.
type Server struct {
	Store    Store
	Dial     DialFunc
	Resolve  ResolveFunc
	Upstream string // host:port of an enterprise proxy, empty to dial directly
	Log      *log.Logger
	allowed  atomic.Int64
	refused  atomic.Int64
}

// ServeHTTP accepts CONNECT, a health check, and metrics.
func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	switch {
	case r.URL != nil && r.URL.Path == "/healthz" && r.Method == http.MethodGet:
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("ok\n"))
	case r.URL != nil && r.URL.Path == "/metrics" && r.Method == http.MethodGet:
		fmt.Fprintf(w, "synaplan_egress_gateway_requests_total{outcome=\"allowed\"} %d\n", s.allowed.Load())
		fmt.Fprintf(w, "synaplan_egress_gateway_requests_total{outcome=\"refused\"} %d\n", s.refused.Load())
	case r.Method == http.MethodConnect:
		s.connect(w, r)
	default:
		http.Error(w, "CONNECT, /healthz, or /metrics", http.StatusBadRequest)
	}
}

func (s *Server) connect(w http.ResponseWriter, r *http.Request) {
	host, port, ok := split(r.Host)
	if !ok {
		http.Error(w, "CONNECT needs host:port", http.StatusBadRequest)
		return
	}
	ips, reason := s.decide(r.Context(), host, port, bearer(r.Header.Get("Proxy-Authorization")))
	if reason != "" {
		s.refused.Add(1)
		s.logf("egress refused host=%s port=%d reason=%s", host, port, reason)
		http.Error(w, "egress refused: "+reason, http.StatusForbidden)
		return
	}
	upstream, err := s.dial(r.Context(), ips, port)
	if err != nil {
		s.refused.Add(1)
		reason := "unreachable"
		if strings.Contains(err.Error(), "upstream proxy status") {
			reason = "upstream_refused"
		}
		s.logf("egress refused host=%s port=%d reason=%s", host, port, reason)
		http.Error(w, "egress refused: "+reason, http.StatusBadGateway)
		return
	}
	hj, ok := w.(http.Hijacker)
	if !ok {
		_ = upstream.Close()
		http.Error(w, "hijack unsupported", http.StatusInternalServerError)
		return
	}
	client, _, err := hj.Hijack()
	if err != nil {
		_ = upstream.Close()
		return
	}
	if _, err := client.Write([]byte("HTTP/1.1 200 Connection established\r\n\r\n")); err != nil {
		_ = client.Close()
		_ = upstream.Close()
		return
	}
	s.allowed.Add(1)
	s.logf("egress allowed host=%s port=%d", host, port)
	go relay(client, upstream)
}

func (s *Server) decide(ctx context.Context, host string, port int, grantID string) ([]net.IP, string) {
	if net.ParseIP(host) != nil {
		return nil, "ip_literal"
	}
	if grantID != "" {
		grant, ok, err := s.Store.Take(ctx, grantID)
		if err != nil || !ok {
			return nil, "grant_missing"
		}
		if !strings.EqualFold(grant.Host, host) || grant.Port != port {
			return nil, "grant_mismatch"
		}
		return publicIPs(grant.IPs)
	}
	rules, err := s.Store.Rules(ctx)
	if err != nil || !Match(rules, host, port) {
		return nil, "unknown_host"
	}
	found, err := s.Resolve(ctx, host)
	if err != nil || len(found) == 0 {
		return nil, "resolve_failed"
	}
	ips := make([]string, len(found))
	for i, ip := range found {
		ips[i] = ip.String()
	}
	return publicIPs(ips)
}

func publicIPs(raw []string) ([]net.IP, string) {
	out := make([]net.IP, 0, len(raw))
	for _, item := range raw {
		ip := net.ParseIP(strings.TrimSpace(item))
		if ip == nil {
			return nil, "bad_pin"
		}
		if Private(ip) {
			continue
		}
		out = append(out, ip)
	}
	if len(out) == 0 {
		return nil, "private_ip"
	}
	return out, ""
}

func (s *Server) dial(ctx context.Context, ips []net.IP, port int) (net.Conn, error) {
	dial := s.Dial
	if dial == nil {
		dial = (&net.Dialer{Timeout: dialTimeout}).DialContext
	}
	var last error
	for _, ip := range ips {
		target := net.JoinHostPort(ip.String(), strconv.Itoa(port))
		if s.Upstream != "" {
			conn, err := dial(ctx, "tcp", s.Upstream)
			if err != nil {
				last = err
				continue
			}
			tunneled, err := writeUpstreamConnect(conn, target)
			if err != nil {
				_ = conn.Close()
				last = err
				continue
			}
			return tunneled, nil
		}
		conn, err := dial(ctx, "tcp", target)
		if err != nil {
			last = err
			continue
		}
		return conn, nil
	}
	if last == nil {
		last = fmt.Errorf("no address dialed")
	}
	return nil, last
}

func writeUpstreamConnect(conn net.Conn, target string) (net.Conn, error) {
	_ = conn.SetDeadline(time.Now().Add(dialTimeout))
	if _, err := fmt.Fprintf(conn, "CONNECT %s HTTP/1.1\r\nHost: %s\r\n\r\n", target, target); err != nil {
		return nil, err
	}
	reader := bufio.NewReader(conn)
	resp, err := http.ReadResponse(reader, &http.Request{Method: http.MethodConnect})
	if err != nil {
		return nil, err
	}
	_ = resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("upstream proxy status %d", resp.StatusCode)
	}
	_ = conn.SetDeadline(time.Time{})
	if reader.Buffered() == 0 {
		return conn, nil
	}
	return &prefixConn{Conn: conn, rest: reader}, nil
}

type prefixConn struct {
	net.Conn
	rest *bufio.Reader
}

func (c *prefixConn) Read(buf []byte) (int, error) {
	if c.rest != nil && c.rest.Buffered() > 0 {
		return c.rest.Read(buf)
	}
	c.rest = nil
	return c.Conn.Read(buf)
}

func (s *Server) logf(format string, args ...any) {
	if s.Log != nil {
		s.Log.Printf(format, args...)
	}
}

func split(hostport string) (string, int, bool) {
	host, portStr, err := net.SplitHostPort(hostport)
	if err != nil {
		return "", 0, false
	}
	port, err := strconv.Atoi(portStr)
	if err != nil || port < 1 || port > 65535 || host == "" {
		return "", 0, false
	}
	return strings.ToLower(host), port, true
}

func bearer(header string) string {
	const prefix = "Bearer "
	if len(header) <= len(prefix) || !strings.EqualFold(header[:len(prefix)], prefix) {
		return ""
	}
	return strings.TrimSpace(header[len(prefix):])
}

func relay(client, upstream net.Conn) {
	defer func() {
		_ = client.Close()
		_ = upstream.Close()
	}()
	_ = client.SetDeadline(time.Now().Add(tunnelIdle))
	done := make(chan struct{}, 2)
	go func() { _, _ = io.Copy(upstream, client); done <- struct{}{} }()
	go func() { _, _ = io.Copy(client, upstream); done <- struct{}{} }()
	<-done
}

// DecodeGrant parses a grant body. An empty body is not a grant.
func DecodeGrant(body string) (Grant, error) {
	var grant Grant
	if err := json.Unmarshal([]byte(body), &grant); err != nil {
		return Grant{}, err
	}
	return grant, nil
}
