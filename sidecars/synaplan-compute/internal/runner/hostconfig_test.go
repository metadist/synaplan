package runner

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/api/types/mount"
	"github.com/metadist/synaplan-compute/pkg/contract"
)

func liveLayout(t *testing.T) (Policy, Spec) {
	t.Helper()
	scratch := t.TempDir()
	workspaces := t.TempDir()
	work := filepath.Join(scratch, "run", "work")
	out := filepath.Join(scratch, "run", "out")
	ws := filepath.Join(workspaces, "01ARZ3NDEKTSV4RRFFQ69G5FAV", "data")
	for _, d := range []string{work, out, ws} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	spec := Spec{
		ImageRef: "ghcr.io/metadist/synaplan-compute-python@sha256:" + strings.Repeat("a", 64),
		Cmd:      []string{"python", "main.py"},
		WorkHost: work,
		OutHost:  out,
		Limits: contract.Limits{
			TimeoutSec: 60,
			MemoryMb:   512,
			CPU:        1.0,
			Pids:       128,
			OutputMb:   50,
		},
	}
	return Policy{ScratchRoot: scratch, WorkspacesRoot: workspaces}, spec
}

func TestHostConfigHardening(t *testing.T) {
	t.Parallel()

	policy, spec := liveLayout(t)
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
	for _, flag := range []string{"noexec", "nosuid", "nodev"} {
		if !contains(hc.Tmpfs["/tmp"], flag) {
			t.Errorf("Tmpfs[/tmp] = %q, missing %s", tmpfs, flag)
		}
	}
	if !containsString(hc.CapDrop, "ALL") {
		t.Errorf("CapDrop = %v, want ALL", hc.CapDrop)
	}
	if !containsString(hc.SecurityOpt, "no-new-privileges") {
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
		if m.BindOptions == nil || !m.BindOptions.NonRecursive {
			t.Errorf("bind %s must be non-recursive", m.Target)
		}
	}
	if containsString(hc.SecurityOpt, "seccomp=unconfined") {
		t.Error("must not disable seccomp")
	}
	if len(cfg.Env) != 0 {
		t.Errorf("Env must be empty (no proxy variables): %v", cfg.Env)
	}
	if len(cfg.Cmd) == 0 || cfg.Cmd[0] != "python" {
		t.Errorf("Cmd = %v, want argv [python ...]", cfg.Cmd)
	}
	if len(cfg.Cmd) > 1 && cfg.Cmd[0] == "sh" && cfg.Cmd[1] == "-c" {
		t.Error("Cmd must not be a shell string")
	}
	if err := ValidateHardened(cfg, hc, policy); err != nil {
		t.Fatal(err)
	}

	spec.WorkspaceHost = filepath.Join(policy.WorkspacesRoot, "01ARZ3NDEKTSV4RRFFQ69G5FAV", "data")
	cfg, hc = Hardened(spec)
	if !hasMount(hc, "/workspace") {
		t.Fatal("workspace mount missing")
	}
	if err := ValidateHardened(cfg, hc, policy); err != nil {
		t.Fatal(err)
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

func TestHardenedUsesConfiguredSandboxUser(t *testing.T) {
	t.Parallel()
	policy, spec := liveLayout(t)
	spec.User = "70000:70000"
	cfg, hc := Hardened(spec)
	if cfg.User != "70000:70000" {
		t.Fatal(cfg.User)
	}
	if err := ValidateHardened(cfg, hc, policy); err != nil {
		t.Fatal(err)
	}
}

func TestValidateHardenedRejectsWeakenedConfig(t *testing.T) {
	t.Parallel()
	policy, spec := liveLayout(t)
	one := int64(1)
	zero := int64(0)
	symlinkSrc := filepath.Join(policy.ScratchRoot, "run", "work-link")
	if err := os.Symlink(spec.WorkHost, symlinkSrc); err != nil {
		t.Fatal(err)
	}
	cases := []struct {
		name   string
		mutate func(cfg *container.Config, hc *container.HostConfig)
		policy Policy
	}{
		{"root user", func(c *container.Config, _ *container.HostConfig) { c.User = "0:0" }, policy},
		{"named user", func(c *container.Config, _ *container.HostConfig) { c.User = "root" }, policy},
		{"empty user", func(c *container.Config, _ *container.HostConfig) { c.User = "" }, policy},
		{"root gid", func(c *container.Config, _ *container.HostConfig) { c.User = "65534:0" }, policy},
		{"no capdrop", func(_ *container.Config, h *container.HostConfig) { h.CapDrop = nil }, policy},
		{"partial capdrop", func(_ *container.Config, h *container.HostConfig) { h.CapDrop = []string{"NET_RAW"} }, policy},
		{"capadd", func(_ *container.Config, h *container.HostConfig) { h.CapAdd = []string{"SYS_ADMIN"} }, policy},
		{"no no-new-privileges", func(_ *container.Config, h *container.HostConfig) { h.SecurityOpt = nil }, policy},
		{"seccomp unconfined", func(_ *container.Config, h *container.HostConfig) {
			h.SecurityOpt = append(h.SecurityOpt, "seccomp=unconfined")
		}, policy},
		{"apparmor unconfined", func(_ *container.Config, h *container.HostConfig) {
			h.SecurityOpt = append(h.SecurityOpt, "apparmor=unconfined")
		}, policy},
		{"writable rootfs", func(_ *container.Config, h *container.HostConfig) { h.ReadonlyRootfs = false }, policy},
		{"privileged", func(_ *container.Config, h *container.HostConfig) { h.Privileged = true }, policy},
		{"no init", func(_ *container.Config, h *container.HostConfig) { h.Init = nil }, policy},
		{"pids nil", func(_ *container.Config, h *container.HostConfig) { h.PidsLimit = nil }, policy},
		{"pids zero", func(_ *container.Config, h *container.HostConfig) { h.PidsLimit = &zero }, policy},
		{"no memory", func(_ *container.Config, h *container.HostConfig) { h.Memory = 0 }, policy},
		{"extra swap", func(_ *container.Config, h *container.HostConfig) { h.MemorySwap = h.Memory * 2 }, policy},
		{"no cpu", func(_ *container.Config, h *container.HostConfig) { h.NanoCPUs = 0; h.CPUQuota = 0 }, policy},
		{"host network", func(_ *container.Config, h *container.HostConfig) { h.NetworkMode = "host" }, policy},
		{"bridge network", func(_ *container.Config, h *container.HostConfig) { h.NetworkMode = "bridge" }, policy},
		{"container network", func(_ *container.Config, h *container.HostConfig) { h.NetworkMode = "container:abc" }, policy},
		{"custom network", func(_ *container.Config, h *container.HostConfig) { h.NetworkMode = "compute-egress" }, policy},
		{"host pid", func(_ *container.Config, h *container.HostConfig) { h.PidMode = "host" }, policy},
		{"host ipc", func(_ *container.Config, h *container.HostConfig) { h.IpcMode = "host" }, policy},
		{"host uts", func(_ *container.Config, h *container.HostConfig) { h.UTSMode = "host" }, policy},
		{"tmpfs exec", func(_ *container.Config, h *container.HostConfig) { h.Tmpfs["/tmp"] = "rw,nosuid,nodev" }, policy},
		{"tmpfs suid", func(_ *container.Config, h *container.HostConfig) { h.Tmpfs["/tmp"] = "rw,noexec,nodev" }, policy},
		{"tmpfs dev", func(_ *container.Config, h *container.HostConfig) { h.Tmpfs["/tmp"] = "rw,noexec,nosuid" }, policy},
		{"no tmpfs", func(_ *container.Config, h *container.HostConfig) { h.Tmpfs = nil }, policy},
		{"device", func(_ *container.Config, h *container.HostConfig) {
			h.Devices = []container.DeviceMapping{{PathOnHost: "/dev/kvm", PathInContainer: "/dev/kvm"}}
		}, policy},
		{"docker.sock", func(_ *container.Config, h *container.HostConfig) {
			h.Mounts = append(h.Mounts, mount.Mount{Type: mount.TypeBind, Source: "/var/run/docker.sock", Target: "/var/run/docker.sock"})
		}, policy},
		{"extra target", func(_ *container.Config, h *container.HostConfig) {
			h.Mounts = append(h.Mounts, mount.Mount{Type: mount.TypeBind, Source: "/scratch/run/etc", Target: "/etc"})
		}, policy},
		{"source outside scratch", func(_ *container.Config, h *container.HostConfig) { h.Mounts[0].Source = "/etc" }, policy},
		{"dotdot source", func(_ *container.Config, h *container.HostConfig) { h.Mounts[0].Source = "/scratch/../etc" }, policy},
		{"scratch root itself", func(_ *container.Config, h *container.HostConfig) { h.Mounts[0].Source = "/scratch" }, policy},
		{"relative source", func(_ *container.Config, h *container.HostConfig) { h.Mounts[0].Source = "scratch/run/work" }, policy},
		{"missing out", func(_ *container.Config, h *container.HostConfig) { h.Mounts = h.Mounts[:1] }, policy},
		{"volume type", func(_ *container.Config, h *container.HostConfig) { h.Mounts[0].Type = mount.TypeVolume }, policy},
		{"legacy binds", func(_ *container.Config, h *container.HostConfig) { h.Binds = []string{"/etc:/etc"} }, policy},
		{"workspace outside root", func(_ *container.Config, h *container.HostConfig) {
			h.Mounts = append(h.Mounts, mount.Mount{Type: mount.TypeBind, Source: "/home/user", Target: "/workspace"})
		}, policy},
		{"empty policy", func(_ *container.Config, _ *container.HostConfig) {}, Policy{}},
		{"pids one but no memory", func(_ *container.Config, h *container.HostConfig) { h.PidsLimit = &one; h.Memory = 0 }, policy},
		{"env proxy", func(c *container.Config, _ *container.HostConfig) { c.Env = []string{"HTTP_PROXY=http://x"} }, policy},
		{"recursive bind", func(_ *container.Config, h *container.HostConfig) {
			h.Mounts[0].BindOptions.NonRecursive = false
		}, policy},
		{"nil bind options", func(_ *container.Config, h *container.HostConfig) { h.Mounts[0].BindOptions = nil }, policy},
		{"symlink source", func(_ *container.Config, h *container.HostConfig) { h.Mounts[0].Source = symlinkSrc }, policy},
	}
	for _, tc := range cases {
		tc := tc
		t.Run(tc.name, func(t *testing.T) {
			t.Parallel()
			cfg, hc := Hardened(spec)
			tc.mutate(&cfg, &hc)
			if err := ValidateHardened(cfg, hc, tc.policy); err == nil {
				t.Fatalf("%s must be rejected", tc.name)
			}
		})
	}
}

func contains(csv, flag string) bool {
	return containsString(strings.Split(csv, ","), flag)
}

func hasMount(hc container.HostConfig, target string) bool {
	for _, m := range hc.Mounts {
		if m.Target == target {
			return true
		}
	}
	return false
}
