package audit

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const hugeMarker = "SYNAPLAN_HUGE_STDOUT_MARKER"

func TestAuditNeverLogsOutput(t *testing.T) {
	t.Parallel()

	huge, err := os.ReadFile(filepath.Join(repoRoot(t), "tests", "hostile", "huge_stdout.py"))
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Contains(huge, []byte(hugeMarker)) {
		t.Fatal("huge_stdout.py must contain the marker so this test is meaningful")
	}

	var buf bytes.Buffer
	log := New(&buf)
	code := 0
	dur := int64(12)
	inB := int64(32)
	outB := int64(256 * 1024)
	nArt := 0
	log.Log(Event{
		Event:         RunFinished,
		RunID:         "01ARZ3NDEKTSV4RRFFQ69G5FAV",
		Owner:         "user:123",
		Image:         "python",
		Tier:          "docker",
		ExitCode:      &code,
		Reason:        "",
		DurationMs:    &dur,
		BytesIn:       &inB,
		BytesOut:      &outB,
		ArtefactCount: &nArt,
	})
	line := buf.String()
	if line == "" {
		t.Fatal("expected audit line")
	}
	if strings.Contains(line, hugeMarker) {
		t.Fatal("audit must not contain run stdout marker")
	}
	if strings.Contains(line, "stdout") || strings.Contains(line, "stderr") {
		t.Fatalf("audit must not name stdout/stderr fields: %s", line)
	}
	if strings.Contains(line, "main.py") || strings.Contains(line, "data.csv") {
		t.Fatal("audit must not contain input file names")
	}

	var payload map[string]any
	if err := json.Unmarshal([]byte(strings.TrimSpace(line)), &payload); err != nil {
		t.Fatal(err)
	}
	for _, forbidden := range []string{"stdout", "stderr", "files", "text", "content"} {
		if _, ok := payload[forbidden]; ok {
			t.Fatalf("forbidden field %q present", forbidden)
		}
	}
	if payload["event"] != RunFinished {
		t.Fatalf("event = %v", payload["event"])
	}
}

func TestAuditRefusedRunHasReason(t *testing.T) {
	t.Parallel()
	var buf bytes.Buffer
	New(&buf).Log(Event{
		Event:  RunRefused,
		Owner:  "user:123",
		Image:  "python",
		Reason: "limits_exceed_caps",
	})
	if !strings.Contains(buf.String(), "limits_exceed_caps") {
		t.Fatalf("missing reason: %s", buf.String())
	}
	if !strings.Contains(buf.String(), RunRefused) {
		t.Fatal("missing event")
	}
}

func repoRoot(t *testing.T) string {
	t.Helper()
	dir, err := os.Getwd()
	if err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 8; i++ {
		if _, err := os.Stat(filepath.Join(dir, "go.mod")); err == nil {
			return dir
		}
		dir = filepath.Dir(dir)
	}
	t.Fatal("go.mod not found")
	return ""
}
