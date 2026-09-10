package runner

import (
	"fmt"

	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/api/types/mount"
	"github.com/docker/go-units"
	"github.com/metadist/synaplan-compute/pkg/contract"
)

const (
	sandboxUser    = "65534:65534"
	workDir        = "/work"
	outDir         = "/out"
	workspaceDir   = "/workspace"
	tmpfsOpts      = "rw,noexec,nosuid,size=64m"
	noNewPrivs     = "no-new-privileges"
	runLabelKey    = "synaplan.compute.run"
	runLabelValue  = "1"
	networkNone    = "none"
	defaultNofile  = 1024
	tmpfsSizeBytes = 64 * 1024 * 1024
)

// Spec is the only input Hardened accepts. Docker.Create is the only caller
// that turns this into a container.
type Spec struct {
	ImageRef      string
	Cmd           []string
	WorkHost      string
	OutHost       string
	WorkspaceHost string
	Limits        contract.Limits
	Runtime       string
	NetworkMode   string
	Env           []string
	Labels        map[string]string
}

// Hardened returns the T1 HostConfig literal. Every container is created
// through this function; there is no other factory.
func Hardened(spec Spec) (container.Config, container.HostConfig) {
	initTrue := true
	pids := int64(spec.Limits.Pids)
	if pids <= 0 {
		pids = 128
	}
	mem := int64(spec.Limits.MemoryMb) * 1024 * 1024
	if mem <= 0 {
		mem = 512 * 1024 * 1024
	}
	cpu := spec.Limits.CPU
	if cpu <= 0 {
		cpu = 1
	}
	nano := int64(cpu * 1e9)
	fsize := int64(spec.Limits.OutputMb) * 1024 * 1024
	if fsize <= 0 {
		fsize = 50 * 1024 * 1024
	}

	network := spec.NetworkMode
	if network == "" {
		network = networkNone
	}

	labels := map[string]string{
		runLabelKey: runLabelValue,
	}
	for k, v := range spec.Labels {
		labels[k] = v
	}

	cfg := container.Config{
		Image:      spec.ImageRef,
		User:       sandboxUser,
		Cmd:        append([]string(nil), spec.Cmd...),
		WorkingDir: workDir,
		Env:        append([]string(nil), spec.Env...),
		Labels:     labels,
	}

	mounts := []mount.Mount{
		bind(spec.WorkHost, workDir, false),
		bind(spec.OutHost, outDir, false),
	}
	if spec.WorkspaceHost != "" {
		mounts = append(mounts, bind(spec.WorkspaceHost, workspaceDir, false))
	}

	hc := container.HostConfig{
		NetworkMode:    container.NetworkMode(network),
		ReadonlyRootfs: true,
		Tmpfs: map[string]string{
			"/tmp": tmpfsOpts,
		},
		CapDrop:     []string{"ALL"},
		SecurityOpt: []string{noNewPrivs},
		Privileged:  false,
		PidMode:     "",
		IpcMode:     "",
		UTSMode:     "",
		Runtime:     spec.Runtime,
		Init:        &initTrue,
		Resources: container.Resources{
			Memory:     mem,
			MemorySwap: mem,
			NanoCPUs:   nano,
			PidsLimit:  &pids,
			Ulimits: []*units.Ulimit{
				{Name: "nofile", Soft: defaultNofile, Hard: defaultNofile},
				{Name: "fsize", Soft: fsize, Hard: fsize},
			},
		},
		Mounts: mounts,
	}

	return cfg, hc
}

func bind(source, target string, ro bool) mount.Mount {
	return mount.Mount{
		Type:     mount.TypeBind,
		Source:   source,
		Target:   target,
		ReadOnly: ro,
	}
}

// ScratchLayout is the on-disk layout dockerd bind-mounts. Recorded in docs/SPIKE.md:
// /work and /out are bind-mounted from COMPUTE_SCRATCH_DIR/<runId>/{work,out}.
type ScratchLayout struct {
	Root string
	Work string
	Out  string
}

// ValidateHardened returns an error if hc/cfg violate the T1 baseline.
func ValidateHardened(cfg container.Config, hc container.HostConfig) error {
	if cfg.User != sandboxUser {
		return fmt.Errorf("user: got %q want %s", cfg.User, sandboxUser)
	}
	if !hc.ReadonlyRootfs {
		return fmt.Errorf("ReadonlyRootfs must be true")
	}
	if hc.Privileged {
		return fmt.Errorf("Privileged must never be set")
	}
	if hc.Init == nil || !*hc.Init {
		return fmt.Errorf("Init must be true")
	}
	if string(hc.NetworkMode) != networkNone && hc.NetworkMode.IsHost() {
		return fmt.Errorf("host network is forbidden")
	}
	if hc.PidMode.IsHost() || hc.IpcMode.IsHost() || hc.UTSMode.IsHost() {
		return fmt.Errorf("host pid/ipc/uts is forbidden")
	}
	if len(hc.CapDrop) == 0 || hc.CapDrop[0] != "ALL" {
		return fmt.Errorf("CapDrop must include ALL")
	}
	return nil
}
