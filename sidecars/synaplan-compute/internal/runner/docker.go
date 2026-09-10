package runner

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"time"

	"github.com/docker/docker/api/types"
	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/api/types/filters"
	"github.com/docker/docker/client"
)

// ErrUnavailable is returned when dockerd is not reachable. Health still works.
var ErrUnavailable = errors.New("docker unavailable")

// Docker is the container factory. Create always goes through Hardened.
type Docker struct {
	cli *client.Client
}

// Connect builds a Docker SDK client from the environment. On failure the
// returned Docker is non-nil with a nil client so health can still serve.
func Connect() *Docker {
	cli, err := client.NewClientWithOpts(client.FromEnv, client.WithAPIVersionNegotiation())
	if err != nil {
		return &Docker{}
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	if _, err := cli.Ping(ctx); err != nil {
		_ = cli.Close()
		return &Docker{}
	}
	return &Docker{cli: cli}
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
	if err := ValidateHardened(cfg, hc); err != nil {
		return "", err
	}
	resp, err := d.cli.ContainerCreate(ctx, &cfg, &hc, nil, nil, "")
	if err != nil {
		return "", err
	}
	if err := d.cli.ContainerStart(ctx, resp.ID, types.ContainerStartOptions{}); err != nil {
		_ = d.cli.ContainerRemove(ctx, resp.ID, types.ContainerRemoveOptions{Force: true, RemoveVolumes: true})
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

// Logs copies stdout/stderr. Attach is best-effort.
func (d *Docker) Logs(ctx context.Context, id string) (io.ReadCloser, error) {
	if !d.Available() {
		return nil, ErrUnavailable
	}
	return d.cli.ContainerLogs(ctx, id, types.ContainerLogsOptions{
		ShowStdout: true,
		ShowStderr: true,
		Follow:     true,
	})
}

// SweepOrphans removes containers labelled synaplan.compute.run left by a crash.
func (d *Docker) SweepOrphans(ctx context.Context) error {
	if !d.Available() {
		return nil
	}
	args := filters.NewArgs(filters.Arg("label", runLabelKey+"="+runLabelValue))
	list, err := d.cli.ContainerList(ctx, types.ContainerListOptions{All: true, Filters: args})
	if err != nil {
		return err
	}
	for _, c := range list {
		_ = d.cli.ContainerRemove(ctx, c.ID, types.ContainerRemoveOptions{Force: true, RemoveVolumes: true})
	}
	return nil
}

// Close releases the SDK client.
func (d *Docker) Close() error {
	if d == nil || d.cli == nil {
		return nil
	}
	return d.cli.Close()
}

// EnsureScratch creates work and out directories under root.
func EnsureScratch(root string) (ScratchLayout, error) {
	layout := ScratchLayout{
		Root: root,
		Work: root + "/work",
		Out:  root + "/out",
	}
	for _, p := range []string{layout.Work, layout.Out} {
		if err := os.MkdirAll(p, 0o750); err != nil {
			return ScratchLayout{}, err
		}
	}
	return layout, nil
}
