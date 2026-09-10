package artefact

import (
	"errors"
	"os"
	"path/filepath"
	"testing"

	"github.com/metadist/synaplan-compute/internal/safepath"
)

func TestArtefactSymlinkOmitted(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "chart.png"), []byte("\x89PNG"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("/etc/passwd", filepath.Join(dir, "passwd")); err != nil {
		t.Fatal(err)
	}
	items, err := List(dir, 10<<20, []string{"image/png", "text/plain"})
	if err != nil {
		t.Fatal(err)
	}
	for _, it := range items {
		if it.Name == "passwd" {
			t.Fatal("symlink must be omitted")
		}
	}
	if len(items) != 1 || items[0].Name != "chart.png" {
		t.Fatalf("%+v", items)
	}
	if _, _, err := Open(dir, "passwd", 10<<20, []string{"text/plain"}); err == nil {
		t.Fatal("symlink must not be downloadable")
	}
}

func TestArtefactSymlinkedOutDirRefused(t *testing.T) {
	t.Parallel()
	scratch := t.TempDir()
	outside := t.TempDir()
	if err := os.WriteFile(filepath.Join(outside, "secret.txt"), []byte("host"), 0o644); err != nil {
		t.Fatal(err)
	}
	// The sandbox replaced /out with a symlink to a host directory.
	out := filepath.Join(scratch, "out")
	if err := os.Symlink(outside, out); err != nil {
		t.Fatal(err)
	}
	items, err := List(out, 10<<20, []string{"text/plain"})
	if !errors.Is(err, safepath.ErrSymlink) {
		t.Fatalf("listing through a symlinked out dir must return ErrSymlink, got %v items=%+v", err, items)
	}
	if _, _, err := Open(out, "secret.txt", 10<<20, []string{"text/plain"}); !errors.Is(err, safepath.ErrSymlink) {
		t.Fatalf("open through a symlinked out dir must return ErrSymlink, got %v", err)
	}
}

func TestArtefactOpenRefusesRejected(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "evil.bin"), []byte{0x00, 0x01, 0x02, 0x03}, 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "big.txt"), []byte("hello world this is too big"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "a.txt"), []byte("ok"), 0o644); err != nil {
		t.Fatal(err)
	}
	allow := []string{"text/plain"}
	_, _, err := Open(dir, "evil.bin", 10<<20, allow)
	var ref *Refused
	if !errors.As(err, &ref) || ref.Code != "mime_not_allowed" {
		t.Fatalf("mime rejection must refuse download, got %v", err)
	}
	_, _, err = Open(dir, "big.txt", 4, allow)
	if !errors.As(err, &ref) || ref.Code != "output_limit" {
		t.Fatalf("size rejection must refuse download, got %v", err)
	}
	f, it, err := Open(dir, "a.txt", 4, allow)
	if err != nil {
		t.Fatal(err)
	}
	f.Close()
	if it.Mime != "text/plain" || it.Size != 2 {
		t.Fatalf("%+v", it)
	}
	if _, _, err := Open(dir, "missing.txt", 4, allow); !errors.Is(err, ErrNotFound) {
		t.Fatalf("missing: %v", err)
	}
}

func TestTotalBytesIgnoresSymlinks(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "a"), []byte("12345"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(dir, "sub"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "sub", "b"), []byte("123"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("/etc/passwd", filepath.Join(dir, "sub", "link")); err != nil {
		t.Fatal(err)
	}
	if got := TotalBytes(dir, filepath.Join(dir, "missing")); got != 8 {
		t.Fatalf("total %d want 8", got)
	}
}

func TestArtefactMimeRejected(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "evil.bin"), []byte{0x00, 0x01, 0x02, 0x03}, 0o644); err != nil {
		t.Fatal(err)
	}
	items, err := List(dir, 10<<20, []string{"image/png", "text/plain"})
	if err != nil {
		t.Fatal(err)
	}
	if len(items) != 1 || items[0].Rejected == "" {
		t.Fatalf("expected mime rejection: %+v", items)
	}
}

func TestArtefactSizeCap(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "big.txt"), []byte("hello world this is too big"), 0o644); err != nil {
		t.Fatal(err)
	}
	items, err := List(dir, 4, []string{"text/plain"})
	if err != nil {
		t.Fatal(err)
	}
	if len(items) != 1 || items[0].Rejected != "output_limit" {
		t.Fatalf("%+v", items)
	}
}
