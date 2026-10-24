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
		Admin:   fakeAdmin{metrics: string(metrics), threads: threads},
		Probe:   fakeProbe{ok: true, latency: 20 * time.Millisecond},
		Read: func() host.Facts {
			return host.Facts{
				Runtime:       "compose",
				Hostname:      "79e4f90bc8c6",
				Role:          "web",
				Host:          "synweb100",
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
	if first.Hostname != "79e4f90bc8c6" || first.Role != "web" || first.Place.Host != "synweb100" {
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

	raw, err := memory.Get(context.Background(), capacity.SnapshotKey("web1"))
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(raw), "/api/v1/chats/42") {
		t.Fatalf("snapshot kept a request path: %s", raw)
	}
	if memory.TTL(capacity.SnapshotKey("web1")) != capacity.SnapshotTTL {
		t.Fatalf("snapshot ttl = %s", memory.TTL(capacity.SnapshotKey("web1")))
	}
	aggRaw, err := memory.Get(context.Background(), capacity.AggregateKey("web1", "202610241800"))
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
	if memory.TTL(capacity.AggregateKey("web1", "202610241800")) != capacity.AggregateTTL {
		t.Fatalf("aggregate ttl = %s", memory.TTL(capacity.AggregateKey("web1", "202610241800")))
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
