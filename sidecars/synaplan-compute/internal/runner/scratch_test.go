package runner

import (
	"context"
	"os"
	"path/filepath"
	"testing"

	"github.com/metadist/synaplan-compute/internal/perm"
)

func TestEnsureScratchAndSweep(t *testing.T) {
	t.Parallel()
	root := t.TempDir()
	layout, err := EnsureScratch(filepath.Join(root, "run1"), perm.New(65534, 65534))
	if err != nil {
		t.Fatal(err)
	}
	for _, p := range []string{layout.Work, layout.Out} {
		st, err := os.Stat(p)
		if err != nil {
			t.Fatal(err)
		}
		if st.Mode().Perm()&0o070 != 0o070 && st.Mode().Perm()&0o007 != 0o007 {
			t.Fatalf("%s must be writable by the sandbox owner or group/world: %o", p, st.Mode().Perm())
		}
	}
	if _, err := EnsureScratch(filepath.Join(root, "run2"), nil); err != nil {
		t.Fatal(err)
	}
	if err := SweepScratch(root, map[string]bool{"run2": true}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(root, "run1")); !os.IsNotExist(err) {
		t.Fatal("run1 must be swept")
	}
	if _, err := os.Stat(filepath.Join(root, "run2")); err != nil {
		t.Fatal("run2 must be kept")
	}
	var d *Docker
	if err := (&Docker{}).SweepOrphans(context.Background(), root); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(root, "run2")); !os.IsNotExist(err) {
		t.Fatal("SweepOrphans must remove all scratch without docker")
	}
	if d.Available() {
		t.Fatal("nil docker is unavailable")
	}
	if _, err := (&Docker{}).Create(context.Background(), Spec{WorkHost: layout.Work, OutHost: layout.Out}); err != ErrUnavailable {
		t.Fatalf("create without docker: %v", err)
	}
}
