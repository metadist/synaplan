package oai

import (
	"strings"
	"testing"
)

func TestFeedSplitAtEveryByteMatchesTheWholeBody(t *testing.T) {
	pieces := []string{"Hel", "lo", " ", "world"}
	whole := Script(pieces)
	want := feedAll(whole)
	if strings.Join(want, "") != "Hello world" {
		t.Fatalf("whole = %q", want)
	}
	for cut := 0; cut <= len(whole); cut++ {
		d := &Decoder{}
		got := append(d.Feed(whole[:cut]), d.Feed(whole[cut:])...)
		if strings.Join(got, "\x00") != strings.Join(want, "\x00") {
			t.Fatalf("cut %d = %q want %q", cut, got, want)
		}
	}
}

func feedAll(s string) []string {
	return (&Decoder{}).Feed(s)
}
