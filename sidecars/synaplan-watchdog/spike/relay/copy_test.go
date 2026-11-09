package relay

import "testing"

func TestStepWritesTheFrameAndClosesOnTerminal(t *testing.T) {
	st := &State{}
	d := Directive{SSEIDs: true, Terminal: "data: {\"status\":\"error\"}\n\n", Idle: 60, KeepAlive: 15}
	out, done := Step(st, &Frame{Seq: 4, Kind: KindFrame, Body: "data: hi\n\n"}, 0, d, true)
	if done || out != "id: 4\ndata: hi\n\n" {
		t.Fatalf("frame = %q done=%v", out, done)
	}
	out, done = Step(st, &Frame{Seq: 5, Kind: KindTerminal, Body: "data: [DONE]\n\n"}, 0, d, true)
	if !done || out != "id: 5\ndata: [DONE]\n\n" {
		t.Fatalf("terminal = %q done=%v", out, done)
	}
}

func TestStepClosesAGapWithTheDirectiveTerminal(t *testing.T) {
	st := &State{}
	d := Directive{Terminal: "data: gap\n\n", Idle: 60}
	out, done := Step(st, &Frame{Seq: 2, Kind: KindGap, Body: "data: cut\n\n"}, 0, d, true)
	if !done || out != "data: gap\n\n" {
		t.Fatalf("gap = %q done=%v", out, done)
	}
}

func TestStepKeepsTheClientAliveWhileThePumpHeartbeats(t *testing.T) {
	st := &State{}
	d := Directive{Terminal: "data: dead\n\n", Idle: 60, KeepAlive: 15}
	out, done := Step(st, nil, 15000, d, true)
	if done || out != ": keep-alive\n\n" {
		t.Fatalf("keep-alive = %q done=%v", out, done)
	}
	out, done = Step(st, nil, 10000, d, true)
	if done || out != "" {
		t.Fatalf("idle with a live pump = %q done=%v", out, done)
	}
}

func TestStepClosesWhenThePumpIsGoneAndTheStreamIsIdle(t *testing.T) {
	st := &State{}
	d := Directive{Terminal: "data: dead\n\n", Idle: 60, KeepAlive: 15}
	if _, done := Step(st, nil, 15000, d, false); done {
		t.Fatal("closed before the idle timeout")
	}
	out, done := Step(st, nil, 45000, d, false)
	if !done || out != "data: dead\n\n" {
		t.Fatalf("dead pump = %q done=%v", out, done)
	}
}

func TestStepDoesNotPrefixGatewayFrames(t *testing.T) {
	st := &State{}
	out, done := Step(st, &Frame{Seq: 1, Kind: KindFrame, Body: "data: {\"x\":1}\n\n"}, 0, Directive{}, true)
	if done || out != "data: {\"x\":1}\n\n" {
		t.Fatalf("gateway = %q done=%v", out, done)
	}
}
