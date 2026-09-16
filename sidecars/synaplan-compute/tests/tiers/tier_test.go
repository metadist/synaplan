package tiers

import (
	"os"
	"testing"

	rt "github.com/metadist/synaplan-compute/internal/runtime"
)

func TestSameRequestSelectsReportedTier(t *testing.T) {
	t.Parallel()
	docker, err := rt.Select("docker", "", rt.Info{}, false)
	if err != nil {
		t.Fatal(err)
	}
	if docker.Tier != rt.TierDocker {
		t.Fatal(docker.Tier)
	}
	if os.Getenv("COMPUTE_TIER") != "gvisor" {
		return
	}
	gvisor, err := rt.Select("gvisor", "", rt.Info{Runtimes: map[string]struct{}{"runsc": {}}}, true)
	if err != nil {
		t.Fatal(err)
	}
	if gvisor.Tier != rt.TierGVisor {
		t.Fatal(gvisor.Tier)
	}
}
