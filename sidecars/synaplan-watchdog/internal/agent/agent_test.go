package agent

import (
	"context"
	"encoding/json"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/metadist/synaplan-watchdog/internal/capacity"
	"github.com/metadist/synaplan-watchdog/internal/host"
	"github.com/metadist/synaplan-watchdog/internal/store"
)

type fakeAdmin struct {
	metrics string
	threads []byte
}

func (f fakeAdmin) Metrics(context.Context) (string, error) { return f.metrics, nil }
func (f fakeAdmin) Threads(context.Context) ([]byte, error) { return f.threads, nil }

type fakeProbe struct {
	ok      bool
	latency time.Duration
}

func (f fakeProbe) Probe(context.Context) (bool, time.Duration) {
	return f.ok, f.latency
}

func TestTickStoresSnapshotAndDropsThreadPaths(t *testing.T) {
	metrics, err := os.ReadFile("../../testdata/metrics.txt")
	if err != nil {
		t.Fatal(err)
	}
	threads, err := os.ReadFile("../../testdata/threads.json")
	if err != nil {
		t.Fatal(err)
	}
	memory := store.NewMemory()
	usage := uint64(1_000_000)
	agent := &Agent{
		ID:      "web1",
		Version: "dev",
		Prefix:  "synaplan:test:",
		Admin:   fakeAdmin{metrics: string(metrics), threads: threads},
		Probe:   fakeProbe{ok: true, latency: 20 * time.Millisecond},
		Read: func() host.Facts {
			return host.Facts{
				Runtime:       "compose",
				Hostname:      "79e4f90bc8c6",
				Role:          "web",
				Host:          "synweb100",
				Address:       "10.0.0.2",
				LogCollector:  "docker",
				MemoryRSS:     100,
				MemoryLimit:   200,
				MemoryLimited: true,
				CPUCores:      2,
				CPULimited:    true,
				CPUUsageUsec:  usage,
				CPUUsageKnown: true,
			}
		},
		Store: memory,
	}
	now := time.Date(2026, 10, 24, 18, 0, 0, 0, time.UTC)
	first := agent.Tick(context.Background(), now)
	if first.Streams.Total != 4 || first.Streams.Busy != 3 || first.API.Busy != 1 {
		t.Fatalf("pools = streams %+v api %+v", first.Streams, first.API)
	}
	if !first.PHPReady || first.Runtime != "compose" || first.CPUUsageRatio != nil {
		t.Fatalf("first sample = %+v", first)
	}
	if first.Hostname != "79e4f90bc8c6" || first.Role != "web" || first.Place.Host != "synweb100" || first.Place.Address != "10.0.0.2" {
		t.Fatalf("place = %+v hostname %q role %q", first.Place, first.Hostname, first.Role)
	}
	if first.Logs.Collector != "docker" || first.Logs.EventRingHost != "79e4f90bc8c6" {
		t.Fatalf("logs = %+v", first.Logs)
	}
	if first.Threads.Busy != 1 {
		t.Fatalf("threads = %+v", first.Threads)
	}

	usage = 3_000_000
	second := agent.Tick(context.Background(), now.Add(5*time.Second))
	if second.CPUUsageRatio == nil || *second.CPUUsageRatio < 0.1 {
		t.Fatalf("cpu ratio = %v", second.CPUUsageRatio)
	}

	raw, err := memory.Get(context.Background(), capacity.SnapshotKey("synaplan:test:", "web1"))
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(raw), "/api/v1/chats/42") {
		t.Fatalf("snapshot kept a request path: %s", raw)
	}
	if memory.TTL(capacity.SnapshotKey("synaplan:test:", "web1")) != capacity.SnapshotTTL {
		t.Fatalf("snapshot ttl = %s", memory.TTL(capacity.SnapshotKey("synaplan:test:", "web1")))
	}
	last, err := memory.Get(context.Background(), capacity.LastKey("synaplan:test:", "web1"))
	if err != nil || string(last) != string(raw) {
		t.Fatalf("last report differs from the snapshot: %v", err)
	}
	if memory.TTL(capacity.LastKey("synaplan:test:", "web1")) != capacity.SeenTTL {
		t.Fatalf("last report ttl = %s", memory.TTL(capacity.LastKey("synaplan:test:", "web1")))
	}
	if seen := memory.Field(capacity.SeenHashKey("synaplan:test:"), "web1"); seen != "2026-10-24T18:00:05Z" {
		t.Fatalf("seen = %q", seen)
	}
	if memory.HashTTL(capacity.SeenHashKey("synaplan:test:")) != capacity.SeenTTL {
		t.Fatalf("seen ttl = %s", memory.HashTTL(capacity.SeenHashKey("synaplan:test:")))
	}
	aggRaw, err := memory.Get(context.Background(), capacity.AggregateKey("synaplan:test:", "web1", "202610241800"))
	if err != nil {
		t.Fatal(err)
	}
	var agg capacity.Aggregate
	if err := json.Unmarshal(aggRaw, &agg); err != nil {
		t.Fatal(err)
	}
	if agg.Samples != 2 || agg.StreamsBusyMax != 3 {
		t.Fatalf("aggregate = %+v", agg)
	}
	peakRaw, err := memory.Get(context.Background(), capacity.PeakKey("synaplan:test:", "web1", "20261024"))
	if err != nil {
		t.Fatal(err)
	}
	var peak capacity.Peak
	if err := json.Unmarshal(peakRaw, &peak); err != nil {
		t.Fatal(err)
	}
	if peak.StreamsBusyMax != 3 || peak.StreamsTotal != 4 || peak.At != "2026-10-24T18:00:00Z" {
		t.Fatalf("peak = %+v", peak)
	}
	if memory.TTL(capacity.AggregateKey("synaplan:test:", "web1", "202610241800")) != capacity.AggregateTTL {
		t.Fatalf("aggregate ttl = %s", memory.TTL(capacity.AggregateKey("synaplan:test:", "web1", "202610241800")))
	}
}

