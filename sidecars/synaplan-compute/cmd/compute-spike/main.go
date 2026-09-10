// compute-spike is the A0 throwaway prototype.
//
// The HostConfig literal lives in internal/runner.Hardened and is the only
// container factory carried into A1. Production serving is cmd/synaplan-compute.
package main

import (
	"encoding/json"
	"fmt"
	"os"

	"github.com/metadist/synaplan-compute/internal/runner"
	"github.com/metadist/synaplan-compute/pkg/contract"
)

func main() {
	fmt.Fprintln(os.Stderr, "compute-spike (A0): HostConfig factory is runner.Hardened; production binary is synaplan-compute")
	cfg, hc := runner.Hardened(runner.Spec{
		ImageRef: "ghcr.io/metadist/synaplan-compute-python@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		Cmd:      []string{"python", "main.py"},
		WorkHost: "/var/lib/synaplan-compute/scratch/demo/work",
		OutHost:  "/var/lib/synaplan-compute/scratch/demo/out",
		Limits: contract.Limits{
			TimeoutSec: 60,
			MemoryMb:   512,
			CPU:        1,
			Pids:       128,
			OutputMb:   50,
		},
	})
	enc := json.NewEncoder(os.Stdout)
	enc.SetIndent("", "  ")
	_ = enc.Encode(map[string]any{
		"note":           "A0 prototype dumps the hardened HostConfig; POST /v1/runs lives in synaplan-compute",
		"user":           cfg.User,
		"networkMode":    hc.NetworkMode,
		"readonlyRootfs": hc.ReadonlyRootfs,
		"tmpfs":          hc.Tmpfs,
		"capDrop":        hc.CapDrop,
		"securityOpt":    hc.SecurityOpt,
		"privileged":     hc.Privileged,
		"init":           hc.Init,
		"workingDir":     cfg.WorkingDir,
	})
}
