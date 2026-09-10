package runner

import (
	"strings"
	"testing"

	"github.com/docker/docker/api/types/container"
	"github.com/metadist/synaplan-compute/pkg/contract"
)

func TestHostConfigHardening(t *testing.T) {
	t.Parallel()

	spec := Spec{
		ImageRef: "ghcr.io/metadist/synaplan-compute-python@sha256:" + strings.Repeat("a", 64),
		Cmd:      []string{"python", "main.py"},
		WorkHost: "/scratch/run/work",
		OutHost:  "/scratch/run/out",
		Limits: contract.Limits{
			TimeoutSec: 60,
			MemoryMb:   512,
			CPU:        1.0,
			Pids:       128,
			OutputMb:   50,
		},
	}
	cfg, hc := Hardened(spec)

	if got := string(hc.NetworkMode); got != "none" {
		t.Errorf("NetworkMode = %q, want none", got)
	}
	if !hc.ReadonlyRootfs {
		t.Error("ReadonlyRootfs must be true")
	}
	tmpfs, ok := hc.Tmpfs["/tmp"]
	if !ok {
		t.Fatal("Tmpfs must set /tmp")
	}
	for _, flag := range []string{"noexec", "nosuid"} {
		if !strings.Contains(tmpfs, flag) {
			t.Errorf("Tmpfs[/tmp] = %q, missing %s", tmpfs, flag)
		}
	}
	if !contains(hc.CapDrop, "ALL") {
		t.Errorf("CapDrop = %v, want ALL", hc.CapDrop)
	}
	if !contains(hc.SecurityOpt, "no-new-privileges") {
		t.Errorf("SecurityOpt = %v, want no-new-privileges", hc.SecurityOpt)
	}
	if cfg.User != "65534:65534" {
		t.Errorf("User = %q, want 65534:65534", cfg.User)
	}
	if hc.Init == nil || !*hc.Init {
		t.Error("Init must be true")
	}
	if hc.Privileged {
		t.Error("Privileged must never be true")
	}
	if hc.PidMode.IsHost() {
		t.Error("PidMode must not be host")
	}
	if hc.IpcMode.IsHost() {
		t.Error("IpcMode must not be host")
	}
	if hc.UTSMode.IsHost() {
		t.Error("UTSMode must not be host")
	}
	if len(hc.Devices) != 0 {
		t.Errorf("Devices must be empty, got %v", hc.Devices)
	}
	if hc.PidsLimit == nil || *hc.PidsLimit != 128 {
		t.Errorf("PidsLimit = %v, want 128", hc.PidsLimit)
	}
	wantMem := int64(512) * 1024 * 1024
	if hc.Memory != wantMem {
		t.Errorf("Memory = %d, want %d", hc.Memory, wantMem)
	}
	if hc.MemorySwap != wantMem {
		t.Errorf("MemorySwap = %d, want %d (no extra swap)", hc.MemorySwap, wantMem)
	}
	if hc.NanoCPUs != 1_000_000_000 {
		t.Errorf("NanoCPUs = %d, want 1e9", hc.NanoCPUs)
	}
	if cfg.WorkingDir != "/work" {
		t.Errorf("WorkingDir = %q, want /work", cfg.WorkingDir)
	}
	if !hasMount(hc, "/work") || !hasMount(hc, "/out") {
		t.Errorf("Mounts missing /work or /out: %+v", hc.Mounts)
	}
	for _, m := range hc.Mounts {
		if strings.Contains(m.Source, "docker.sock") || strings.Contains(m.Target, "docker.sock") {
			t.Fatalf("docker.sock must never be mounted: %+v", m)
		}
	}
	if contains(hc.SecurityOpt, "seccomp=unconfined") {
		t.Error("must not disable seccomp")
	}
	if len(cfg.Cmd) == 0 || cfg.Cmd[0] != "python" {
		t.Errorf("Cmd = %v, want argv [python ...]", cfg.Cmd)
	}
	joined := strings.Join(cfg.Cmd, " ")
	if strings.Contains(joined, "-c") && cfg.Cmd[0] == "sh" && len(cfg.Cmd) > 1 && cfg.Cmd[1] == "-c" {
		t.Error("Cmd must not be a shell string")
	}
	if err := ValidateHardened(cfg, hc); err != nil {
		t.Fatal(err)
	}

	// Empty egress keeps NetworkMode none; a non-empty proxy network is
	// still never host/container-shared.
	_, hcNone := Hardened(spec)
	if string(hcNone.NetworkMode) != "none" {
		t.Error("default NetworkMode must be none")
	}
}

func TestHardenedNeverPrivilegedEvenIfRuntimeSet(t *testing.T) {
	t.Parallel()
	spec := Spec{
		Cmd:      []string{"node", "main.js"},
		Runtime:  "runsc",
		Limits:   contract.Limits{MemoryMb: 256, CPU: 0.5, Pids: 64, OutputMb: 20},
		WorkHost: "/w",
		OutHost:  "/o",
	}
	cfg, hc := Hardened(spec)
	if hc.Privileged {
		t.Fatal("gVisor runtime must not imply privileged")
	}
	if hc.Runtime != "runsc" {
		t.Fatalf("Runtime = %q", hc.Runtime)
	}
	if cfg.User != "65534:65534" {
		t.Fatal(cfg.User)
	}
}

func contains(ss []string, want string) bool {
	for _, s := range ss {
		if s == want {
			return true
		}
	}
	return false
}

func hasMount(hc container.HostConfig, target string) bool {
	for _, m := range hc.Mounts {
		if m.Target == target {
			return true
		}
	}
	return false
}
