package capacity

import "testing"

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
