// Package relay copies one run's frames. PHP wrote the bytes. A terminal, a gap, or a dead pump closes the client.
package relay

import "fmt"

type Kind string

const (
	KindFrame    Kind = "frame"
	KindTerminal Kind = "terminal"
	KindGap      Kind = "gap"
)

type Frame struct {
	Seq  int64
	Kind Kind
	Body string
}

type Directive struct {
	SSEIDs    bool
	Terminal  string
	Idle      int
	KeepAlive int
}

type State struct {
	SilentMs    int
	SinceKeepMs int
}

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
