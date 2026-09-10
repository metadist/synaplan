package artefact

import (
	"os"
	"path/filepath"
	"testing"
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
