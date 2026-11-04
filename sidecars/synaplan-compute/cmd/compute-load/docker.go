package main

import (
	"context"
	"time"

	"github.com/moby/moby/client"
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
	list, err := cli.ContainerList(ctx, client.ContainerListOptions{
		All:     true,
		Filters: make(client.Filters).Add("label", "synaplan.compute.run=1"),
	})
	if err != nil {
		return 0, false
	}
	return len(list.Items), true
}
