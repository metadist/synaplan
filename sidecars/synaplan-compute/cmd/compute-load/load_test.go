package main

import (
	"testing"
	"time"
)

func TestPickRespectsWeights(t *testing.T) {
	t.Parallel()
	runs := []Template{{Name: "a", Weight: 60}, {Name: "b", Weight: 20}, {Name: "c", Weight: 10}, {Name: "d", Weight: 10}}
	counts := map[string]int{}
	for i := 0; i < 4000; i++ {
		counts[pick(runs, int64(i)).Name]++
		if got := pick(runs, int64(i)).Name; got != pick(runs, int64(i)).Name {
			t.Fatal("pick must be deterministic per index")
		}
	}
	for name, want := range map[string]int{"a": 2400, "b": 800, "c": 400, "d": 400} {
		if diff := counts[name] - want; diff < -200 || diff > 200 {
			t.Fatalf("%s: got %d, want ~%d", name, counts[name], want)
		}
	}
}

func TestPickFallsBackToFirst(t *testing.T) {
	t.Parallel()
	runs := []Template{{Name: "a"}, {Name: "b"}}
	if got := pick(runs, 42); got.Name != "a" {
		t.Fatalf("got %q", got.Name)
	}
}

func TestPct(t *testing.T) {
	t.Parallel()
	if got := pct(nil, 50); got != 0 {
		t.Fatalf("empty = %d", got)
	}
	values := []int64{10, 20, 30, 40, 50, 60, 70, 80, 90, 100}
	if got := pct(values, 50); got != 60 {
		t.Fatalf("p50 = %d", got)
	}
	if got := pct(values, 95); got != 100 {
		t.Fatalf("p95 = %d", got)
	}
}

func TestSummarizeFailsUnexpectedTerminal(t *testing.T) {
	t.Parallel()
	sum := summarize([]outcome{
		{name: "csv_chart", terminal: "succeeded", toDoneMs: 100},
		{name: "xlsx_recalc", terminal: "failed", reason: "program_error", toDoneMs: 100},
	}, 2*time.Second, 1000)
	if len(sum.failures) != 1 {
		t.Fatalf("failures = %v", sum.failures)
	}
}

func TestSummarizeAcceptsExpectedTimeout(t *testing.T) {
	t.Parallel()
	sum := summarize([]outcome{
		{name: "long_sleep", terminal: "failed", reason: "timeout", overtimeMs: 500, expectTimout: true},
	}, 2*time.Second, 1000)
	if len(sum.failures) != 0 {
		t.Fatalf("failures = %v", sum.failures)
	}
	if sum.expectedFails != 1 {
		t.Fatalf("expectedFails = %d", sum.expectedFails)
	}
}

func TestSummarizeFlagsSlowTimeoutKill(t *testing.T) {
	t.Parallel()
	sum := summarize([]outcome{
		{name: "long_sleep", terminal: "failed", reason: "timeout", overtimeMs: 5000, expectTimout: true},
	}, 2*time.Second, 1000)
	if len(sum.failures) != 1 {
		t.Fatalf("failures = %v", sum.failures)
	}
}

func TestSummarizeAllowsOnlyCapacityRefusals(t *testing.T) {
	t.Parallel()
	sum := summarize([]outcome{
		{name: "x", refused: "capacity_exceeded"},
		{name: "y", refused: "unauthorized"},
	}, 2*time.Second, 1000)
	if len(sum.failures) != 1 {
		t.Fatalf("failures = %v", sum.failures)
	}
}
