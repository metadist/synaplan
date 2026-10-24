// Package metrics renders the Prometheus text the watchdog serves.
package metrics

import (
	"fmt"
	"strings"

	"github.com/metadist/synaplan-watchdog/internal/capacity"
)

// Render appends synaplan gauges to FrankenPHP's metrics text.
func Render(frankenPHP string, snap capacity.Snapshot) string {
	var b strings.Builder
	pass := strings.TrimSpace(frankenPHP)
	if pass != "" {
		b.WriteString(pass)
		b.WriteByte('\n')
	}
	fmt.Fprintf(&b, "synaplan_server_info{server=%q,role=%q,runtime=%q,version=%q} 1\n",
		snap.ServerID, snap.Role, snap.Runtime, snap.Version)
	writePool(&b, "streams", snap.Streams)
	writePool(&b, "api", snap.API)
	ratio := 0.0
	if snap.Streams.Total > 0 {
		ratio = float64(snap.Streams.Busy) / float64(snap.Streams.Total)
	}
	fmt.Fprintf(&b, "synaplan_stream_slots_busy_ratio %g\n", ratio)
	return b.String()
}

func writePool(b *strings.Builder, name string, pool capacity.Pool) {
	fmt.Fprintf(b, "synaplan_stream_slots_total{pool=%q} %d\n", name, pool.Total)
	fmt.Fprintf(b, "synaplan_stream_slots_busy{pool=%q} %d\n", name, pool.Busy)
}
