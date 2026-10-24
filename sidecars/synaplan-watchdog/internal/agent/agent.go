// Package agent takes one capacity sample and stores it.
package agent

import (
	"context"
	"encoding/json"
	"log"
	"sync"
	"time"

	"github.com/metadist/synaplan-watchdog/internal/admin"
	"github.com/metadist/synaplan-watchdog/internal/capacity"
	"github.com/metadist/synaplan-watchdog/internal/host"
	"github.com/metadist/synaplan-watchdog/internal/store"
)

// AdminAPI is FrankenPHP's local admin API.
type AdminAPI interface {
	Metrics(ctx context.Context) (string, error)
	Threads(ctx context.Context) ([]byte, error)
}

// PHPProbe asks the server's own readiness URL.
type PHPProbe interface {
	Probe(ctx context.Context) (ok bool, latency time.Duration)
}

// Agent samples this server.
type Agent struct {
	ID      string
	Version string
	Admin   AdminAPI
	Probe   PHPProbe
	Read    func() host.Facts
	Store   store.Store

	mu        sync.Mutex
	prevCPU   cpuPoint
	phpReady  time.Time
	phpLate   time.Duration
	metrics   string
	snapshot  capacity.Snapshot
	hasSample bool
}

type cpuPoint struct {
	at   time.Time
	usec uint64
}

// View is the last sample the HTTP server may show.
type View struct {
	Snapshot    capacity.Snapshot
	MetricsBody string
	PHPReadyAt  time.Time
	PHPLatency  time.Duration
	HasSample   bool
}

// Tick reads, stores, and remembers one sample. Redis errors are logged
// and do not discard the sample.
//
// The PHP probe runs first, so its answer is stamped at now plus its own
// latency: the admin calls that follow may each wait out a timeout, and a
// readiness window must not be shortened by them.
func (a *Agent) Tick(ctx context.Context, now time.Time) capacity.Snapshot {
	snap := capacity.Snapshot{
		ServerID:        a.ID,
		Version:         a.Version,
		WatchdogVersion: capacity.Version,
		UpdatedAt:       now.UTC().Format(time.RFC3339),
		Runtime:         "host",
	}
	if a.Probe != nil {
		ok, latency := a.Probe.Probe(ctx)
		answeredAt := now.Add(latency)
		a.mu.Lock()
		if ok && latency <= probeBudget {
			a.phpReady = answeredAt
			a.phpLate = latency
		}
		readyAt := a.phpReady
		readyLate := a.phpLate
		a.mu.Unlock()
		snap.PHPReady = phpFresh(readyAt, readyLate, answeredAt)
	}
	if a.Read != nil {
		facts := a.Read()
		snap.Runtime = facts.Runtime
		snap.Hostname = facts.Hostname
		snap.Role = facts.Role
		snap.MemoryRSSBytes = facts.MemoryRSS
		snap.MemoryLimitBytes = facts.MemoryLimit
		snap.MemoryLimited = facts.MemoryLimited
		snap.CPUCores = facts.CPUCores
		snap.CPULimited = facts.CPULimited
		snap.Place = capacity.Place{
			Host:        facts.Host,
			Pod:         facts.Pod,
			Namespace:   facts.Namespace,
			Deployment:  facts.Deployment,
			Container:   facts.Container,
			CPURequest:  facts.CPURequest,
			MemoryLimit: facts.MemoryRequest,
		}
		snap.Logs = capacity.Logs{
			Collector:     facts.LogCollector,
			EventRingHost: facts.Hostname,
		}
		if facts.CPUUsageKnown {
			snap.CPUUsageRatio = a.cpuRatio(now, facts)
		}
	}
	if a.Admin != nil {
		body, err := a.Admin.Metrics(ctx)
		if err == nil {
			pools := admin.ParsePools(body)
			snap.Streams = capacity.Pool{Total: pools["streams"].Total, Busy: pools["streams"].Busy}
			snap.API = capacity.Pool{Total: pools["api"].Total, Busy: pools["api"].Busy}
			snap.AdminOK = true
		} else {
			// A stale body must not be served as a fresh scrape.
			body = ""
		}
		a.mu.Lock()
		a.metrics = body
		a.mu.Unlock()
		if raw, err := a.Admin.Threads(ctx); err == nil {
			if summary, err := admin.SummarizeThreads(raw); err == nil {
				snap.Threads = capacity.ThreadSummary{Count: summary.Count, Busy: summary.Busy}
			}
		}
	}

	a.remember(snap)
	a.publish(ctx, now, snap)
	return snap
}

