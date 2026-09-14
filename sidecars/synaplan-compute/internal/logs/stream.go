// Package logs is a per-run, capped stdout/stderr buffer. The API handler
// snapshots it into SSE; further bytes after COMPUTE_LOG_CAP_BYTES (per
// stream) are discarded and reported as truncated.
package logs

import (
	"bytes"
	"sync"
)

const (
	kindStdout = "stdout"
	kindStderr = "stderr"
)

var replacement = []byte("\uFFFD")

// Stream holds one run's captured output. It has its own mutex so the log
// follower and HTTP handlers do not share Server.mu.
type Stream struct {
	mu       sync.Mutex
	cap      int
	stdout   []byte
	stderr   []byte
	truncOut bool
	truncErr bool
}

// New returns an empty stream. capBytes is the per-stream limit (stdout and
// stderr independently). A non-positive cap keeps nothing and marks the
// first write truncated.
func New(capBytes int) *Stream {
	if capBytes < 0 {
		capBytes = 0
	}
	return &Stream{cap: capBytes}
}

// Append adds p to stdout or stderr. Unknown kinds are ignored. Bytes past
// the cap are dropped.
func (s *Stream) Append(kind string, p []byte) {
	if s == nil || len(p) == 0 {
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	switch kind {
	case kindStdout:
		s.stdout, s.truncOut = appendCapped(s.stdout, p, s.cap, s.truncOut)
	case kindStderr:
		s.stderr, s.truncErr = appendCapped(s.stderr, p, s.cap, s.truncErr)
	}
}

// Snapshot returns copies of the captured streams, UTF-8 sanitized, and
// whether each side hit the cap.
func (s *Stream) Snapshot() (stdout, stderr []byte, truncOut, truncErr bool) {
	if s == nil {
		return nil, nil, false, false
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	return sanitize(s.stdout), sanitize(s.stderr), s.truncOut, s.truncErr
}

// Truncated reports whether either side hit the cap.
func (s *Stream) Truncated() (truncOut, truncErr bool) {
	if s == nil {
		return false, false
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.truncOut, s.truncErr
}

func appendCapped(dst, p []byte, cap int, already bool) ([]byte, bool) {
	if cap <= 0 {
		return dst, true
	}
	if len(dst) >= cap {
		return dst[:cap], true
	}
	room := cap - len(dst)
	if len(p) <= room {
		return append(dst, p...), already
	}
	return append(dst, p[:room]...), true
}

func sanitize(b []byte) []byte {
	if len(b) == 0 {
		return nil
	}
	cp := append([]byte(nil), b...)
	return bytes.ToValidUTF8(cp, replacement)
}
