// Package relay copies one run's frames to the client that is still connected.
//
// It does not parse an AI wire format. PHP wrote the frame bytes. A terminal
// or a gap closes the client. Silence closes it only when no pump is alive.
package relay

import "fmt"

// Kind is the run-stream entry kind.
type Kind string

const (
	// KindFrame is copied and the client stays open.
	KindFrame Kind = "frame"
	// KindTerminal is copied and the client is closed.
	KindTerminal Kind = "terminal"
	// KindGap means the log was cut. The client receives the terminal frame.
	KindGap Kind = "gap"
)

// Frame is one entry from chatrun:{runId}:stream.
type Frame struct {
	Seq  int64
	Kind Kind
	Body string
}

// Directive is the attach answer PHP gave the watchdog.
type Directive struct {
	SSEIDs    bool
	Terminal  string
	Idle      int
	KeepAlive int
}

// State is how long the client has heard nothing.
type State struct {
	SilentMs    int
	SinceKeepMs int
}

// Step advances one frame or one stretch of silence.
// advancedMs is zero when frame is non-nil.
// alive is true while a pump heartbeat still exists.
// The returned text is written as-is. done means the client is closed.
func Step(st *State, frame *Frame, advancedMs int, d Directive, alive bool) (string, bool) {
	if frame != nil {
		st.SilentMs = 0
		st.SinceKeepMs = 0
		body := frame.Body
		if d.SSEIDs && frame.Kind != KindGap {
			body = fmt.Sprintf("id: %d\n%s", frame.Seq, frame.Body)
		}
		if frame.Kind == KindGap {
			return d.Terminal, true
		}
		return body, frame.Kind == KindTerminal
	}
	if advancedMs < 0 {
		advancedMs = 0
	}
	st.SilentMs += advancedMs
	st.SinceKeepMs += advancedMs
	idleMs := d.Idle * 1000
	if idleMs > 0 && st.SilentMs >= idleMs && !alive {
		return d.Terminal, true
	}
	keepMs := d.KeepAlive * 1000
	if keepMs > 0 && st.SinceKeepMs >= keepMs {
		st.SinceKeepMs = 0
		return ": keep-alive\n\n", false
	}
	return "", false
}
