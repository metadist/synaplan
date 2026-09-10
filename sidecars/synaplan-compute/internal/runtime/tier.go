package runtime

import (
	"fmt"
	"strings"
)

const (
	TierDocker  = "docker"
	TierGVisor  = "gvisor"
	TierMicroVM = "microvm"

	RuntimeRunsc = "runsc"
)

// Info is the daemon runtime set (from client.Info().Runtimes).
type Info struct {
	Runtimes map[string]struct{}
}

func (i Info) has(name string) bool {
	if i.Runtimes == nil {
		return false
	}
	_, ok := i.Runtimes[name]
	return ok
}

// Selection is the active isolation tier and docker runtime name.
type Selection struct {
	Tier    string
	Runtime string
}

// Select picks the tier. COMPUTE_TIER forces a value and fails startup if
// the daemon cannot provide it — a hoster who configured T2 must never fall
// back to T1 silently.
func Select(forced, runtimeName string, info Info, dockerAvailable bool) (Selection, error) {
	forced = strings.ToLower(strings.TrimSpace(forced))
	switch forced {
	case "":
		if dockerAvailable && info.has(RuntimeRunsc) {
			return Selection{Tier: TierGVisor, Runtime: RuntimeRunsc}, nil
		}
		return Selection{Tier: TierDocker}, nil
	case TierDocker:
		return Selection{Tier: TierDocker}, nil
	case TierGVisor:
		if !dockerAvailable || !info.has(RuntimeRunsc) {
			return Selection{}, fmt.Errorf("COMPUTE_TIER=gvisor requires the runsc runtime")
		}
		return Selection{Tier: TierGVisor, Runtime: RuntimeRunsc}, nil
	case TierMicroVM:
		name := runtimeName
		if name == "" {
			name = "kata"
		}
		if !dockerAvailable || !info.has(name) {
			return Selection{}, fmt.Errorf("COMPUTE_TIER=microvm requires runtime %q", name)
		}
		return Selection{Tier: TierMicroVM, Runtime: name}, nil
	default:
		return Selection{}, fmt.Errorf("unknown COMPUTE_TIER %q", forced)
	}
}
