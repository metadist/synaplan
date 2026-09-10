package hostile

import (
	"bufio"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

var validReasons = map[string]bool{
	"": true, "timeout": true, "oom": true, "pids_limit": true, "output_limit": true, "program_error": true, "cancelled": true,
}

func corpusFiles(t *testing.T, pattern string) []string {
	t.Helper()
	files, err := filepath.Glob(pattern)
	if err != nil {
		t.Fatal(err)
	}
	if len(files) == 0 {
		t.Fatalf("no corpus files match %s", pattern)
	}
	return files
}

func TestCorpusHeaders(t *testing.T) {
	t.Parallel()
	scripts := append(corpusFiles(t, "*.py"), corpusFiles(t, filepath.Join("node", "*.js"))...)
	if len(scripts) != 16 {
		t.Fatalf("expected 8 python + 8 node scripts, got %d", len(scripts))
	}
	for _, name := range scripts {
		name := name
		t.Run(name, func(t *testing.T) {
			ex, err := ParseHeader(name)
			if err != nil {
				t.Fatal(err)
			}
			if ex.Result != "succeeded" && ex.Result != "failed" {
				t.Fatalf("expected-result %q", ex.Result)
			}
			if !validReasons[ex.Reason] {
				t.Fatalf("reason %q is not in the protocol enum", ex.Reason)
			}
			if ex.Result == "failed" && ex.Reason == "" {
				t.Fatal("a failed script must name its reason")
			}
			if ex.Note == "" {
				t.Fatal("note is required")
			}
			if strings.HasSuffix(name, "huge_stdout.py") || strings.HasSuffix(name, "huge_stdout.js") {
				if !ex.TruncatedStdout {
					t.Fatal("huge stdout must declare truncated-stdout")
				}
			}
		})
	}
}

// TestNodeCorpusIsJavaScript guards against Python-style headers or
// docstrings leaking into the Node mirror: no line may start with "#" and
// the header must parse from "//" comments.
func TestNodeCorpusIsJavaScript(t *testing.T) {
	t.Parallel()
	for _, name := range corpusFiles(t, filepath.Join("node", "*.js")) {
		name := name
		t.Run(filepath.Base(name), func(t *testing.T) {
			f, err := os.Open(name)
			if err != nil {
				t.Fatal(err)
			}
			defer f.Close()
			sc := bufio.NewScanner(f)
			line := 0
			for sc.Scan() {
				line++
				text := strings.TrimSpace(sc.Text())
				if strings.HasPrefix(text, "#") {
					t.Fatalf("%s:%d starts with #", name, line)
				}
				if strings.HasPrefix(text, `"""`) {
					t.Fatalf("%s:%d is a Python docstring", name, line)
				}
			}
			if line == 0 {
				t.Fatal("empty script")
			}
			ex, err := ParseHeader(name)
			if err != nil {
				t.Fatal(err)
			}
			if ex.Result == "" {
				t.Fatal("header did not parse from // comments")
			}
		})
	}
}

func TestPythonAndNodeMirrorsAgree(t *testing.T) {
	t.Parallel()
	for _, py := range corpusFiles(t, "*.py") {
		base := strings.TrimSuffix(filepath.Base(py), ".py")
		js := filepath.Join("node", base+".js")
		p, err := ParseHeader(py)
		if err != nil {
			t.Fatal(err)
		}
		n, err := ParseHeader(js)
		if err != nil {
			t.Fatalf("%s has no node mirror: %v", base, err)
		}
		if p.Result != n.Result || p.Reason != n.Reason || p.TruncatedStdout != n.TruncatedStdout {
			t.Fatalf("%s: python %+v vs node %+v", base, p, n)
		}
	}
}

// TestHostileLiveCorpusGate documents the gate for the live corpus: it only
// runs when COMPUTE_HOSTILE_DOCKER=1 and a service is reachable, which is a
// separate job. Without the flag the live part is skipped, not failed.
func TestHostileLiveCorpusGate(t *testing.T) {
	if os.Getenv("COMPUTE_HOSTILE_DOCKER") != "1" {
		t.Skip("set COMPUTE_HOSTILE_DOCKER=1 to submit the corpus to a live service")
	}
	if os.Getenv("COMPUTE_URL") == "" || os.Getenv("COMPUTE_AUTH_TOKEN") == "" {
		t.Fatal("COMPUTE_HOSTILE_DOCKER=1 requires COMPUTE_URL and COMPUTE_AUTH_TOKEN")
	}
}
