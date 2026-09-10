// Package contract defines protocol 1 types for synaplan-compute.
//
// Requests are decoded with json.Decoder.DisallowUnknownFields. Image is a
// key into the service pinned map, never a free image reference. Entry.Program
// is taken from the per-image allow-list. The API never carries a free-form
// shell string; a model that wants a shell writes a script file and runs
// python, node, or sh on that file inside the sandbox.
package contract

import (
	"bufio"
	"encoding/json"
	"errors"
	"io"
	"strings"
)

// Protocol is the frozen contract version for Phase A.
const Protocol = 1

// Closed enums.
const (
	WorkspaceKindRun  = "run"
	WorkspaceKindUser = "user"

	StatusQueued    = "queued"
	StatusRunning   = "running"
	StatusSucceeded = "succeeded"
	StatusFailed    = "failed"
	StatusCancelled = "cancelled"

	ReasonTimeout      = "timeout"
	ReasonOOM          = "oom"
	ReasonPidsLimit    = "pids_limit"
	ReasonOutputLimit  = "output_limit"
	ReasonProgramError = "program_error"
	ReasonCancelled    = "cancelled"

	FileRoleInput = "input"

	ErrUnknownField      = "unknown_field"
	ErrUnknownImage      = "unknown_image"
	ErrProgramNotAllowed = "program_not_allowed"
	ErrLimitsExceedCaps  = "limits_exceed_caps"
	ErrBadFileName       = "bad_file_name"
	ErrTooManyFiles      = "too_many_files"
	ErrPayloadTooLarge   = "payload_too_large"
	ErrUnauthorized      = "unauthorized"
	ErrWorkspaceQuota    = "workspace_quota_exceeded"
	ErrWorkspaceNotOwned = "workspace_not_owned"
	ErrWorkspaceNotFound = "workspace_not_found"
	ErrCapacityExceeded  = "capacity_exceeded"
	ErrEgressNotAllowed  = "egress_not_allowed"
	ErrMissingOwner      = "missing_owner"
	ErrDockerUnavailable = "docker_unavailable"
	ErrInvalidProtocol   = "invalid_protocol"
	ErrInvalidWorkspace  = "invalid_workspace"
	ErrInvalidJSON       = "invalid_json"
	ErrInternal          = "internal_error"
	ErrRunNotFound       = "run_not_found"
	ErrArtefactNotFound  = "artefact_not_found"
	ErrMimeNotAllowed    = "mime_not_allowed"
)

// SSE event names emitted by GET /v1/runs/{id}/logs.
const (
	LogEventStdout    = "stdout"
	LogEventStderr    = "stderr"
	LogEventStatus    = "status"
	LogEventTruncated = "truncated"
	LogEventDone      = "done"
)

// LogChunk is the data of a stdout or stderr event.
type LogChunk struct {
	Seq  int    `json:"seq"`
	Text string `json:"text"`
}

// LogStatus is the data of a status event.
type LogStatus struct {
	Status string `json:"status"`
}

// LogDone is the data of the final done event; exitCode is absent when the
// container never reported one (cancelled, docker unavailable).
type LogDone struct {
	Status   string `json:"status"`
	ExitCode *int   `json:"exitCode,omitempty"`
	Reason   string `json:"reason,omitempty"`
}

// LimitDetail is the details object of a limits_exceed_caps error.
type LimitDetail struct {
	Field     string  `json:"field"`
	Requested float64 `json:"requested"`
	Cap       float64 `json:"cap"`
}

// RunRequest is POST /v1/runs request.json.
type RunRequest struct {
	Protocol  int       `json:"protocol"`
	Owner     string    `json:"owner"`
	Workspace Workspace `json:"workspace"`
	Image     string    `json:"image"`
	Entry     Entry     `json:"entry"`
	Files     []FileRef `json:"files"`
	Limits    Limits    `json:"limits"`
	Egress    Egress    `json:"egress"`
}

// Workspace selects the ephemeral run workspace or a persistent user workspace.
type Workspace struct {
	Kind string `json:"kind"`
	ID   string `json:"id,omitempty"`
}

