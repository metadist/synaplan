package images

import (
	"fmt"
	"strings"
)

const (
	KeyPython = "python"
	KeyNode   = "node"
)

// Image is a pinned digest reference plus the in-sandbox program allow-list.
type Image struct {
	Key      string
	Ref      string
	Programs []string
}

// Map is the only source of image references. A run never pulls; keys only.
type Map struct {
	images map[string]Image
}

// Default is the v1 Python + Node catalog. Digests are placeholders until A3
// publishes signed images; the @sha256: form is still required.
func Default() *Map {
	return New([]Image{
		{
			Key:      KeyPython,
			Ref:      "ghcr.io/metadist/synaplan-compute-python@sha256:" + strings.Repeat("a", 64),
			Programs: []string{"python", "sh"},
		},
		{
			Key:      KeyNode,
			Ref:      "ghcr.io/metadist/synaplan-compute-node@sha256:" + strings.Repeat("b", 64),
			Programs: []string{"node", "sh"},
		},
	})
}

// New validates that every ref is digest-pinned.
func New(list []Image) *Map {
	m := &Map{images: make(map[string]Image, len(list))}
	for _, img := range list {
		m.images[img.Key] = img
	}
	return m
}

// Lookup returns the image for key.
func (m *Map) Lookup(key string) (Image, bool) {
	img, ok := m.images[key]
	return img, ok
}

// ProgramAllowed reports whether program is on the image allow-list.
func (m *Map) ProgramAllowed(key, program string) bool {
	img, ok := m.images[key]
	if !ok {
		return false
	}
	for _, p := range img.Programs {
		if p == program {
			return true
		}
	}
	return false
}

// List returns catalog entries in stable python-then-node order.
func (m *Map) List() []Image {
	out := make([]Image, 0, len(m.images))
	for _, key := range []string{KeyPython, KeyNode} {
		if img, ok := m.images[key]; ok {
			out = append(out, img)
		}
	}
	return out
}

// RequireDigests fails if any ref is not name@sha256:hex.
func (m *Map) RequireDigests() error {
	for _, img := range m.images {
		if err := requireDigest(img.Ref); err != nil {
			return fmt.Errorf("image %s: %w", img.Key, err)
		}
	}
	return nil
}

func requireDigest(ref string) error {
	at := strings.LastIndex(ref, "@sha256:")
	if at < 0 {
		return fmt.Errorf("ref %q is not digest-pinned", ref)
	}
	hex := ref[at+len("@sha256:"):]
	if len(hex) != 64 {
		return fmt.Errorf("digest hex length %d, want 64", len(hex))
	}
	for _, c := range hex {
		if (c < '0' || c > '9') && (c < 'a' || c > 'f') && (c < 'A' || c > 'F') {
			return fmt.Errorf("digest is not hex")
		}
	}
	return nil
}
