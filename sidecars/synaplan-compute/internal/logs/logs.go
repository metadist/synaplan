// Package logs buffers the stdout and stderr of one run for the logs SSE
// endpoint.
//
// A sandboxed script controls how much it writes, so each stream is bounded
// separately by COMPUTE_LOG_CAP_BYTES: once a stream is full its further
// output is discarded and the run reports truncation, which keeps memory flat
// while the container keeps running. Snapshots are UTF-8 sanitized because a
// script can emit arbitrary bytes and the response body must stay valid UTF-8
// (escaping for display remains the UI's job).
package logs

import (
	"bytes"
	"sync"
)

// Names of the two captured streams, as passed to Append.
const (
	Stdout = "stdout"
	Stderr = "stderr"
)

// replacement stands in for every invalid UTF-8 sequence in a snapshot.
const replacement = "\uFFFD"

// Stream captures the output of one run, bounded per stream by the configured
// cap. The log follower appends while HTTP handlers snapshot, so every method
// takes the lock.
type Stream struct {
	limit int

	mu  sync.Mutex
	out side
	err side
}

// side is one captured stream. Bytes past the cap are dropped rather than
// rotated, so what survives truncation is the head of the output.
type side struct {
	buf       []byte
	truncated bool
}

// New bounds each stream to capBytes. A non-positive cap keeps no output and
// reports truncation on the first write, so a misconfigured cap cannot grow
// unbounded.
func New(capBytes int) *Stream {
	if capBytes < 0 {
		capBytes = 0
	}
	return &Stream{limit: capBytes}
}

// Append records one write from kind. Anything other than Stderr counts as
// stdout so an unexpected stream name is captured instead of dropped.
func (s *Stream) Append(kind string, p []byte) {
	if s == nil || len(p) == 0 {
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	if kind == Stderr {
		s.err.record(p, s.limit)
		return
	}
	s.out.record(p, s.limit)
}

// Snapshot returns stdout and stderr with their truncation flags.
func (s *Stream) Snapshot() ([]byte, []byte, bool, bool) {
	if s == nil {
		return nil, nil, false, false
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	return sanitize(s.out.buf), sanitize(s.err.buf), s.out.truncated, s.err.truncated
}

// Truncated reports whether stdout and stderr hit the cap.
func (s *Stream) Truncated() (bool, bool) {
	if s == nil {
		return false, false
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.out.truncated, s.err.truncated
}

// record keeps as much of p as the cap still allows.
func (d *side) record(p []byte, limit int) {
	room := limit - len(d.buf)
	if room <= 0 {
		d.truncated = true
		return
	}
	if len(p) > room {
		d.buf = append(d.buf, p[:room]...)
		d.truncated = true
		return
	}
	d.buf = append(d.buf, p...)
}

// sanitize returns a copy of b with invalid UTF-8 replaced. Sanitizing here
// rather than in record keeps a rune that the runtime split across two
// container log frames intact; only a rune cut by the cap is replaced.
func sanitize(b []byte) []byte {
	if len(b) == 0 {
		return nil
	}
	return bytes.ToValidUTF8(b, []byte(replacement))
}
