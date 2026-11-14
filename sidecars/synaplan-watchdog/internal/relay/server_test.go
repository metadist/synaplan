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
