package hostile

import (
	"os"
	"path/filepath"
	"testing"
)

func TestCorpusHeaders(t *testing.T) {
	t.Parallel()
	root := "."
	scripts := []string{
		"fork_bomb.py",
		"proc_walk.py",
		"disk_fill.py",
		"dns_attempt.py",
		"setuid.py",
		"symlink_out.py",
		"long_sleep.py",
		"huge_stdout.py",
		filepath.Join("node", "fork_bomb.js"),
		filepath.Join("node", "proc_walk.js"),
		filepath.Join("node", "disk_fill.js"),
		filepath.Join("node", "dns_attempt.js"),
		filepath.Join("node", "setuid.js"),
		filepath.Join("node", "symlink_out.js"),
		filepath.Join("node", "long_sleep.js"),
		filepath.Join("node", "huge_stdout.js"),
	}
	for _, name := range scripts {
		t.Run(name, func(t *testing.T) {
			ex, err := ParseHeader(filepath.Join(root, name))
			if err != nil {
				t.Fatal(err)
			}
			if ex.Result != "succeeded" && ex.Result != "failed" {
				t.Fatalf("expected-result %q", ex.Result)
			}
			if name == "huge_stdout.py" || name == filepath.Join("node", "huge_stdout.js") {
				if !ex.TruncatedStdout {
					t.Fatal("huge stdout must declare truncated-stdout")
				}
			}
		})
	}
}

func TestHostileSkippedWithoutDocker(t *testing.T) {
	if os.Getenv("COMPUTE_HOSTILE_DOCKER") == "1" {
		t.Skip("live docker corpus is a separate CI job")
	}
}
