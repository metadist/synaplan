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
	// SeenKey is the hash of every server id that reported, with its last
	// report time. A server that stops reporting stays listed here, so the
	// page can say "last report 3 minutes ago" after its snapshot expired.
	SeenKey = "capacity:seen"
	// SeenTTL drops a web server that has not reported for an hour. A
	// replaced container keeps its own id, so a longer window fills the
	// page with servers that are already gone.
	SeenTTL = time.Hour
	// WorkerListedTTL is how long a worker stays listed after its last
	// report. The page omits a worker older than this. It is under a week
	// so a replaced worker does not stay on the card for days.
	WorkerListedTTL = 24 * time.Hour
)

// Pool is one PHP worker pool.
type Pool struct {
	Total int `json:"total"`
	Busy  int `json:"busy"`
}

// Place says where this server runs. Empty fields do not apply to the runtime:
// a compose container has no pod, a bare host has no container.
type Place struct {
	// Host is the machine: the Kubernetes node, or SYNAPLAN_HOST_NAME on compose.
	Host string `json:"host,omitempty"`
	// Address is that machine's address (SYNAPLAN_NODE_ADDRESS). Empty when
	// the install did not set one, or set the container's own loopback.
	Address string `json:"address,omitempty"`
	// Pod is the Kubernetes pod name.
	Pod string `json:"pod,omitempty"`
	// Namespace is the Kubernetes namespace.
	Namespace string `json:"namespace,omitempty"`
	// Deployment is the Kubernetes Deployment the pod belongs to.
	Deployment string `json:"deployment,omitempty"`
	// Container is the container name inside the pod.
	Container string `json:"container,omitempty"`
	// CPURequest and MemoryLimit are the pod's declared resources, as strings.
	CPURequest  string `json:"cpu_request,omitempty"`
	MemoryLimit string `json:"memory_limit,omitempty"`
}

// Logs says where this server's log lines go, so the page can name the
// source without guessing. PHP and the watchdog both write to stderr.
type Logs struct {
	// Collector gathers stderr: docker, kubernetes, or process.
	Collector string `json:"collector"`
	// EventRingHost is the host value PHP stamps on event-ring records.
	EventRingHost string `json:"event_ring_host"`
}

// Snapshot is capacity:server:{id}.
type Snapshot struct {
	ServerID         string        `json:"server_id"`
	Hostname         string        `json:"hostname"`
	Role             string        `json:"role"`
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
	Place            Place         `json:"place"`
	Logs             Logs          `json:"logs"`
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

// KeyPrefix is what the application's RedisService puts before every key:
// `synaplan:<APP_ENV>:`. The watchdog writes under the same prefix so PHP
// reads the snapshots, and so a dev and a test stack on one Redis stay apart.
func KeyPrefix(appEnv string) string {
	if appEnv == "" {
		appEnv = "prod"
	}
	return "synaplan:" + appEnv + ":"
}

// SnapshotKey is the live report for one server.
func SnapshotKey(prefix, serverID string) string {
	return prefix + "capacity:server:" + serverID
}

// AggregateKey is one minute of peaks for one server.
func AggregateKey(prefix, serverID, minute string) string {
	return prefix + "capacity:agg:" + serverID + ":" + minute
}

// SeenHashKey is the seen-list under the prefix.
func SeenHashKey(prefix string) string {
	return prefix + SeenKey
}

// ListedTTL is how long a silent server's last report is kept. A worker
// stays for a day. Every other role stays for an hour.
func ListedTTL(role string) time.Duration {
	if role == "worker" {
		return WorkerListedTTL
	}
	return SeenTTL
}

// LastKey is the last report of a server, kept for ListedTTL of its role.
// The live snapshot expires after 20 seconds; this copy lets the page show
// a silent server with its role, machine and log source instead of a bare id.
func LastKey(prefix, serverID string) string {
	return prefix + "capacity:last:" + serverID
}

// PeakTTL keeps a day's peak long enough to be read as "yesterday".
const PeakTTL = 48 * time.Hour

// Peak is capacity:peak:{id}:{day}: the most AI answers held at once that day.
type Peak struct {
	Day            string `json:"day"`
	StreamsBusyMax int    `json:"streams_busy_max"`
	StreamsTotal   int    `json:"streams_total"`
	At             string `json:"at"`
}

// PeakKey is one UTC day's peak for one server.
func PeakKey(prefix, serverID, day string) string {
	return prefix + "capacity:peak:" + serverID + ":" + day
}

// Day is the UTC day used in the peak key.
func Day(now time.Time) string {
	return now.UTC().Format("20060102")
}

// RaisePeak records a new high-water mark; an equal or lower sample leaves
// the earlier time in place.
func RaisePeak(current Peak, day string, snap Snapshot) Peak {
	if current.Day != day {
		current = Peak{Day: day, StreamsBusyMax: -1}
	}
	if snap.Streams.Busy > current.StreamsBusyMax {
		current.StreamsBusyMax = snap.Streams.Busy
		current.At = snap.UpdatedAt
	}
	current.StreamsTotal = snap.Streams.Total
	return current
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
