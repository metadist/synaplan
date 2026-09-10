package runner

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"time"

	"github.com/docker/docker/api/types"
	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/api/types/filters"
	"github.com/docker/docker/client"
	"github.com/docker/docker/pkg/stdcopy"
	"github.com/metadist/synaplan-compute/internal/perm"
)

// ErrUnavailable is returned when dockerd is not reachable. Health still works.
var ErrUnavailable = errors.New("docker unavailable")

// cleanupTimeout bounds kill/remove calls that must not inherit an expired
// run context.
const cleanupTimeout = 15 * time.Second

// State is the post-exit view the API maps to a reason.
type State struct {
	ExitCode  int
	OOMKilled bool
	Running   bool
}

// Runner is the container lifecycle the API drives. *Docker is the only
// production implementation; tests substitute a fake.
type Runner interface {
	Available() bool
	Create(ctx context.Context, spec Spec) (string, error)
	Wait(ctx context.Context, id string) (int, error)
	Inspect(ctx context.Context, id string) (State, error)
	Kill(ctx context.Context, id string) error
	Remove(ctx context.Context, id string) error
	Logs(ctx context.Context, id string, stdout, stderr io.Writer) error
}

// Docker is the container factory. Create always goes through Hardened.
type Docker struct {
	cli *client.Client
	// Policy bounds bind-mount sources; an empty Policy refuses every run.
	Policy Policy
}