type failingAdmin struct{}

func (failingAdmin) Metrics(context.Context) (string, error) {
	return "", context.DeadlineExceeded
}

func (failingAdmin) Threads(context.Context) ([]byte, error) {
	return nil, context.DeadlineExceeded
}

func TestWorkerLastReportLivesForADay(t *testing.T) {
	memory := store.NewMemory()
	agent := &Agent{
		ID:     "web1-worker",
		Prefix: "synaplan:test:",
		Read:   func() host.Facts { return host.Facts{Runtime: "compose", Role: "worker"} },
		Store:  memory,
	}
	agent.Tick(context.Background(), time.Date(2026, 10, 24, 18, 0, 0, 0, time.UTC))
	if memory.TTL(capacity.LastKey("synaplan:test:", "web1-worker")) != capacity.WorkerListedTTL {
		t.Fatalf("worker last report ttl = %s", memory.TTL(capacity.LastKey("synaplan:test:", "web1-worker")))
	}
	if memory.HashTTL(capacity.SeenHashKey("synaplan:test:")) != capacity.WorkerListedTTL {
		t.Fatalf("worker seen ttl = %s", memory.HashTTL(capacity.SeenHashKey("synaplan:test:")))
	}
}

func TestFailedAdminScrapeDropsTheStaleBody(t *testing.T) {
	good := fakeAdmin{metrics: "frankenphp_total_workers{worker=\"streams\"} 4\n", threads: []byte(`[]`)}
	agent := &Agent{
		ID:    "web1",
		Admin: good,
		Read:  func() host.Facts { return host.Facts{Runtime: "host"} },
		Store: store.NewMemory(),
	}
	now := time.Now()
	agent.Tick(context.Background(), now)
	if view := agent.Current(); view.MetricsBody == "" || !view.Snapshot.AdminOK {
		t.Fatalf("first scrape should be good: %+v", view)
	}

	agent.Admin = failingAdmin{}
	snap := agent.Tick(context.Background(), now.Add(5*time.Second))
	view := agent.Current()
	if snap.AdminOK || view.MetricsBody != "" {
		t.Fatalf("a failed scrape kept the old body: ok=%v body=%q", snap.AdminOK, view.MetricsBody)
	}
}

func TestProbeIsStampedWhenItAnswers(t *testing.T) {
	slowAdmin := fakeAdmin{metrics: "", threads: []byte(`[]`)}
	agent := &Agent{
		ID:    "web1",
		Admin: slowAdmin,
		Probe: fakeProbe{ok: true, latency: 1500 * time.Millisecond},
		Read:  func() host.Facts { return host.Facts{Runtime: "host"} },
		Store: store.NewMemory(),
	}
	now := time.Date(2026, 10, 24, 18, 0, 0, 0, time.UTC)
	agent.Tick(context.Background(), now)
	if !agent.PHPFresh(now.Add(11 * time.Second)) {
		t.Fatal("an answer stamped at now+1.5s must still be fresh 11s after the tick began")
	}
	if agent.PHPFresh(now.Add(12 * time.Second)) {
		t.Fatal("the window is ten seconds after the answer")
	}
}

func TestSlowPHPProbeDoesNotCountAsReady(t *testing.T) {
	agent := &Agent{
		ID:    "web1",
		Probe: fakeProbe{ok: true, latency: 3 * time.Second},
		Read:  func() host.Facts { return host.Facts{Runtime: "host"} },
		Store: store.NewMemory(),
	}
	snap := agent.Tick(context.Background(), time.Now())
	if snap.PHPReady {
		t.Fatal("a probe slower than 2s counted as ready")
	}
}
