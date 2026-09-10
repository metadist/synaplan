package runtime

import "testing"

func TestDetectGVisorWhenRunscPresent(t *testing.T) {
	t.Parallel()
	sel, err := Select("", "", Info{Runtimes: map[string]struct{}{"runc": {}, "runsc": {}}}, true)
	if err != nil {
		t.Fatal(err)
	}
	if sel.Tier != TierGVisor || sel.Runtime != RuntimeRunsc {
		t.Fatalf("%+v", sel)
	}
}

func TestDetectDockerWithoutRunsc(t *testing.T) {
	t.Parallel()
	sel, err := Select("", "", Info{Runtimes: map[string]struct{}{"runc": {}}}, true)
	if err != nil {
		t.Fatal(err)
	}
	if sel.Tier != TierDocker || sel.Runtime != "" {
		t.Fatalf("%+v", sel)
	}
}

func TestForcedTierFailsStartupWithoutRuntime(t *testing.T) {
	t.Parallel()
	_, err := Select(TierGVisor, "", Info{Runtimes: map[string]struct{}{"runc": {}}}, true)
	if err == nil {
		t.Fatal("expected failure")
	}
	_, err = Select(TierGVisor, "", Info{}, false)
	if err == nil {
		t.Fatal("gvisor without docker must fail")
	}
	sel, err := Select(TierDocker, "", Info{}, false)
	if err != nil {
		t.Fatal(err)
	}
	if sel.Tier != TierDocker {
		t.Fatal(sel.Tier)
	}
}

func TestForcedMicroVMNeedsNamedRuntime(t *testing.T) {
	t.Parallel()
	_, err := Select(TierMicroVM, "kata", Info{Runtimes: map[string]struct{}{"runc": {}}}, true)
	if err == nil {
		t.Fatal("expected failure")
	}
	sel, err := Select(TierMicroVM, "kata", Info{Runtimes: map[string]struct{}{"kata": {}}}, true)
	if err != nil {
		t.Fatal(err)
	}
	if sel.Tier != TierMicroVM || sel.Runtime != "kata" {
		t.Fatalf("%+v", sel)
	}
}
