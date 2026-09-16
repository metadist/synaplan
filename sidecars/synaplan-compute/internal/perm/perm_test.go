package perm

import (
	"os"
	"path/filepath"
	"syscall"
	"testing"
)

func TestOwnerPreparesSandboxWritablePaths(t *testing.T) {
	t.Parallel()
	root := t.TempDir()
	o := New(65534, 65534)
	probeErr := o.Probe(root)
	dir := filepath.Join(root, "run", "work")
	if err := o.MkdirAll(dir); err != nil {
		t.Fatal(err)
	}
	file := filepath.Join(dir, "main.py")
	if err := o.WriteFile(file, []byte("print(1)")); err != nil {
		t.Fatal(err)
	}
	dst, err := os.Stat(dir)
	if err != nil {
		t.Fatal(err)
	}
	fst, err := os.Stat(file)
	if err != nil {
		t.Fatal(err)
	}
	if o.ChownEnabled() {
		if probeErr != nil {
			t.Fatalf("probe error with chown enabled: %v", probeErr)
		}
		if dst.Mode().Perm() != 0o770 || fst.Mode().Perm() != 0o660 {
			t.Fatalf("shared modes: dir %o file %o", dst.Mode().Perm(), fst.Mode().Perm())
		}
		sys, ok := fst.Sys().(*syscall.Stat_t)
		if !ok {
			t.Fatal("stat sys")
		}
		if int(sys.Gid) != os.Getgid() {
			t.Fatalf("group %d must be the service gid %d so listing stays readable", sys.Gid, os.Getgid())
		}
		return
	}
	if probeErr == nil {
		t.Fatal("probe must report why chown is disabled")
	}
	if dst.Mode().Perm() != 0o777 || fst.Mode().Perm() != 0o666 {
		t.Fatalf("fallback modes: dir %o file %o", dst.Mode().Perm(), fst.Mode().Perm())
	}
}

func TestNilOwnerKeepsPrivateModes(t *testing.T) {
	t.Parallel()
	var o *Owner
	root := t.TempDir()
	dir := filepath.Join(root, "d")
	if err := o.MkdirAll(dir); err != nil {
		t.Fatal(err)
	}
	if err := o.WriteFile(filepath.Join(dir, "f"), []byte("x")); err != nil {
		t.Fatal(err)
	}
	st, err := os.Stat(dir)
	if err != nil {
		t.Fatal(err)
	}
	if st.Mode().Perm()&0o007 != 0 {
		t.Fatalf("nil owner must not open the directory to others: %o", st.Mode().Perm())
	}
	if o.String() != "" || o.ChownEnabled() {
		t.Fatal("nil owner has no identity")
	}
}