// Entry is argv executed inside the sandbox. Program is an allow-list key.
type Entry struct {
	Program string   `json:"program"`
	Args    []string `json:"args"`
}

// FileRef names a multipart part that must land in /work before start.
type FileRef struct {
	Name string `json:"name"`
	Role string `json:"role"`
}

// Limits are per-run resource bounds; they must not exceed instance caps.
type Limits struct {
	TimeoutSec int     `json:"timeoutSec"`
	MemoryMb   int     `json:"memoryMb"`
	CPU        float64 `json:"cpu"`
	Pids       int     `json:"pids"`
	OutputMb   int     `json:"outputMb"`
}

// Egress is the per-run host allow-list. Empty Keep NetworkMode none.
type Egress struct {
	Allow []EgressHost `json:"allow"`
}

// EgressHost is a PHP/SsrfGuard-pinned destination. Compute trusts IPs only.
type EgressHost struct {
	Host string   `json:"host"`
	Port int      `json:"port"`
	IPs  []string `json:"ips"`
}

// RunAccepted is the 202 body.
type RunAccepted struct {
	RunID string `json:"runId"`
}

// RunStatus is GET /v1/runs/{id}.
type RunStatus struct {
	RunID      string    `json:"runId"`
	Status     string    `json:"status"`
	ExitCode   *int      `json:"exitCode,omitempty"`
	Reason     string    `json:"reason,omitempty"`
	StartedAt  string    `json:"startedAt,omitempty"`
	FinishedAt string    `json:"finishedAt,omitempty"`
	DurationMs *int64    `json:"durationMs,omitempty"`
	Usage      Usage     `json:"usage"`
	Truncated  Truncated `json:"truncated"`
}

// Usage is resource accounting for a run. wallMs is the container wall time;
// cpuSec and maxMemoryMb stay zero until cgroup accounting lands (A3).
type Usage struct {
	WallMs      int64   `json:"wallMs"`
	CPUSec      float64 `json:"cpuSec"`
	MaxMemoryMb int     `json:"maxMemoryMb"`
	BytesIn     int64   `json:"bytesIn"`
	BytesOut    int64   `json:"bytesOut"`
}

// Truncated reports server-side log caps.
type Truncated struct {
	Stdout bool `json:"stdout"`
	Stderr bool `json:"stderr"`
}

// ErrorBody is the problem+json envelope.
type ErrorBody struct {
	Error ErrorDetail `json:"error"`
}

// ErrorDetail is the inner error object.
type ErrorDetail struct {
	Code    string `json:"code"`
	Message string `json:"message"`
	Details any    `json:"details,omitempty"`
}

// Health is GET /v1/health.
type Health struct {
	Protocol int            `json:"protocol"`
	Tier     string         `json:"tier"`
	Images   []HealthImage  `json:"images"`
	Capacity HealthCapacity `json:"capacity"`
	Caps     HealthCaps     `json:"caps"`
	Features HealthFeatures `json:"features"`
}

// HealthImage is a pinned image key and digest reference.
type HealthImage struct {
	Key string `json:"key"`
	Ref string `json:"ref"`
}

// HealthCapacity is the live run counter.
type HealthCapacity struct {
	MaxConcurrent int `json:"maxConcurrent"`
	Running       int `json:"running"`
	Queued        int `json:"queued"`
}

// HealthCaps are instance-wide hard limits.
type HealthCaps struct {
	TimeoutSec int     `json:"timeoutSec"`
	MemoryMb   int     `json:"memoryMb"`
	CPU        float64 `json:"cpu"`
	Pids       int     `json:"pids"`
	OutputMb   int     `json:"outputMb"`
}

// HealthFeatures reports optional capabilities.
type HealthFeatures struct {
	Workspaces bool `json:"workspaces"`
	Egress     bool `json:"egress"`
}

// WorkspaceCreate is POST /v1/workspaces.
type WorkspaceCreate struct {
	Owner   string `json:"owner"`
	QuotaMb int    `json:"quotaMb"`
}

