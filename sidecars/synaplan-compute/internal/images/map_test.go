package images

import (
	"testing"
)

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

func TestLoadKeepsPublishedDigests(t *testing.T) {
	t.Setenv("COMPUTE_ALLOW_LOCAL_IMAGES", "")
	t.Setenv("COMPUTE_IMAGE_PYTHON", "")
	t.Setenv("COMPUTE_IMAGE_NODE", "")
	m, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if err := m.RequireDigests(); err != nil {
		t.Fatal(err)
	}
}

func TestLoadRejectsOverrideWithoutAllow(t *testing.T) {
	t.Setenv("COMPUTE_ALLOW_LOCAL_IMAGES", "")
	t.Setenv("COMPUTE_IMAGE_PYTHON", "synaplan-compute-python:local")
	t.Setenv("COMPUTE_IMAGE_NODE", "")
	if _, err := Load(); err == nil {
		t.Fatal("expected override without allow to fail")
	}
}

func TestLoadAllowsComposeBuiltTags(t *testing.T) {
	t.Setenv("COMPUTE_ALLOW_LOCAL_IMAGES", "1")
	t.Setenv("COMPUTE_IMAGE_PYTHON", "synaplan-compute-python:local")
	t.Setenv("COMPUTE_IMAGE_NODE", "synaplan-compute-node:local")
	m, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	py, ok := m.Lookup(KeyPython)
	if !ok || py.Ref != "synaplan-compute-python:local" {
		t.Fatalf("python ref = %+v", py)
	}
	nd, ok := m.Lookup(KeyNode)
	if !ok || nd.Ref != "synaplan-compute-node:local" {
		t.Fatalf("node ref = %+v", nd)
	}
	if !m.ProgramAllowed(KeyPython, "python") || !m.ProgramAllowed(KeyNode, "node") {
		t.Fatal("allow-lists must survive a local override")
	}
	if err := m.RequireDigests(); err != nil {
		t.Fatal(err)
	}
}
