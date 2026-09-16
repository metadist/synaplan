package api

import (
	"context"
	"errors"
	"fmt"
	"io"
	"sync"
	"sync/atomic"
	"time"

	"github.com/metadist/synaplan-compute/internal/runner"
)

// fakeRunner is a hermetic runner.Runner: no dockerd, deterministic exits,
// and an in-flight gauge so tests can prove the concurrency bound.
type fakeRunner struct {
	inflight    atomic.Int32
	maxInflight atomic.Int32
	created     atomic.Int32
	removed     atomic.Int32

	exitCode  int
	oom       bool
	stdout    string
	stderr    string
	waitDelay time.Duration
	onCreate  func(spec runner.Spec)

	mu         sync.Mutex
	containers map[string]*fakeContainer
	specs      []runner.Spec
}

type fakeContainer struct {
	killed    chan struct{}
	killOnce  sync.Once
	exited    chan struct{}
	exitOnce  sync.Once
	removedAt time.Time
}

type runnerSpec = runner.Spec

func newFakeRunner() *fakeRunner {
	return &fakeRunner{containers: map[string]*fakeContainer{}}
}

func contextWithTimeout(d time.Duration) (context.Context, context.CancelFunc) {
	return context.WithTimeout(context.Background(), d)
}

func (f *fakeRunner) Available() bool { return true }

func (f *fakeRunner) Create(ctx context.Context, spec runner.Spec) (string, error) {
	if err := ctx.Err(); err != nil {
		return "", err
	}
	n := f.inflight.Add(1)
	for {
		cur := f.maxInflight.Load()
		if n <= cur || f.maxInflight.CompareAndSwap(cur, n) {
			break
		}
	}
	id := fmt.Sprintf("fake-%d", f.created.Add(1))
	f.mu.Lock()
	f.containers[id] = &fakeContainer{killed: make(chan struct{}), exited: make(chan struct{})}
	f.specs = append(f.specs, spec)
	f.mu.Unlock()
	if f.onCreate != nil {
		f.onCreate(spec)
	}
	return id, nil
}

func (f *fakeRunner) get(id string) (*fakeContainer, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	c, ok := f.containers[id]
	if !ok {
		return nil, errors.New("no such container")
	}
	return c, nil
}

func (f *fakeRunner) Wait(ctx context.Context, id string) (int, error) {
	c, err := f.get(id)
	if err != nil {
		return -1, err
	}
	defer c.exitOnce.Do(func() { close(c.exited) })
	timer := time.NewTimer(f.waitDelay)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return -1, ctx.Err()
	case <-c.killed:
		return 137, nil
	case <-timer.C:
		return f.exitCode, nil
	}
}

func (f *fakeRunner) Inspect(_ context.Context, id string) (runner.State, error) {
	if _, err := f.get(id); err != nil {
		return runner.State{}, err
	}
	return runner.State{ExitCode: f.exitCode, OOMKilled: f.oom}, nil
}

func (f *fakeRunner) Kill(_ context.Context, id string) error {
	c, err := f.get(id)
	if err != nil {
		return err
	}
	c.killOnce.Do(func() { close(c.killed) })
	return nil
}

func (f *fakeRunner) Remove(_ context.Context, id string) error {
	c, err := f.get(id)
	if err != nil {
		return err
	}
	f.mu.Lock()
	first := c.removedAt.IsZero()
	c.removedAt = time.Now()
	f.mu.Unlock()
	if first {
		f.inflight.Add(-1)
		f.removed.Add(1)
	}
	return nil
}

func (f *fakeRunner) Logs(ctx context.Context, id string, stdout, stderr io.Writer) error {
	c, err := f.get(id)
	if err != nil {
		return err
	}
	if f.stdout != "" {
		_, _ = io.WriteString(stdout, f.stdout)
	}
	if f.stderr != "" {
		_, _ = io.WriteString(stderr, f.stderr)
	}
	select {
	case <-ctx.Done():
	case <-c.exited:
	case <-c.killed:
	}
	return nil
}
