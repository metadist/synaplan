// Package capacity is the Redis document this server publishes about itself.
package capacity

import "time"

const (
	// Version is the watchdog build reported on the capacity page.
	Version = "0.1.0"
	// SnapshotTTL is how long a missed report stays visible.
	SnapshotTTL = 20 * time.Second
	// AggregateTTL keeps one minute of peaks for a day.
	AggregateTTL = 24 * time.Hour
)

// Pool is one PHP worker pool.
type Pool struct {
	Total int `json:"total"`
	Busy  int `json:"busy"`
}

// Snapshot is capacity:server:{id}.
type Snapshot struct {
	ServerID         string        `json:"server_id"`
	Runtime          string        `json:"runtime"`
	Version          string        `json:"version"`
	WatchdogVersion  string        `json:"watchdog_version"`
	UpdatedAt        string        `json:"updated_at"`
	Draining         bool          `json:"draining"`
	PHPReady         bool          `json:"php_ready"`
	AdminOK          bool          `json:"admin_ok"`
	Streams          Pool          `json:"streams"`
	API              Pool          `json:"api"`
	MemoryRSSBytes   uint64        `json:"memory_rss_bytes"`
	MemoryLimitBytes uint64        `json:"memory_limit_bytes"`
	MemoryLimited    bool          `json:"memory_limited"`
	CPUCores         float64       `json:"cpu_cores"`
	CPULimited       bool          `json:"cpu_limited"`
	CPUUsageRatio    *float64      `json:"cpu_usage_ratio,omitempty"`
	Namespace        string        `json:"namespace,omitempty"`
	Node             string        `json:"node,omitempty"`
	Pod              string        `json:"pod,omitempty"`
	CPURequest       string        `json:"cpu_request,omitempty"`
	MemoryLimit      string        `json:"memory_limit,omitempty"`
	Threads          ThreadSummary `json:"threads"`
}

// ThreadSummary counts FrankenPHP threads. It has no request paths.
type ThreadSummary struct {
	Count int `json:"count"`
	Busy  int `json:"busy"`
}

// Aggregate is capacity:agg:{id}:{minute}. Peaks, not averages.
type Aggregate struct {
	Minute         string  `json:"minute"`
	Samples        int     `json:"samples"`
	StreamsBusyMax int     `json:"streams_busy_max"`
	StreamsTotal   int     `json:"streams_total"`
	APIBusyMax     int     `json:"api_busy_max"`
	APITotal       int     `json:"api_total"`
	MemoryUsedMax  uint64  `json:"memory_used_max"`
	MemoryLimit    uint64  `json:"memory_limit"`
	CPUUsageMax    float64 `json:"cpu_usage_max"`
}

// SnapshotKey is the live report for one server.
func SnapshotKey(serverID string) string {
	return "capacity:server:" + serverID
}

// AggregateKey is one minute of peaks for one server.
func AggregateKey(serverID, minute string) string {
	return "capacity:agg:" + serverID + ":" + minute
}

// Minute is the UTC minute used in the aggregate key.
func Minute(now time.Time) string {
	return now.UTC().Format("200601021504")
}

// FoldPeak merges one sample into the minute aggregate.
func FoldPeak(current Aggregate, minute string, snap Snapshot) Aggregate {
	if current.Samples == 0 {
		current.Minute = minute
	}
	current.Samples++
	if snap.Streams.Busy > current.StreamsBusyMax {
		current.StreamsBusyMax = snap.Streams.Busy
	}
	current.StreamsTotal = snap.Streams.Total
	if snap.API.Busy > current.APIBusyMax {
		current.APIBusyMax = snap.API.Busy
	}
	current.APITotal = snap.API.Total
	if snap.MemoryRSSBytes > current.MemoryUsedMax {
		current.MemoryUsedMax = snap.MemoryRSSBytes
	}
	current.MemoryLimit = snap.MemoryLimitBytes
	if snap.CPUUsageRatio != nil && *snap.CPUUsageRatio > current.CPUUsageMax {
		current.CPUUsageMax = *snap.CPUUsageRatio
	}
	return current
}
