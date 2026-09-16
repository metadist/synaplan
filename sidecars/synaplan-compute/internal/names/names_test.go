package names

import (
	"strings"
	"testing"
)

func TestFileName(t *testing.T) {
	t.Parallel()
	ok := []string{"main.py", "data.csv", "chart.png", "a_b-c.txt"}
	bad := []string{"", "-x", "../x", "a/b", "a\\b", strings.Repeat("x", 129)}
	for _, n := range ok {
		if !FileName(n) {
			t.Fatalf("want ok %q", n)
		}
	}
	for _, n := range bad {
		if FileName(n) {
			t.Fatalf("want reject %q", n)
		}
	}
}

func TestRelPath(t *testing.T) {
	t.Parallel()
	if p, ok := RelPath("a/b.txt"); !ok || p != "a/b.txt" {
		t.Fatalf("%q %v", p, ok)
	}
	if _, ok := RelPath("../etc/passwd"); ok {
		t.Fatal("dot-dot")
	}
	if _, ok := RelPath("/etc/passwd"); ok {
		t.Fatal("absolute")
	}
}
