package logs

import (
	"bytes"
	"sync"
	"testing"
	"unicode/utf8"
)

func TestLogTruncation(t *testing.T) {
	t.Parallel()
	s := New(16)
	s.Append("stdout", []byte("xxxxxxxxxxxxxxxxxxxx"))
	s.Append("stderr", []byte("warn\n"))
	out, errb, truncOut, truncErr := s.Snapshot()
	if !truncOut || truncErr {
		t.Fatalf("flags out=%v err=%v", truncOut, truncErr)
	}
	if len(out) != 16 {
		t.Fatalf("stdout kept %d, want 16", len(out))
	}
	if string(errb) != "warn\n" {
		t.Fatalf("stderr %q", errb)
	}
	s.Append("stdout", []byte("more"))
	out2, _, still, _ := s.Snapshot()
	if !still || !bytes.Equal(out, out2) {
		t.Fatal("bytes after the cap must be discarded")
	}
	to, te := s.Truncated()
	if !to || te {
		t.Fatalf("Truncated() = %v %v", to, te)
	}
}

func TestLogIndependentCaps(t *testing.T) {
	t.Parallel()
	s := New(8)
	s.Append("stderr", bytes.Repeat([]byte("e"), 20))
	out, errb, truncOut, truncErr := s.Snapshot()
	if truncOut || !truncErr {
		t.Fatalf("flags out=%v err=%v", truncOut, truncErr)
	}
	if len(out) != 0 || len(errb) != 8 {
		t.Fatalf("out=%d err=%d", len(out), len(errb))
	}
}

func TestLogUTF8Sanitize(t *testing.T) {
	t.Parallel()
	s := New(256)
	s.Append("stdout", []byte("ok\xffmore"))
	out, _, _, _ := s.Snapshot()
	if !utf8.Valid(out) {
		t.Fatalf("invalid UTF-8: %q", out)
	}
	if !bytes.Contains(out, []byte("ok")) || !bytes.Contains(out, []byte("more")) {
		t.Fatalf("%q", out)
	}
	if !bytes.Contains(out, []byte("\uFFFD")) {
		t.Fatal("invalid byte must be replaced")
	}
}

func TestLogEmptyAndUnknownKind(t *testing.T) {
	t.Parallel()
	s := New(16)
	s.Append("stdout", nil)
	s.Append("other", []byte("nope"))
	out, errb, to, te := s.Snapshot()
	if out != nil || errb != nil || to || te {
		t.Fatalf("%q %q %v %v", out, errb, to, te)
	}
}

func TestLogZeroCap(t *testing.T) {
	t.Parallel()
	s := New(0)
	s.Append("stdout", []byte("x"))
	out, _, to, _ := s.Snapshot()
	if !to || len(out) != 0 {
		t.Fatalf("zero cap must drop everything: %q %v", out, to)
	}
}

func TestLogConcurrentAppend(t *testing.T) {
	t.Parallel()
	s := New(64 * 1024)
	var wg sync.WaitGroup
	for i := 0; i < 8; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for j := 0; j < 100; j++ {
				s.Append("stdout", []byte("a"))
				s.Append("stderr", []byte("b"))
			}
		}()
	}
	wg.Wait()
	out, errb, _, _ := s.Snapshot()
	if len(out) != 800 || len(errb) != 800 {
		t.Fatalf("out=%d err=%d", len(out), len(errb))
	}
}
