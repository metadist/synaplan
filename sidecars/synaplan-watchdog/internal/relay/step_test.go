package relay

import "testing"

func TestStepCopiesAFrameAndClosesOnTerminal(t *testing.T) {
	st := &State{}
	d := Directive{SSEIDs: true, Terminal: "data: done\n\n", Idle: 60, KeepAlive: 15}
	body, done := Step(st, &Frame{Seq: 3, Kind: KindFrame, Body: "data: hi\n\n"}, 0, d, true)
	if done || body != "id: 3\ndata: hi\n\n" {
		t.Fatalf("frame = %q done=%v", body, done)
	}
	body, done = Step(st, &Frame{Seq: 4, Kind: KindTerminal, Body: "data: end\n\n"}, 0, d, true)
	if !done || body != "id: 4\ndata: end\n\n" {
		t.Fatalf("terminal = %q done=%v", body, done)
	}
}

func TestStepClosesAGapWithTheDirectiveTerminal(t *testing.T) {
	st := &State{}
	d := Directive{SSEIDs: true, Terminal: "data: gap\n\n", Idle: 60, KeepAlive: 15}
	body, done := Step(st, &Frame{Seq: 2, Kind: KindGap, Body: "ignored"}, 0, d, true)
	if !done || body != "data: gap\n\n" {
		t.Fatalf("gap = %q done=%v", body, done)
	}
}

func TestStepClosesWhenThePumpIsGone(t *testing.T) {
	st := &State{}
	d := Directive{Terminal: "data: idle\n\n", Idle: 1, KeepAlive: 15}
	body, done := Step(st, nil, 1000, d, false)
	if !done || body != "data: idle\n\n" {
		t.Fatalf("idle = %q done=%v", body, done)
	}
}

func TestStepKeepsTheClientWhileThePumpIsAlive(t *testing.T) {
	st := &State{}
	d := Directive{Terminal: "data: idle\n\n", Idle: 1, KeepAlive: 15}
	body, done := Step(st, nil, 1000, d, true)
	if done || body != "" {
		t.Fatalf("alive pump closed: %q done=%v", body, done)
	}
}

func TestStepSendsAKeepAlive(t *testing.T) {
	st := &State{}
	d := Directive{Terminal: "data: idle\n\n", Idle: 60, KeepAlive: 15}
	body, done := Step(st, nil, 15000, d, true)
	if done || body != ": keep-alive\n\n" {
		t.Fatalf("keepalive = %q done=%v", body, done)
	}
}
