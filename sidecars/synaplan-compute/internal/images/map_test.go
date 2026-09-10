package images

import "testing"

func TestDefaultKeysAndPrograms(t *testing.T) {
	t.Parallel()
	m := Default()
	if err := m.RequireDigests(); err != nil {
		t.Fatal(err)
	}
	py, ok := m.Lookup(KeyPython)
	if !ok {
		t.Fatal("python key missing")
	}
	if !m.ProgramAllowed(KeyPython, "python") || !m.ProgramAllowed(KeyPython, "sh") {
		t.Fatal("python allow-list")
	}
	if m.ProgramAllowed(KeyPython, "bash") || m.ProgramAllowed(KeyPython, "perl") {
		t.Fatal("python must not allow extra programs")
	}
	nd, ok := m.Lookup(KeyNode)
	if !ok {
		t.Fatal("node key missing")
	}
	if !m.ProgramAllowed(KeyNode, "node") || !m.ProgramAllowed(KeyNode, "sh") {
		t.Fatal("node allow-list")
	}
	if py.Ref == nd.Ref {
		t.Fatal("python and node must pin distinct digests")
	}
	if _, ok := m.Lookup("python:3.12"); ok {
		t.Fatal("free image refs must not resolve")
	}
	if _, ok := m.Lookup("ghcr.io/metadist/synaplan-compute-python:latest"); ok {
		t.Fatal("tag refs must not resolve")
	}
}

func TestImageMapRequiresDigest(t *testing.T) {
	t.Parallel()
	m := New([]Image{{Key: "python", Ref: "python:3.12", Programs: []string{"python"}}})
	if err := m.RequireDigests(); err == nil {
		t.Fatal("expected digest error")
	}
}
