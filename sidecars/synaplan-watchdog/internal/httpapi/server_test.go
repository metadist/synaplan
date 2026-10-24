package httpapi

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/metadist/synaplan-watchdog/internal/agent"
	"github.com/metadist/synaplan-watchdog/internal/capacity"
	"github.com/metadist/synaplan-watchdog/internal/host"
	"github.com/metadist/synaplan-watchdog/internal/store"
)

func TestListenAddrRejectsTheRelayPort(t *testing.T) {
	addr, err := ListenAddr("")
	if err != nil || addr != ":8098" {
		t.Fatalf("default = %s %v", addr, err)
	}
	if _, err := ListenAddr("127.0.0.1:8097"); err == nil {
		t.Fatal("8097 was accepted")
	}
	if _, err := ListenAddr(":8098"); err != nil {
		t.Fatal(err)
	}
}

func TestReadyNeedsARecentPHPProbeAndRedis(t *testing.T) {
	memory := store.NewMemory()
	ag := &agent.Agent{
		ID:    "web1",
		Probe: readyProbe{ok: true, latency: 10 * time.Millisecond},
		Read:  func() host.Facts { return host.Facts{Runtime: "compose"} },
		Admin: staticAdmin{},
		Store: memory,
	}
	now := time.Now()
	ag.Tick(context.Background(), now)
	server := New(":8098", ag, memory)

	code, body := server.readyNow(context.Background(), now)
	if code != http.StatusOK || body != "ok\n" {
		t.Fatalf("ready = %d %s", code, body)
	}

	code, body = server.readyNow(context.Background(), now.Add(11*time.Second))
	if code != http.StatusServiceUnavailable || body != "php\n" {
		t.Fatalf("stale = %d %s", code, body)
	}

	ag.Tick(context.Background(), now)
	memory.SetPing(errors.New("down"))
	code, body = server.readyNow(context.Background(), now)
	if code != http.StatusServiceUnavailable || body != "redis\n" {
		t.Fatalf("redis = %d %s", code, body)
	}
}

func TestLiveAndMetrics(t *testing.T) {
	memory := store.NewMemory()
	ag := &agent.Agent{
		ID:      "web1",
		Version: "5.6.0",
		Probe:   readyProbe{ok: true, latency: time.Millisecond},
		Read:    func() host.Facts { return host.Facts{Runtime: "host"} },
		Admin:   staticAdmin{metrics: "frankenphp_total_workers{worker=\"streams\"} 16\nfrankenphp_busy_workers{worker=\"streams\"} 4\n"},
		Store:   memory,
	}
	ag.Tick(context.Background(), time.Now())
	server := New(":8098", ag, memory)

	live := httptest.NewRecorder()
	server.live(live, httptest.NewRequest(http.MethodGet, "/live", nil))
	if live.Code != http.StatusOK {
		t.Fatalf("live = %d", live.Code)
	}

	rec := httptest.NewRecorder()
	server.metrics(rec, httptest.NewRequest(http.MethodGet, "/metrics", nil))
	body, _ := io.ReadAll(rec.Body)
	text := string(body)
	if rec.Code != http.StatusOK {
		t.Fatalf("metrics = %d", rec.Code)
	}
	if !strings.Contains(text, "frankenphp_total_workers") || !strings.Contains(text, "synaplan_server_info") {
		t.Fatalf("metrics =\n%s", text)
	}
	if !strings.Contains(text, `version="5.6.0"`) {
		t.Fatalf("version missing:\n%s", text)
	}
	_ = capacity.Version
}

func TestMetricsIs503WithoutAFrankenPHPSample(t *testing.T) {
	memory := store.NewMemory()
	ag := &agent.Agent{
		ID:    "web1",
		Read:  func() host.Facts { return host.Facts{Runtime: "host"} },
		Admin: brokenAdmin{},
		Store: memory,
	}
	server := New(":8098", ag, memory)

	before := httptest.NewRecorder()
	server.metrics(before, httptest.NewRequest(http.MethodGet, "/metrics", nil))
	if before.Code != http.StatusServiceUnavailable {
		t.Fatalf("no sample yet, got %d", before.Code)
	}

	ag.Tick(context.Background(), time.Now())
	after := httptest.NewRecorder()
	server.metrics(after, httptest.NewRequest(http.MethodGet, "/metrics", nil))
	if after.Code != http.StatusServiceUnavailable {
		t.Fatalf("failed admin scrape, got %d", after.Code)
	}
}

type brokenAdmin struct{}

func (brokenAdmin) Metrics(context.Context) (string, error) { return "", errors.New("refused") }
func (brokenAdmin) Threads(context.Context) ([]byte, error) { return nil, errors.New("refused") }

type readyProbe struct {
	ok      bool
	latency time.Duration
}

func (p readyProbe) Probe(context.Context) (bool, time.Duration) {
	return p.ok, p.latency
}

type staticAdmin struct {
	metrics string
}

func (s staticAdmin) Metrics(context.Context) (string, error) { return s.metrics, nil }
func (s staticAdmin) Threads(context.Context) ([]byte, error) {
	return []byte(`[]`), nil
}
