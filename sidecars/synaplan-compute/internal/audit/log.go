package audit

import (
	"encoding/json"
	"io"
	"sync"
	"time"
)

// Event is one structured audit line. It has no stdout, stderr, input file
// names, or artefact contents.
type Event struct {
	TS            string   `json:"ts"`
	Event         string   `json:"event"`
	RunID         string   `json:"runId,omitempty"`
	Owner         string   `json:"owner,omitempty"`
	Image         string   `json:"image,omitempty"`
	Tier          string   `json:"tier,omitempty"`
	Limits        any      `json:"limits,omitempty"`
	ExitCode      *int     `json:"exitCode,omitempty"`
	Reason        string   `json:"reason,omitempty"`
	DurationMs    *int64   `json:"durationMs,omitempty"`
	BytesIn       *int64   `json:"bytesIn,omitempty"`
	BytesOut      *int64   `json:"bytesOut,omitempty"`
	ArtefactCount *int     `json:"artefactCount,omitempty"`
	EgressHosts   []string `json:"egressHosts,omitempty"`
}

const (
	RunAccepted      = "run.accepted"
	RunStarted       = "run.started"
	RunFinished      = "run.finished"
	RunCancelled     = "run.cancelled"
	RunRefused       = "run.refused"
	WorkspaceCreated = "workspace.created"
	WorkspaceDeleted = "workspace.deleted"
	EgressRefused    = "egress.refused"
)

// Logger writes one JSON object per line.
type Logger struct {
	mu  sync.Mutex
	out io.Writer
}

// New writes to out (typically os.Stdout).
func New(out io.Writer) *Logger {
	return &Logger{out: out}
}

// Log stamps ts if empty and encodes e. Extra fields such as stdout are
// impossible because Event does not have them.
func (l *Logger) Log(e Event) {
	if l == nil || l.out == nil {
		return
	}
	if e.TS == "" {
		e.TS = time.Now().UTC().Format(time.RFC3339Nano)
	}
	b, err := json.Marshal(e)
	if err != nil {
		return
	}
	l.mu.Lock()
	defer l.mu.Unlock()
	_, _ = l.out.Write(append(b, '\n'))
}
