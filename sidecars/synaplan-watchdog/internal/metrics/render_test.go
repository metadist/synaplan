package metrics

import (
	"strings"
	"testing"

	"github.com/metadist/synaplan-watchdog/internal/capacity"
)

func TestRenderPassesFrankenPHPThrough(t *testing.T) {
	body := Render("frankenphp_total_workers{worker=\"streams\"} 16\n", capacity.Snapshot{
		ServerID: "web1",
		Runtime:  "compose",
		Version:  "dev",
		Streams:  capacity.Pool{Total: 16, Busy: 4},
		API:      capacity.Pool{Total: 8, Busy: 1},
	})
	if !strings.Contains(body, "frankenphp_total_workers{worker=\"streams\"} 16\n") {
		t.Fatalf("missing passthrough:\n%s", body)
	}
	if !strings.Contains(body, "synaplan_server_info{server=\"web1\",runtime=\"compose\",version=\"dev\"} 1\n") {
		t.Fatalf("missing server info:\n%s", body)
	}
	if !strings.Contains(body, "synaplan_stream_slots_total{pool=\"streams\"} 16\n") {
		t.Fatalf("missing slots:\n%s", body)
	}
	if !strings.Contains(body, "synaplan_stream_slots_busy{pool=\"streams\"} 4\n") {
		t.Fatalf("missing busy:\n%s", body)
	}
	if !strings.Contains(body, "synaplan_stream_slots_busy_ratio 0.25\n") {
		t.Fatalf("missing ratio:\n%s", body)
	}
}

func TestRenderQuotesALabel(t *testing.T) {
	body := Render("", capacity.Snapshot{ServerID: `web"1`})
	if !strings.Contains(body, `server="web\"1"`) {
		t.Fatalf("label = %s", body)
	}
}
