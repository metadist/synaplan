package relay

import (
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestDefaultUpstreamIsTheLoopbackPHPListener(t *testing.T) {
	cfg := ConfigFrom(func(string) string { return "" }, "synaplan:dev:")
	if cfg.Upstream != "http://127.0.0.1:9080" {
		t.Fatalf("upstream = %s", cfg.Upstream)
	}
}

func TestUpstreamHeaderWaitIsNotCapped(t *testing.T) {
	if upstreamTransport().ResponseHeaderTimeout != 0 {
		t.Fatal("response header timeout is set")
	}
}

func TestPlainTextPoolRefusalBecomesBusyJSON(t *testing.T) {
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		w.Header().Set("X-From-Php", "yes")
		w.WriteHeader(http.StatusServiceUnavailable)
		_, _ = io.WriteString(w, "no worker free")
	}))
	defer upstream.Close()

	server := New(Config{Upstream: upstream.URL, Slots: 4})
	rec := httptest.NewRecorder()
	server.ServeHTTP(rec, httptest.NewRequest(http.MethodPost, "/v1/chat/completions", nil))

	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("status = %d", rec.Code)
	}
	if rec.Header().Get("Retry-After") != "5" || rec.Header().Get("X-From-Php") != "yes" {
		t.Fatalf("headers = %v", rec.Header())
	}
	if !strings.Contains(rec.Body.String(), `"code":"server_busy"`) {
		t.Fatalf("body = %s", rec.Body.String())
	}
}

func TestApplication503PassesThrough(t *testing.T) {
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusServiceUnavailable)
		_, _ = io.WriteString(w, `{"error":"signup_paused"}`)
	}))
	defer upstream.Close()

	server := New(Config{Upstream: upstream.URL, Slots: 4})
	rec := httptest.NewRecorder()
	server.ServeHTTP(rec, httptest.NewRequest(http.MethodPost, "/api/v1/messages/stream", nil))

	if rec.Body.String() != `{"error":"signup_paused"}` {
		t.Fatalf("body = %s", rec.Body.String())
	}
}

func TestCopyFlushesAnEventStreamAndKeepsUpstreamHeaders(t *testing.T) {
	rec := httptest.NewRecorder()
	resp := &http.Response{
		StatusCode: http.StatusOK,
		Header: http.Header{
			"Content-Type": []string{"text/event-stream"},
			"X-From-Php":   []string{"yes"},
		},
		Body: io.NopCloser(strings.NewReader("data: one\n\n")),
	}
	copyUpstream(rec, resp)
	if rec.Header().Get("X-From-Php") != "yes" {
		t.Fatal("upstream header was dropped")
	}
	if rec.Body.String() != "data: one\n\n" {
		t.Fatalf("body = %q", rec.Body.String())
	}
	if !rec.Flushed {
		t.Fatal("event stream was not flushed")
	}
}
