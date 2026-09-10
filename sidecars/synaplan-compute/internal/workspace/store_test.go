package workspace

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestCreateOpaqueULIDAndOwner(t *testing.T) {
	t.Parallel()
	s := testStore(t)
	meta, err := s.Create("user:123", 64)
	if err != nil {
		t.Fatal(err)
	}
	if len(meta.ID) != 26 {
		t.Fatalf("id length %d, want ULID 26", len(meta.ID))
	}
	if strings.Contains(meta.ID, "/") || strings.Contains(meta.ID, s.root) {
		t.Fatal("id must be opaque")
	}
	if _, err := s.AssertOwner(meta.ID, "user:123"); err != nil {
		t.Fatal(err)
	}
	if _, err := s.AssertOwner(meta.ID, "user:999"); err != ErrNotOwned {
		t.Fatalf("got %v", err)
	}
	if _, err := s.AssertOwner("does-not-exist", "user:123"); err != ErrNotFound {
		t.Fatalf("got %v", err)
	}
}

func TestWorkspaceListNeverReturnsPaths(t *testing.T) {
	t.Parallel()
	s := testStore(t)
	meta, err := s.Create("user:1", 16)
	if err != nil {
		t.Fatal(err)
	}
	host := s.HostPath(meta.ID)
	if err := os.WriteFile(filepath.Join(host, "note.txt"), []byte("hi"), 0o644); err != nil {
		t.Fatal(err)
	}
	files, err := s.ListFiles(meta.ID, "")
	if err != nil {
		t.Fatal(err)
	}
	if len(files) != 1 || files[0].Path != "note.txt" {
		t.Fatalf("%+v", files)
	}
	for _, f := range files {
		if strings.Contains(f.Path, host) || strings.Contains(f.Path, s.root) || strings.HasPrefix(f.Path, "/") {
			t.Fatalf("host path leaked: %q", f.Path)
		}
	}
}

func TestWorkspaceQuotaPrecheck(t *testing.T) {
	t.Parallel()
	s := testStore(t)
	meta, err := s.Create("user:1", 1) // 1 MiB
	if err != nil {
		t.Fatal(err)
	}
	exceed, err := s.WouldExceed(meta.ID, 2*1024*1024)
	if err != nil {
		t.Fatal(err)
	}
	if !exceed {
		t.Fatal("2 MiB extra on 1 MiB quota must exceed")
	}
}

func TestCreateRejectsMissingOwner(t *testing.T) {
	t.Parallel()
	s := testStore(t)
	if _, err := s.Create("", 10); err == nil {
		t.Fatal("expected owner error")
	}
}

func testStore(t *testing.T) *Store {
	t.Helper()
	s, err := New(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	return s
}
