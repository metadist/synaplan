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

func TestKeys(t *testing.T) {
	if SnapshotKey("web1") != "capacity:server:web1" {
		t.Fatal(SnapshotKey("web1"))
	}
	if AggregateKey("web1", "202610241900") != "capacity:agg:web1:202610241900" {
		t.Fatal(AggregateKey("web1", "202610241900"))
	}
}
