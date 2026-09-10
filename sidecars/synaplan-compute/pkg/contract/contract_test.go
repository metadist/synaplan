package contract_test

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/metadist/synaplan-compute/pkg/contract"
)

func TestFixturesDecode(t *testing.T) {
	t.Parallel()
	dir := fixtureDir(t)
	cases := []struct {
		file string
		dst  any
	}{
		{"run_request_python.json", &contract.RunRequest{}},
		{"run_request_node.json", &contract.RunRequest{}},
		{"run_request_user_workspace.json", &contract.RunRequest{}},
		{"run_request_egress.json", &contract.RunRequest{}},
		{"run_status_succeeded.json", &contract.RunStatus{}},
		{"run_status_timeout.json", &contract.RunStatus{}},
		{"run_status_cancelled.json", &contract.RunStatus{}},
		{"artefact_list.json", &[]contract.Artefact{}},
		{"health.json", &contract.Health{}},
		{"workspace_create.json", &contract.WorkspaceCreate{}},
		{"workspace_usage.json", &contract.WorkspaceUsage{}},
		{"error_limits_exceed_caps.json", &contract.ErrorBody{}},
		{"error_workspace_quota_exceeded.json", &contract.ErrorBody{}},
		{"error_egress_not_allowed.json", &contract.ErrorBody{}},
	}
	for _, tc := range cases {
		t.Run(tc.file, func(t *testing.T) {
			b := readFixture(t, dir, tc.file)
			if err := contract.DecodeJSON(bytes.NewReader(b), tc.dst); err != nil {
				t.Fatal(err)
			}
		})
	}
}

func TestUnknownFieldRejected(t *testing.T) {
	t.Parallel()
	payloads := []string{
		`{"protocol":1,"owner":"user:1","workspace":{"kind":"run"},"image":"python","entry":{"program":"python","args":[]},"files":[],"limits":{"timeoutSec":1,"memoryMb":1,"cpu":1,"pids":1,"outputMb":1},"egress":{"allow":[]},"shell":"nope"}`,
		`{"protocol":1,"extra":true}`,
		`{"owner":"user:1","notAField":1}`,
	}
	for _, p := range payloads {
		var req contract.RunRequest
		err := contract.DecodeJSON(strings.NewReader(p), &req)
		if err == nil {
			t.Fatalf("expected unknown field error for %s", p)
		}
		if !contract.UnknownField(err) {
			t.Fatalf("want unknown field, got %v", err)
		}
	}
}

func TestPythonFixtureImageIsKey(t *testing.T) {
	t.Parallel()
	var req contract.RunRequest
	if err := contract.DecodeJSON(bytes.NewReader(readFixture(t, fixtureDir(t), "run_request_python.json")), &req); err != nil {
		t.Fatal(err)
	}
	if req.Image != "python" {
		t.Fatalf("image %q is a key, not a free ref", req.Image)
	}
	if strings.Contains(req.Image, "/") || strings.Contains(req.Image, "@") {
		t.Fatal("image must not be a reference")
	}
	if req.Entry.Program != "python" {
		t.Fatal(req.Entry.Program)
	}
}

func fixtureDir(t *testing.T) string {
	t.Helper()
	dir, err := os.Getwd()
	if err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 8; i++ {
		p := filepath.Join(dir, "tests", "fixtures", "compute-contract")
		if st, err := os.Stat(p); err == nil && st.IsDir() {
			return p
		}
		dir = filepath.Dir(dir)
	}
	t.Fatal("fixtures not found")
	return ""
}

func readFixture(t *testing.T, dir, name string) []byte {
	t.Helper()
	b, err := os.ReadFile(filepath.Join(dir, name))
	if err != nil {
		t.Fatal(err)
	}
	if name != "logs.sse" && !json.Valid(b) && !strings.HasSuffix(name, ".sse") {
		t.Fatalf("invalid json %s", name)
	}
	return b
}