const (
	probeBudget = 2 * time.Second
	readyWindow = 10 * time.Second
)

// phpFresh is the readiness rule: PHP answered within the budget, and that
// answer is not older than the window.
func phpFresh(readyAt time.Time, latency time.Duration, at time.Time) bool {
	return !readyAt.IsZero() && at.Sub(readyAt) <= readyWindow && latency <= probeBudget
}

// PHPFresh reports whether the last good probe still counts at the given time.
// A role without a probe has no PHP to wait for and is always fresh.
func (a *Agent) PHPFresh(at time.Time) bool {
	if a.Probe == nil {
		return true
	}
	a.mu.Lock()
	defer a.mu.Unlock()
	return phpFresh(a.phpReady, a.phpLate, at)
}

// ServesPHP is true when this agent watches a FrankenPHP process.
func (a *Agent) ServesPHP() bool {
	return a.Admin != nil
}

func (a *Agent) cpuRatio(now time.Time, facts host.Facts) *float64 {
	a.mu.Lock()
	defer a.mu.Unlock()
	var ratio *float64
	if !a.prevCPU.at.IsZero() && now.After(a.prevCPU.at) && facts.CPUUsageUsec >= a.prevCPU.usec && facts.CPUCores > 0 {
		deltaT := now.Sub(a.prevCPU.at).Seconds()
		deltaU := float64(facts.CPUUsageUsec - a.prevCPU.usec)
		if deltaT > 0 {
			value := (deltaU / 1e6) / deltaT / facts.CPUCores
			ratio = &value
		}
	}
	a.prevCPU = cpuPoint{at: now, usec: facts.CPUUsageUsec}
	return ratio
}

func (a *Agent) remember(snap capacity.Snapshot) {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.snapshot = snap
	a.hasSample = true
}

func (a *Agent) publish(ctx context.Context, now time.Time, snap capacity.Snapshot) {
	if a.Store == nil {
		return
	}
	body, err := json.Marshal(snap)
	if err != nil {
		log.Printf("watchdog: snapshot: %v", err)
		return
	}
	if err := a.Store.Put(ctx, capacity.SnapshotKey(a.ID), body, capacity.SnapshotTTL); err != nil {
		log.Printf("watchdog: snapshot store: %v", err)
	}
	minute := capacity.Minute(now)
	agg := capacity.Aggregate{}
	raw, err := a.Store.Get(ctx, capacity.AggregateKey(a.ID, minute))
	if err != nil {
		log.Printf("watchdog: aggregate read: %v", err)
		return
	}
	if len(raw) > 0 {
		if err := json.Unmarshal(raw, &agg); err != nil {
			log.Printf("watchdog: aggregate decode: %v", err)
			agg = capacity.Aggregate{}
		}
	}
	agg = capacity.FoldPeak(agg, minute, snap)
	encoded, err := json.Marshal(agg)
	if err != nil {
		return
	}
	if err := a.Store.Put(ctx, capacity.AggregateKey(a.ID, minute), encoded, capacity.AggregateTTL); err != nil {
		log.Printf("watchdog: aggregate store: %v", err)
	}
}

// Current returns the latest sample for /metrics and /ready.
func (a *Agent) Current() View {
	a.mu.Lock()
	defer a.mu.Unlock()
	return View{
		Snapshot:    a.snapshot,
		MetricsBody: a.metrics,
		PHPReadyAt:  a.phpReady,
		PHPLatency:  a.phpLate,
		HasSample:   a.hasSample,
	}
}
