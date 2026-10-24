package scrape

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestAdminAndPHPProbe(t *testing.T) {
	adminSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/metrics":
			_, _ = w.Write([]byte("frankenphp_total_workers{worker=\"streams\"} 2\n"))
		case "/frankenphp/threads":
			_, _ = w.Write([]byte(`[{"busy":false}]`))
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(adminSrv.Close)
	admin := Admin{BaseURL: adminSrv.URL, Client: adminSrv.Client()}
	body, err := admin.Metrics(context.Background())
	if err != nil || body == "" {
		t.Fatal(err)
	}
	threads, err := admin.Threads(context.Background())
	if err != nil || len(threads) == 0 {
		t.Fatal(err)
	}

	phpSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	t.Cleanup(phpSrv.Close)
	ok, latency := (PHP{URL: phpSrv.URL, Client: phpSrv.Client()}).Probe(context.Background())
	if !ok || latency > 2*time.Second {
		t.Fatalf("probe ok=%v latency=%s", ok, latency)
	}
}
