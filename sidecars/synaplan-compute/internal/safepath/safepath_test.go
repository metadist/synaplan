package safepath

import (
	"errors"
	"os"
	"path/filepath"
	"testing"
)

func TestOpenFileRefusesSymlinkedDirectory(t *testing.T) {
	t.Parallel()
	root := t.TempDir()
	outside := t.TempDir()
	if err := os.WriteFile(filepath.Join(outside, "secret.txt"), []byte("host"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outside, filepath.Join(root, "link")); err != nil {
		t.Fatal(err)
	}
	if _, _, err := OpenFile(root, "link/secret.txt"); !errors.Is(err, ErrSymlink) {
		t.Fatalf("directory symlink must be refused, got %v", err)
	}
	if _, err := ReadDir(root, "link"); !errors.Is(err, ErrSymlink) {
		t.Fatalf("listing through a directory symlink must be refused, got %v", err)
	}
}

func TestOpenFileRefusesSymlinkedFile(t *testing.T) {
	t.Parallel()
	root := t.TempDir()
	if err := os.Symlink("/etc/passwd", filepath.Join(root, "passwd")); err != nil {
		t.Fatal(err)
	}
	if _, _, err := OpenFile(root, "passwd"); !errors.Is(err, ErrSymlink) {
		t.Fatalf("file symlink must be refused, got %v", err)
	}
	entries, err := ReadDir(root, "")
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != 1 || !entries[0].Symlink {
		t.Fatalf("symlink entry must be flagged, got %+v", entries)
	}
}

func TestOpenFileRefusesSymlinkRoot(t *testing.T) {
	t.Parallel()
	outside := t.TempDir()
	if err := os.WriteFile(filepath.Join(outside, "secret.txt"), []byte("host"), 0o644); err != nil {
		t.Fatal(err)
	}
	parent := t.TempDir()
	root := filepath.Join(parent, "out")
	if err := os.Symlink(outside, root); err != nil {
		t.Fatal(err)
	}
	if _, _, err := OpenFile(root, "secret.txt"); !errors.Is(err, ErrSymlink) {
		t.Fatalf("symlink root must be refused, got %v", err)
	}
	if _, err := ReadDir(root, ""); !errors.Is(err, ErrSymlink) {
		t.Fatalf("listing a symlink root must be refused, got %v", err)
	}
	if _, _, err := Walk(root, "secret.txt"); !errors.Is(err, ErrSymlink) {
		t.Fatalf("walk through a symlink root must be refused, got %v", err)
	}
}

func TestOpenFileRegular(t *testing.T) {
	t.Parallel()
	root := t.TempDir()
	if err := os.MkdirAll(filepath.Join(root, "sub"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(root, "sub", "a.txt"), []byte("ok"), 0o644); err != nil {
		t.Fatal(err)
	}
	f, st, err := OpenFile(root, "sub/a.txt")
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	if st.Size() != 2 {
		t.Fatal(st.Size())
	}
	if _, _, err := OpenFile(root, "sub"); !errors.Is(err, ErrNotRegular) {
		t.Fatalf("directory open must be refused, got %v", err)
	}
	if _, _, err := Walk(root, "../x"); err == nil {
		t.Fatal("dot-dot must be refused")
	}
}
