package logs

import (
	"bytes"
	"strings"
	"sync"
	"testing"
	"unicode/utf8"
)

func TestLogTruncation(t *testing.T) {
	t.Parallel()
	s := New(16)
	s.Append(Stdout, []byte(strings.Repeat("x", 100)))

	out, _, truncOut, truncErr := s.Snapshot()
	if len(out) != 16 {
		t.Fatalf("stdout kept %d bytes, want the 16-byte cap", len(out))
	}
	if !bytes.Equal(out, []byte(strings.Repeat("x", 16))) {
		t.Fatalf("truncation must keep the head of the output: %q", out)
	}
	if !truncOut {
		t.Fatal("stdout past the cap must be reported as truncated")
	}
	if truncErr {
		t.Fatal("stderr saw no writes and must not be marked truncated")
	}
}

func TestCapAppliesPerStream(t *testing.T) {
	t.Parallel()
	s := New(8)
	s.Append(Stdout, []byte("0123456789"))
	s.Append(Stderr, []byte("abc"))

	out, errb, truncOut, truncErr := s.Snapshot()
	if string(out) != "01234567" || !truncOut {
		t.Fatalf("stdout = %q truncated = %v", out, truncOut)
	}
	// stderr has its own budget, so a flooded stdout must not consume it.
	if string(errb) != "abc" || truncErr {
		t.Fatalf("stderr = %q truncated = %v", errb, truncErr)
	}
}

func TestWritesAfterTheCapAreDiscarded(t *testing.T) {
	t.Parallel()
	s := New(4)
	s.Append(Stdout, []byte("abcd"))
	if _, truncated := s.Truncated(); truncated {
		t.Fatal("a write that exactly fills the cap is not truncation")
	}
	s.Append(Stdout, []byte("efgh"))

	out, _, truncOut, _ := s.Snapshot()
	if string(out) != "abcd" {
		t.Fatalf("output past the cap must be dropped, got %q", out)
	}
	if !truncOut {
		t.Fatal("a discarded write must set the truncation flag")
	}
}

func TestSnapshotReplacesInvalidUTF8(t *testing.T) {
	t.Parallel()
	s := New(64)
	s.Append(Stdout, []byte{'o', 'k', 0xff, 0xfe})

	out, _, _, _ := s.Snapshot()
	if !utf8.Valid(out) {
		t.Fatalf("snapshot must be valid UTF-8, got % x", out)
	}
	if !strings.HasPrefix(string(out), "ok") {
		t.Fatalf("valid bytes must survive sanitizing: %q", out)
	}
	if !strings.Contains(string(out), replacement) {
		t.Fatalf("invalid bytes must become the replacement rune: %q", out)
	}
}

func TestRuneSplitAcrossAppendsSurvives(t *testing.T) {
	t.Parallel()
	// The runtime can cut a multi-byte rune between two log frames. Sanitizing
	// per write would mangle both halves, so this must round-trip.
	euro := []byte("€")
	if len(euro) != 3 {
		t.Fatalf("expected a 3-byte rune, got %d", len(euro))
	}
	s := New(64)
	s.Append(Stdout, euro[:1])
	s.Append(Stdout, euro[1:])

	out, _, _, _ := s.Snapshot()
	if string(out) != "€" {
		t.Fatalf("rune split across appends became %q", out)
	}
}

func TestRuneCutByTheCapIsReplaced(t *testing.T) {
	t.Parallel()
	// Cap 1 leaves room for only the first byte of the rune; the partial rune
	// must not leave invalid UTF-8 in the response body.
	s := New(1)
	s.Append(Stdout, []byte("€"))

	out, _, truncOut, _ := s.Snapshot()
	if !utf8.Valid(out) {
		t.Fatalf("snapshot must be valid UTF-8, got % x", out)
	}
	if string(out) != replacement {
		t.Fatalf("partial rune = %q, want the replacement rune", out)
	}
	if !truncOut {
		t.Fatal("cutting the rune means output was dropped")
	}
}

func TestNonPositiveCapKeepsNothing(t *testing.T) {
	t.Parallel()
	for _, capBytes := range []int{0, -1} {
		s := New(capBytes)
		if _, truncated := s.Truncated(); truncated {
			t.Fatalf("cap %d: nothing written yet, nothing truncated", capBytes)
		}
		s.Append(Stdout, []byte("x"))

		out, _, truncOut, _ := s.Snapshot()
		if len(out) != 0 {
			t.Fatalf("cap %d kept %q", capBytes, out)
		}
		if !truncOut {
			t.Fatalf("cap %d must report the dropped write", capBytes)
		}
	}
}

func TestEmptyAndUnknownKinds(t *testing.T) {
	t.Parallel()
	s := New(16)
	s.Append(Stdout, nil)
	s.Append(Stderr, []byte{})
	if out, errb, truncOut, truncErr := s.Snapshot(); out != nil || errb != nil || truncOut || truncErr {
		t.Fatalf("empty writes changed state: %q %q %v %v", out, errb, truncOut, truncErr)
	}
	// An unknown stream name must be captured, not silently dropped.
	s.Append("trace", []byte("hi"))
	if out, _, _, _ := s.Snapshot(); string(out) != "hi" {
		t.Fatalf("unknown kind was dropped, stdout = %q", out)
	}
}

func TestNilStreamIsSafe(t *testing.T) {
	t.Parallel()
	var s *Stream
	s.Append(Stdout, []byte("x"))
	if out, errb, a, b := s.Snapshot(); out != nil || errb != nil || a || b {
		t.Fatal("nil stream must snapshot empty")
	}
	if a, b := s.Truncated(); a || b {
		t.Fatal("nil stream must report no truncation")
	}
}

func TestConcurrentAppendAndSnapshot(t *testing.T) {
	t.Parallel()
	s := New(1024)
	var wg sync.WaitGroup
	for i := 0; i < 8; i++ {
		wg.Add(2)
		go func() {
			defer wg.Done()
			for j := 0; j < 100; j++ {
				s.Append(Stdout, []byte("a"))
				s.Append(Stderr, []byte("b"))
			}
		}()
		go func() {
			defer wg.Done()
			for j := 0; j < 100; j++ {
				s.Snapshot()
				s.Truncated()
			}
		}()
	}
	wg.Wait()

	out, errb, _, _ := s.Snapshot()
	if len(out) != 800 || len(errb) != 800 {
		t.Fatalf("lost writes: stdout %d stderr %d, want 800 each", len(out), len(errb))
	}
}