// WorkspaceCreated is the 201 body.
type WorkspaceCreated struct {
	WorkspaceID string `json:"workspaceId"`
	QuotaMb     int    `json:"quotaMb"`
}

// WorkspaceUsage is GET /v1/workspaces/{id}/usage.
type WorkspaceUsage struct {
	UsedMb     int    `json:"usedMb"`
	QuotaMb    int    `json:"quotaMb"`
	FileCount  int    `json:"fileCount"`
	LastUsedAt string `json:"lastUsedAt"`
}

// WorkspaceFile is one regular file in a workspace listing.
type WorkspaceFile struct {
	Path       string `json:"path"`
	Size       int64  `json:"size"`
	Mime       string `json:"mime"`
	ModifiedAt string `json:"modifiedAt"`
}

// Artefact is one /out listing row.
type Artefact struct {
	Name     string `json:"name"`
	Size     int64  `json:"size"`
	Mime     string `json:"mime"`
	SHA256   string `json:"sha256,omitempty"`
	Rejected string `json:"rejected,omitempty"`
}

// DecodeError carries the protocol error code for a failed decode:
// unknown_field for DisallowUnknownFields violations, invalid_json otherwise.
type DecodeError struct {
	Code string
	Err  error
}

func (e *DecodeError) Error() string { return e.Code + ": " + e.Err.Error() }

// Unwrap exposes the underlying decoder error.
func (e *DecodeError) Unwrap() error { return e.Err }

// DecodeJSON decodes v with unknown fields rejected. maxBytes 0 means no extra cap.
func DecodeJSON(r io.Reader, v any) error {
	dec := json.NewDecoder(r)
	dec.DisallowUnknownFields()
	if err := dec.Decode(v); err != nil {
		return &DecodeError{Code: classifyDecodeError(err), Err: err}
	}
	return nil
}

// DecodeJSONLimited wraps r with a byte cap before DecodeJSON.
func DecodeJSONLimited(r io.Reader, maxBytes int64, v any) error {
	if maxBytes > 0 {
		r = io.LimitReader(r, maxBytes+1)
	}
	return DecodeJSON(r, v)
}

func classifyDecodeError(err error) string {
	if strings.Contains(err.Error(), "unknown field") {
		return ErrUnknownField
	}
	return ErrInvalidJSON
}

// DecodeCode returns the protocol code for a DecodeJSON error, or "" when err
// did not come from DecodeJSON.
func DecodeCode(err error) string {
	var de *DecodeError
	if errors.As(err, &de) {
		return de.Code
	}
	return ""
}

// UnknownField reports whether err came from DisallowUnknownFields.
func UnknownField(err error) bool {
	return DecodeCode(err) == ErrUnknownField
}

// SSEEvent is one parsed text/event-stream record.
type SSEEvent struct {
	ID    string
	Event string
	Data  string
}

// ParseSSE reads a text/event-stream body into records. Only id, event, and
// data fields are recognised; comments and unknown fields are ignored.
func ParseSSE(r io.Reader) ([]SSEEvent, error) {
	sc := bufio.NewScanner(r)
	sc.Buffer(make([]byte, 0, 64*1024), 16*1024*1024)
	var out []SSEEvent
	var cur SSEEvent
	var dataLines []string
	flush := func() {
		if cur.Event == "" && len(dataLines) == 0 && cur.ID == "" {
			return
		}
		cur.Data = strings.Join(dataLines, "\n")
		out = append(out, cur)
		cur = SSEEvent{}
		dataLines = nil
	}
	for sc.Scan() {
		line := sc.Text()
		if line == "" {
			flush()
			continue
		}
		if strings.HasPrefix(line, ":") {
			continue
		}
		field, value, _ := strings.Cut(line, ":")
		value = strings.TrimPrefix(value, " ")
		switch field {
		case "id":
			cur.ID = value
		case "event":
			cur.Event = value
		case "data":
			dataLines = append(dataLines, value)
		}
	}
	if err := sc.Err(); err != nil {
		return nil, err
	}
	flush()
	return out, nil
}
