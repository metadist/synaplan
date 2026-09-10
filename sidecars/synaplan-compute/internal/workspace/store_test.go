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

func TestMetadataLivesOutsideMountedTree(t *testing.T) {
	t.Parallel()
	s := testStore(t)
	meta, err := s.Create("user:1", 16)
	if err != nil {
		t.Fatal(err)
	}
	host := s.HostPath(meta.ID)
	if filepath.Base(host) != "data" || filepath.Dir(filepath.Dir(host)) != s.root {
		t.Fatalf("host path %q must be <root>/<id>/data", host)
	}
	if _, err := os.Stat(filepath.Join(s.root, meta.ID+".json")); err != nil {
		t.Fatalf("meta must be <root>/<id>.json: %v", err)
	}
	entries, err := os.ReadDir(host)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != 0 {
		t.Fatalf("mounted tree must start empty (no meta.json inside): %v", entries)
	}
	// A script rewriting a meta.json inside the mount must have no effect.
	if err := os.WriteFile(filepath.Join(host, "meta.json"), []byte(`{"id":"`+meta.ID+`","owner":"user:evil","quotaMb":99999}`), 0o644); err != nil {
		t.Fatal(err)
	}
	got, err := s.Get(meta.ID)
	if err != nil {
		t.Fatal(err)
	}
	if got.Owner != "user:1" || got.QuotaMb != 16 {
		t.Fatalf("metadata tampered from inside the mount: %+v", got)
	}
	if err := s.Delete(meta.ID); err != nil {
		t.Fatal(err)
	}
	if _, err := s.Get(meta.ID); err != ErrNotFound {
		t.Fatalf("after delete: %v", err)
	}
	if _, err := os.Stat(filepath.Join(s.root, meta.ID)); !os.IsNotExist(err) {
		t.Fatal("id dir must be removed")
	}
}

func TestWorkspaceRefusesSymlinkEscape(t *testing.T) {
	t.Parallel()
	s := testStore(t)
	meta, err := s.Create("user:1", 16)
	if err != nil {
		t.Fatal(err)
	}
	outside := t.TempDir()
	if err := os.WriteFile(filepath.Join(outside, "secret.txt"), []byte("host secret"), 0o644); err != nil {
		t.Fatal(err)
	}
	host := s.HostPath(meta.ID)
	if err := os.Symlink(outside, filepath.Join(host, "link")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Join(outside, "secret.txt"), filepath.Join(host, "file-link.txt")); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(host, "ok.txt"), []byte("fine"), 0o644); err != nil {
		t.Fatal(err)
	}

	files, err := s.ListFiles(meta.ID, "")
	if err != nil {
		t.Fatal(err)
	}
	if len(files) != 1 || files[0].Path != "ok.txt" {
		t.Fatalf("symlinks must not be listed: %+v", files)
	}
	if _, err := s.ListFiles(meta.ID, "link"); err != ErrBadName {
		t.Fatalf("listing through a directory symlink must be refused, got %v", err)
	}
	if _, _, err := s.OpenFile(meta.ID, "link/secret.txt"); err != ErrBadName {
		t.Fatalf("open through a directory symlink must be refused, got %v", err)
	}
	if _, _, err := s.OpenFile(meta.ID, "file-link.txt"); err != ErrBadName {
		t.Fatalf("open of a file symlink must be refused, got %v", err)
	}
	f, info, err := s.OpenFile(meta.ID, "ok.txt")
	if err != nil {
		t.Fatal(err)
	}
	f.Close()
	if info.Mime != "text/plain" {
		t.Fatalf("mime must have no parameters: %q", info.Mime)
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
