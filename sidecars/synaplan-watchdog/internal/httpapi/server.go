// Package httpapi serves :8098. Live, ready, and metrics only.
package httpapi

import (
	"context"
	"net"
	"net/http"
	"time"

	"github.com/metadist/synaplan-watchdog/internal/agent"
	"github.com/metadist/synaplan-watchdog/internal/metrics"
	"github.com/metadist/synaplan-watchdog/internal/store"
)

// Server is the local capacity endpoint.
type Server struct {
	http  *http.Server
	agent *agent.Agent
	store store.Store
}

// ListenAddr accepts only port 8098. Anything else is the relay, which is
// not part of this build.
func ListenAddr(raw string) (string, error) {
	if raw == "" {
		raw = ":8098"
	}
	_, port, err := net.SplitHostPort(raw)
	if err != nil || port != "8098" {
		return "", errOnly8098
	}
	return raw, nil
}

// New builds the server. It does not listen yet.
func New(addr string, ag *agent.Agent, st store.Store) *Server {
	s := &Server{agent: ag, store: st}
	mux := http.NewServeMux()
	mux.HandleFunc("GET /live", s.live)
	mux.HandleFunc("GET /ready", s.ready)
	mux.HandleFunc("GET /metrics", s.metrics)
	s.http = &http.Server{
		Addr:              addr,
		Handler:           mux,
		ReadHeaderTimeout: 2 * time.Second,
	}
	return s
}

// ListenAndServe starts the server.
func (s *Server) ListenAndServe() error {
	return s.http.ListenAndServe()
}

// Shutdown stops the listener.
func (s *Server) Shutdown(ctx context.Context) error {
	return s.http.Shutdown(ctx)
}

func (s *Server) live(w http.ResponseWriter, _ *http.Request) {
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write([]byte("ok\n"))
}

func (s *Server) ready(w http.ResponseWriter, r *http.Request) {
	code, body := s.readyNow(r.Context(), time.Now())
	w.WriteHeader(code)
	_, _ = w.Write([]byte(body))
}

func (s *Server) readyNow(ctx context.Context, now time.Time) (int, string) {
	view := s.agent.Current()
	if view.Snapshot.Draining {
		return http.StatusServiceUnavailable, "draining\n"
	}
	phpOK := !view.PHPReadyAt.IsZero() && now.Sub(view.PHPReadyAt) <= 10*time.Second && view.PHPLatency <= 2*time.Second
	if !phpOK {
		return http.StatusServiceUnavailable, "php\n"
	}
	if s.store == nil {
		return http.StatusServiceUnavailable, "redis\n"
	}
	pingCtx, cancel := context.WithTimeout(ctx, 2*time.Second)
	defer cancel()
	if err := s.store.Ping(pingCtx); err != nil {
		return http.StatusServiceUnavailable, "redis\n"
	}
	return http.StatusOK, "ok\n"
}

func (s *Server) metrics(w http.ResponseWriter, _ *http.Request) {
	view := s.agent.Current()
	w.Header().Set("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
	_, _ = w.Write([]byte(metrics.Render(view.MetricsBody, view.Snapshot)))
}
