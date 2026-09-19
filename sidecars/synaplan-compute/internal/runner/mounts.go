package runner

import (
	"fmt"
	"path"
	"strings"

	"github.com/docker/docker/api/types"
	"github.com/docker/docker/api/types/mount"
)

// DaemonBindSource translates a container-absolute bind source into the path
// dockerd must mount. Bind sources are resolved by the daemon on ITS
// filesystem, not in our mount namespace: passing our container path would
// mount an empty daemon-side directory and the run would silently see no
// files. Only host-path binds qualify — named volumes, tmpfs and anything
// else fail closed, since only a bind carries a daemon-usable source path.
//
// mounts comes from our own container inspect (Source = daemon path,
// Destination = our path).
func DaemonBindSource(mounts []types.MountPoint, source string) (string, error) {
	if !path.IsAbs(source) {
		return "", fmt.Errorf("bind source %q is not absolute", source)
	}
	source = path.Clean(source)
	best := -1
	bestLen := -1
	for i := range mounts {
		m := &mounts[i]
		if m.Type != mount.TypeBind || m.Destination == "" || m.Source == "" {
			continue
		}
		dest := path.Clean(m.Destination)
		if source != dest && !strings.HasPrefix(source, dest+"/") {
			continue
		}
		if len(dest) > bestLen {
			bestLen = len(dest)
			best = i
		}
	}
	if best < 0 {
		return "", fmt.Errorf("bind source %q is not backed by a host-path bind", source)
	}
	rel, err := underRel(mounts[best].Destination, source)
	if err != nil {
		return "", err
	}
	out := path.Join(mounts[best].Source, rel)
	if !path.IsAbs(out) {
		return "", fmt.Errorf("daemon bind source %q is not absolute", out)
	}
	return out, nil
}

func underRel(base, target string) (string, error) {
	base = path.Clean(base)
	target = path.Clean(target)
	if target == base {
		return ".", nil
	}
	rel := strings.TrimPrefix(target, base+"/")
	if rel == target || rel == ".." || strings.HasPrefix(rel, "../") {
		return "", fmt.Errorf("%q is not under %q", target, base)
	}
	return rel, nil
}
