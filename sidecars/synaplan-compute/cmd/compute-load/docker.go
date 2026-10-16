package main

import (
	"context"
	"time"

	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/api/types/filters"
	"github.com/docker/docker/client"
)

// dockerRunContainerCount counts containers carrying the run label,
// including stopped ones (a finished run must be removed, not left).
// The second return is false when docker is unreachable.
func dockerRunContainerCount() (int, bool) {
	cli, err := client.NewClientWithOpts(client.FromEnv, client.WithAPIVersionNegotiation())
	if err != nil {
		return 0, false
	}
	defer cli.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	list, err := cli.ContainerList(ctx, container.ListOptions{
		All:     true,
		Filters: filters.NewArgs(filters.Arg("label", "synaplan.compute.run=1")),
	})
	if err != nil {
		return 0, false
	}
	return len(list), true
}