// Connect builds a Docker SDK client from the environment. On failure the
// returned Docker is non-nil with a nil client so health can still serve.
func Connect(p Policy) *Docker {
	cli, err := client.NewClientWithOpts(client.FromEnv, client.WithAPIVersionNegotiation())
	if err != nil {
		return &Docker{Policy: p}
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	if _, err := cli.Ping(ctx); err != nil {
		_ = cli.Close()
		return &Docker{Policy: p}
	}
	return &Docker{cli: cli, Policy: p}
}

// Available reports whether ContainerCreate can be called.
func (d *Docker) Available() bool {
	return d != nil && d.cli != nil
}

// InfoRuntimes lists daemon runtime names (e.g. runc, runsc).
func (d *Docker) InfoRuntimes(ctx context.Context) (map[string]struct{}, error) {
	if !d.Available() {
		return nil, ErrUnavailable
	}
	info, err := d.cli.Info(ctx)
	if err != nil {
		return nil, err
	}
	out := make(map[string]struct{}, len(info.Runtimes))
	for name := range info.Runtimes {
		out[name] = struct{}{}
	}
	return out, nil
}

// Create starts a container using Hardened as the only HostConfig source.
func (d *Docker) Create(ctx context.Context, spec Spec) (string, error) {
	if !d.Available() {
		return "", ErrUnavailable
	}
	cfg, hc := Hardened(spec)
	if err := ValidateHardened(cfg, hc, d.Policy); err != nil {
		return "", err
	}
	resp, err := d.cli.ContainerCreate(ctx, &cfg, &hc, nil, nil, "")
	if err != nil {
		return "", err
	}
	if err := d.cli.ContainerStart(ctx, resp.ID, types.ContainerStartOptions{}); err != nil {
		cctx, cancel := cleanupContext()
		defer cancel()
		_ = d.cli.ContainerRemove(cctx, resp.ID, types.ContainerRemoveOptions{Force: true, RemoveVolumes: true})
		return "", err
	}
	return resp.ID, nil
}

// Wait blocks until the container exits or ctx is done.
func (d *Docker) Wait(ctx context.Context, id string) (int, error) {
	if !d.Available() {
		return -1, ErrUnavailable
	}
	statusCh, errCh := d.cli.ContainerWait(ctx, id, container.WaitConditionNotRunning)
	select {
	case err := <-errCh:
		return -1, err
	case st := <-statusCh:
		if st.Error != nil && st.Error.Message != "" {
			return int(st.StatusCode), fmt.Errorf("wait: %s", st.Error.Message)
		}
		return int(st.StatusCode), nil
	case <-ctx.Done():
		return -1, ctx.Err()
	}
}

// Inspect reports exit code and OOM state after Wait.
func (d *Docker) Inspect(ctx context.Context, id string) (State, error) {
	if !d.Available() {
		return State{}, ErrUnavailable
	}
	info, err := d.cli.ContainerInspect(ctx, id)
	if err != nil {
		return State{}, err
	}
	if info.State == nil {
		return State{}, fmt.Errorf("inspect %s: no state", id)
	}
	return State{ExitCode: info.State.ExitCode, OOMKilled: info.State.OOMKilled, Running: info.State.Running}, nil
}

// Kill sends SIGKILL.
func (d *Docker) Kill(ctx context.Context, id string) error {
	if !d.Available() {
		return ErrUnavailable
	}
	return d.cli.ContainerKill(ctx, id, "KILL")
}

// Remove force-deletes the container and anonymous volumes.
func (d *Docker) Remove(ctx context.Context, id string) error {
	if !d.Available() {
		return ErrUnavailable
	}
	return d.cli.ContainerRemove(ctx, id, types.ContainerRemoveOptions{Force: true, RemoveVolumes: true})
}

// Logs follows the container's multiplexed log stream and demultiplexes it
// into stdout and stderr until the container stops or ctx is done.
func (d *Docker) Logs(ctx context.Context, id string, stdout, stderr io.Writer) error {
	if !d.Available() {
		return ErrUnavailable
	}
	rc, err := d.cli.ContainerLogs(ctx, id, types.ContainerLogsOptions{
		ShowStdout: true,
		ShowStderr: true,
		Follow:     true,
	})
	if err != nil {
		return err
	}
	defer rc.Close()
	_, err = stdcopy.StdCopy(stdout, stderr, rc)
	return err
}

// SweepOrphans removes containers labelled synaplan.compute.run left by a
// crash and every scratch directory under scratchDir, since no run survives
// a restart. Scratch is swept even when dockerd is unreachable.
func (d *Docker) SweepOrphans(ctx context.Context, scratchDir string) error {
	var firstErr error
	if scratchDir != "" {
		if err := SweepScratch(scratchDir, nil); err != nil {
			firstErr = err
		}
	}
	if !d.Available() {
		return firstErr
	}
	args := filters.NewArgs(filters.Arg("label", runLabelKey+"="+runLabelValue))
	list, err := d.cli.ContainerList(ctx, types.ContainerListOptions{All: true, Filters: args})
	if err != nil {
		if firstErr == nil {
			firstErr = err
		}
		return firstErr
	}
	for _, c := range list {
		_ = d.cli.ContainerRemove(ctx, c.ID, types.ContainerRemoveOptions{Force: true, RemoveVolumes: true})
	}
	return firstErr
}

// SweepScratch removes every entry of scratchDir whose name is not in keep.
func SweepScratch(scratchDir string, keep map[string]bool) error {
	entries, err := os.ReadDir(scratchDir)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}
	var firstErr error
	for _, e := range entries {
		if keep[e.Name()] {
			continue
		}
		if err := os.RemoveAll(filepath.Join(scratchDir, e.Name())); err != nil && firstErr == nil {
			firstErr = err
		}
	}
	return firstErr
}

// Close releases the SDK client.
func (d *Docker) Close() error {
	if d == nil || d.cli == nil {
		return nil
	}
	return d.cli.Close()
}

// EnsureScratch creates work and out directories under root so the sandbox
// owner can read inputs and write outputs.
func EnsureScratch(root string, owner *perm.Owner) (ScratchLayout, error) {
	layout := ScratchLayout{
		Root: root,
		Work: filepath.Join(root, "work"),
		Out:  filepath.Join(root, "out"),
	}
	if err := os.MkdirAll(root, 0o750); err != nil {
		return ScratchLayout{}, err
	}
	for _, p := range []string{layout.Work, layout.Out} {
		if err := owner.MkdirAll(p); err != nil {
			return ScratchLayout{}, err
		}
	}
	return layout, nil
}

func cleanupContext() (context.Context, context.CancelFunc) {
	return context.WithTimeout(context.Background(), cleanupTimeout)
}
