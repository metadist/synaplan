package runner

import (
	"testing"

	"github.com/docker/docker/api/types"
	"github.com/docker/docker/api/types/mount"
)

func daemonMounts() []types.MountPoint {
	return []types.MountPoint{
		{Type: mount.TypeBind, Source: "/synaplan-compute/scratch", Destination: "/scratch"},
		{Type: mount.TypeBind, Source: "/synaplan-compute/workspaces", Destination: "/workspaces"},
		{Type: mount.TypeVolume, Source: "some_volume", Destination: "/data"},
		{Type: mount.TypeTmpfs, Destination: "/tmp"},
	}
}

func TestDaemonBindSourceRemapsToDaemonPaths(t *testing.T) {
	t.Parallel()
	got, err := DaemonBindSource(daemonMounts(), "/scratch/01HX/work")
	if err != nil {
		t.Fatal(err)
	}
	if got != "/synaplan-compute/scratch/01HX/work" {
		t.Fatalf("got %q", got)
	}
	got, err = DaemonBindSource(daemonMounts(), "/workspaces/abc/data")
	if err != nil {
		t.Fatal(err)
	}
	if got != "/synaplan-compute/workspaces/abc/data" {
		t.Fatalf("got %q", got)
	}
}

func TestDaemonBindSourceRefusesNonBinds(t *testing.T) {
	t.Parallel()
	for _, source := range []string{"/data/x", "/tmp/y", "/etc/passwd", "relative/path"} {
		if _, err := DaemonBindSource(daemonMounts(), source); err == nil {
			t.Fatalf("%q must be refused", source)
		}
	}
}

func TestDaemonBindSourceRefusesTraversal(t *testing.T) {
	t.Parallel()
	if _, err := DaemonBindSource(daemonMounts(), "/scratch/../etc/passwd"); err == nil {
		t.Fatal("dotdot escape must be refused")
	}
}

func TestDaemonBindSourceLongestPrefixWins(t *testing.T) {
	t.Parallel()
	mounts := []types.MountPoint{
		{Type: mount.TypeBind, Source: "/a", Destination: "/x"},
		{Type: mount.TypeBind, Source: "/b", Destination: "/x/sub"},
	}
	got, err := DaemonBindSource(mounts, "/x/sub/file")
	if err != nil {
		t.Fatal(err)
	}
	if got != "/b/file" {
		t.Fatalf("got %q, want /b/file", got)
	}
}
