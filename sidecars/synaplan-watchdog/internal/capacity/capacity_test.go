package capacity

import (
	"testing"
	"time"
)

func TestFoldPeakKeepsTheHighWaterMark(t *testing.T) {
	low := 0.1
	high := 0.8
	first := FoldPeak(Aggregate{}, "202610241900", Snapshot{
		Streams:        Pool{Total: 16, Busy: 2},
		API:            Pool{Total: 8, Busy: 1},
		MemoryRSSBytes: 100,
		CPUUsageRatio:  &low,
	})
	second := FoldPeak(first, "202610241900", Snapshot{
		Streams:        Pool{Total: 16, Busy: 14},
		API:            Pool{Total: 8, Busy: 1},
		MemoryRSSBytes: 50,
		CPUUsageRatio:  &high,
	})
	if second.Samples != 2 || second.StreamsBusyMax != 14 || second.MemoryUsedMax != 100 {
		t.Fatalf("aggregate = %+v", second)
	}
	if second.CPUUsageMax != high || second.StreamsTotal != 16 {
		t.Fatalf("aggregate = %+v", second)
	}
}

func TestRaisePeakKeepsTheFirstTimeOfTheHighWaterMark(t *testing.T) {
	first := RaisePeak(Peak{}, "20261024", Snapshot{UpdatedAt: "2026-10-24T11:40:00Z", Streams: Pool{Total: 24, Busy: 22}})
	same := RaisePeak(first, "20261024", Snapshot{UpdatedAt: "2026-10-24T11:41:00Z", Streams: Pool{Total: 24, Busy: 22}})
	lower := RaisePeak(same, "20261024", Snapshot{UpdatedAt: "2026-10-24T12:00:00Z", Streams: Pool{Total: 24, Busy: 3}})
	if lower.StreamsBusyMax != 22 || lower.At != "2026-10-24T11:40:00Z" || lower.StreamsTotal != 24 {
		t.Fatalf("peak = %+v", lower)
	}
	zero := RaisePeak(Peak{}, "20261025", Snapshot{UpdatedAt: "2026-10-25T00:00:05Z"})
	if zero.StreamsBusyMax != 0 || zero.At != "2026-10-25T00:00:05Z" {
		t.Fatalf("a new day starts at the first sample: %+v", zero)
	}
	nextDay := RaisePeak(lower, "20261025", Snapshot{UpdatedAt: "2026-10-25T00:00:05Z", Streams: Pool{Total: 24, Busy: 1}})
	if nextDay.StreamsBusyMax != 1 || nextDay.Day != "20261025" {
		t.Fatalf("a new day does not inherit yesterday: %+v", nextDay)
	}
}

func TestListedTTLKeepsAWorkerForADay(t *testing.T) {
	if ListedTTL("worker") != WorkerListedTTL || WorkerListedTTL != 24*time.Hour {
		t.Fatalf("worker window = %s", ListedTTL("worker"))
	}
	if ListedTTL("web") != SeenTTL || ListedTTL("scheduler") != SeenTTL || ListedTTL("") != SeenTTL {
		t.Fatal("only a worker stays for a day")
	}
}

func TestKeysCarryTheApplicationPrefix(t *testing.T) {
	prefix := KeyPrefix("prod")
	if prefix != "synaplan:prod:" || KeyPrefix("") != "synaplan:prod:" || KeyPrefix("dev") != "synaplan:dev:" {
		t.Fatal(prefix)
	}
	if SnapshotKey(prefix, "web1") != "synaplan:prod:capacity:server:web1" {
		t.Fatal(SnapshotKey(prefix, "web1"))
	}
	if AggregateKey(prefix, "web1", "202610241900") != "synaplan:prod:capacity:agg:web1:202610241900" {
		t.Fatal(AggregateKey(prefix, "web1", "202610241900"))
	}
	if SeenHashKey(prefix) != "synaplan:prod:capacity:seen" {
		t.Fatal(SeenHashKey(prefix))
	}
}
