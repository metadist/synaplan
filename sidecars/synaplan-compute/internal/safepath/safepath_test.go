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

func TestCreateFileNeverFollowsOrReplaces(t *testing.T) {
	t.Parallel()
	root := t.TempDir()
	outside := t.TempDir()

	f, err := CreateFile(root, "new.txt", 0o640)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := f.WriteString("ok"); err != nil {
		t.Fatal(err)
	}
	_ = f.Close()
	if b, _ := os.ReadFile(filepath.Join(root, "new.txt")); string(b) != "ok" {
		t.Fatalf("content %q", b)
	}
	if _, err := CreateFile(root, "new.txt", 0o640); !errors.Is(err, os.ErrExist) {
		t.Fatalf("an existing file must not be replaced, got %v", err)
	}

	dangling := filepath.Join(outside, "created-through-link")
	if err := os.Symlink(dangling, filepath.Join(root, "dangling.txt")); err != nil {
		t.Fatal(err)
	}
	if _, err := CreateFile(root, "dangling.txt", 0o640); !errors.Is(err, os.ErrExist) {
		t.Fatalf("a dangling symlink must be refused, got %v", err)
	}
	if _, err := os.Lstat(dangling); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("nothing may be created at the link target, got %v", err)
	}

	victim := filepath.Join(outside, "victim")
	if err := os.WriteFile(victim, []byte("keep"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(victim, filepath.Join(root, "live.txt")); err != nil {
		t.Fatal(err)
	}
	if _, err := CreateFile(root, "live.txt", 0o640); !errors.Is(err, os.ErrExist) {
		t.Fatalf("a live symlink must be refused, got %v", err)
	}
	if b, _ := os.ReadFile(victim); string(b) != "keep" {
		t.Fatalf("link target was written: %q", b)
	}

	for _, name := range []string{"", ".", "..", "a/b"} {
		if _, err := CreateFile(root, name, 0o640); !errors.Is(err, os.ErrInvalid) {
			t.Fatalf("name %q must be refused, got %v", name, err)
		}
	}

	linkRoot := filepath.Join(outside, "link-root")
	if err := os.Symlink(root, linkRoot); err != nil {
		t.Fatal(err)
	}
	if _, err := CreateFile(linkRoot, "x.txt", 0o640); !errors.Is(err, ErrSymlink) {
		t.Fatalf("a symlink root must be refused, got %v", err)
	}
}
