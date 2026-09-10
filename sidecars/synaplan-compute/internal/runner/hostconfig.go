package runner

import (
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/api/types/mount"
	"github.com/docker/go-units"
	"github.com/metadist/synaplan-compute/pkg/contract"
)

const (
	// DefaultSandboxUser is nobody:nogroup; COMPUTE_SANDBOX_UID/GID override it.
	DefaultSandboxUser = "65534:65534"
	workDir            = "/work"
	outDir             = "/out"
	workspaceDir       = "/workspace"
	tmpfsOpts          = "rw,noexec,nosuid,nodev,size=64m"
	noNewPrivs         = "no-new-privileges"
	runLabelKey        = "synaplan.compute.run"
	runLabelValue      = "1"
	networkNone        = "none"
	defaultNofile      = 1024
	dockerSock         = "docker.sock"
)

// Spec is the only input Hardened accepts. Docker.Create is the only caller
// that turns this into a container. There is no network field on purpose:
// every run is NetworkMode none until an egress proxy exists.
type Spec struct {
	ImageRef      string
	Cmd           []string
	WorkHost      string
	OutHost       string
	WorkspaceHost string
	Limits        contract.Limits
	Runtime       string
	User          string
	Labels        map[string]string
}

// Policy bounds where bind-mount sources may come from. Empty roots refuse
// every bind of that kind.
type Policy struct {
	ScratchRoot    string
	WorkspacesRoot string
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
	user := spec.User
	if user == "" {
		user = DefaultSandboxUser
	}

	labels := map[string]string{
		runLabelKey: runLabelValue,
	}
	for k, v := range spec.Labels {
		labels[k] = v
	}

	cfg := container.Config{
		Image:      spec.ImageRef,
		User:       user,
		Cmd:        append([]string(nil), spec.Cmd...),
		WorkingDir: workDir,
		Labels:     labels,
	}

	mounts := []mount.Mount{
		bind(spec.WorkHost, workDir),
		bind(spec.OutHost, outDir),
	}
	if spec.WorkspaceHost != "" {
		mounts = append(mounts, bind(spec.WorkspaceHost, workspaceDir))
	}

	hc := container.HostConfig{
		NetworkMode:    networkNone,
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

// bind is a recursive-off, read-write bind. The Docker API has no noexec /
// nosuid / nodev option for bind mounts (only tmpfs takes them), so /work,
// /out, and /workspace are executable by design; see THREAT_MODEL.md.
func bind(source, target string) mount.Mount {
	return mount.Mount{
		Type:        mount.TypeBind,
		Source:      source,
		Target:      target,
		ReadOnly:    false,
		BindOptions: &mount.BindOptions{NonRecursive: true},
	}
}

// ScratchLayout is the on-disk layout dockerd bind-mounts. Recorded in docs/SPIKE.md:
// /work and /out are bind-mounted from COMPUTE_SCRATCH_DIR/<runId>/{work,out}.
type ScratchLayout struct {
	Root string
	Work string
	Out  string
}

// ValidateHardened returns an error if hc/cfg violate the T1 baseline. It
// asserts every property TestHostConfigHardening checks so a future edit to
// Hardened cannot weaken the container without failing at Create.
func ValidateHardened(cfg container.Config, hc container.HostConfig, p Policy) error {
	if err := validateUser(cfg.User); err != nil {
		return err
	}
	if !hc.ReadonlyRootfs {
		return fmt.Errorf("ReadonlyRootfs must be true")
	}
	if len(cfg.Env) != 0 {
		return fmt.Errorf("Env must be empty (no proxy variables)")
	}
	if hc.Privileged {
		return fmt.Errorf("Privileged must never be set")
	}
	if hc.Init == nil || !*hc.Init {
		return fmt.Errorf("Init must be true")
	}
	if string(hc.NetworkMode) != networkNone {
		return fmt.Errorf("NetworkMode must be none, got %q", hc.NetworkMode)
	}
	if hc.PidMode != "" || hc.IpcMode != "" || hc.UTSMode != "" || hc.UsernsMode != "" || hc.CgroupnsMode != "" {
		return fmt.Errorf("pid/ipc/uts/userns/cgroupns modes must be default")
	}
	if !containsString(hc.CapDrop, "ALL") {
		return fmt.Errorf("CapDrop must include ALL")
	}
	if len(hc.CapAdd) != 0 {
		return fmt.Errorf("CapAdd must be empty")
	}
	if !containsString(hc.SecurityOpt, noNewPrivs) {
		return fmt.Errorf("SecurityOpt must include %s", noNewPrivs)
	}
	for _, opt := range hc.SecurityOpt {
		lower := strings.ToLower(opt)
		if strings.Contains(lower, "unconfined") || strings.HasPrefix(lower, "label=disable") {
			return fmt.Errorf("SecurityOpt %q disables a mandatory profile", opt)
		}
	}
	if hc.PidsLimit == nil || *hc.PidsLimit <= 0 {
		return fmt.Errorf("PidsLimit must be positive")
	}
	if hc.Memory <= 0 {
		return fmt.Errorf("Memory must be positive")
	}
	if hc.MemorySwap != hc.Memory {
		return fmt.Errorf("MemorySwap must equal Memory (no extra swap)")
	}
	if hc.NanoCPUs <= 0 && !(hc.CPUQuota > 0 && hc.CPUPeriod > 0) {
		return fmt.Errorf("NanoCPUs or CPUQuota/CPUPeriod must be set")
	}
	if len(hc.Tmpfs) == 0 {
		return fmt.Errorf("Tmpfs must mount /tmp")
	}
	for path, opts := range hc.Tmpfs {
		for _, flag := range []string{"noexec", "nosuid", "nodev"} {
			if !containsString(strings.Split(opts, ","), flag) {
				return fmt.Errorf("Tmpfs[%s]=%q is missing %s", path, opts, flag)
			}
		}
	}
	if len(hc.Devices) != 0 || len(hc.DeviceRequests) != 0 || len(hc.DeviceCgroupRules) != 0 {
		return fmt.Errorf("Devices must be empty")
	}
	if len(hc.Binds) != 0 || len(hc.VolumesFrom) != 0 {
		return fmt.Errorf("legacy Binds/VolumesFrom must be empty; use Mounts")
	}
	return validateMounts(hc.Mounts, p)
}

func validateUser(user string) error {
	uid, gid, ok := strings.Cut(user, ":")
	if !ok {
		return fmt.Errorf("user %q must be uid:gid", user)
	}
	u, err := strconv.Atoi(uid)
	if err != nil || u <= 0 {
		return fmt.Errorf("user %q must be a non-root numeric uid", user)
	}
	g, err := strconv.Atoi(gid)
	if err != nil || g <= 0 {
		return fmt.Errorf("user %q must be a non-root numeric gid", user)
	}
	return nil
}

func validateMounts(mounts []mount.Mount, p Policy) error {
	seen := map[string]bool{}
	for _, m := range mounts {
		if m.Type != mount.TypeBind {
			return fmt.Errorf("mount %s: only bind mounts are allowed", m.Target)
		}
		if strings.Contains(m.Source, dockerSock) || strings.Contains(m.Target, dockerSock) {
			return fmt.Errorf("docker.sock must never be mounted")
		}
		if m.BindOptions == nil || !m.BindOptions.NonRecursive {
			return fmt.Errorf("mount %s: bind must be non-recursive", m.Target)
		}
		if seen[m.Target] {
			return fmt.Errorf("mount %s: duplicate target", m.Target)
		}
		seen[m.Target] = true
		var root string
		switch m.Target {
		case workDir, outDir:
			root = p.ScratchRoot
		case workspaceDir:
			root = p.WorkspacesRoot
		default:
			return fmt.Errorf("mount %s: unexpected target", m.Target)
		}
		if err := under(root, m.Source); err != nil {
			return fmt.Errorf("mount %s: %w", m.Target, err)
		}
		st, err := os.Lstat(m.Source)
		if err != nil {
			return fmt.Errorf("mount %s: source %q: %w", m.Target, m.Source, err)
		}
		if st.Mode()&os.ModeSymlink != 0 {
			return fmt.Errorf("mount %s: source must not be a symlink", m.Target)
		}
		if !st.IsDir() {
			return fmt.Errorf("mount %s: source must be a directory", m.Target)
		}
	}
	if !seen[workDir] || !seen[outDir] {
		return fmt.Errorf("mounts must include %s and %s", workDir, outDir)
	}
	return nil
}

func under(root, source string) error {
	if root == "" {
		return fmt.Errorf("no root configured for source %q", source)
	}
	if !filepath.IsAbs(root) || !filepath.IsAbs(source) {
		return fmt.Errorf("root and source must be absolute (%q, %q)", root, source)
	}
	root = filepath.Clean(root)
	source = filepath.Clean(source)
	rel, err := filepath.Rel(root, source)
	if err != nil || rel == "." || rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
		return fmt.Errorf("source %q is not under %q", source, root)
	}
	return nil
}

func containsString(ss []string, want string) bool {
	for _, s := range ss {
		if s == want {
			return true
		}
	}
	return false
}
