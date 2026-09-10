package config

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

const minAuthTokenBytes = 32

// Config is env-derived service configuration.
type Config struct {
	ListenAddr        string
	AuthToken         string
	ScratchDir        string
	WorkspacesDir     string
	Tier              string
	RuntimeName       string
	MaxTimeoutSec     int
	MaxMemoryMb       int
	MaxCPU            float64
	MaxPids           int
	MaxOutputMb       int
	MaxConcurrent     int
	QueueMax          int
	LogCapBytes       int
	RunRetention      time.Duration
	EgressEnabled     bool
	EgressMaxHosts    int
	MaxRequestBytes   int64
	MaxFiles          int
	ArtefactMIMEAllow []string
}

// Load reads COMPUTE_* environment variables. Auth token must be ≥ 32 bytes.
func Load() (*Config, error) {
	c := &Config{
		ListenAddr:        env("COMPUTE_LISTEN", ":8080"),
		AuthToken:         os.Getenv("COMPUTE_AUTH_TOKEN"),
		ScratchDir:        env("COMPUTE_SCRATCH_DIR", "/var/lib/synaplan-compute/scratch"),
		WorkspacesDir:     env("COMPUTE_WORKSPACES_DIR", "/var/lib/synaplan-compute/workspaces"),
		Tier:              strings.ToLower(strings.TrimSpace(os.Getenv("COMPUTE_TIER"))),
		RuntimeName:       os.Getenv("COMPUTE_RUNTIME_NAME"),
		MaxTimeoutSec:     envInt("COMPUTE_MAX_TIMEOUT_SEC", 300),
		MaxMemoryMb:       envInt("COMPUTE_MAX_MEMORY_MB", 2048),
		MaxCPU:            envFloat("COMPUTE_MAX_CPU", 2.0),
		MaxPids:           envInt("COMPUTE_MAX_PIDS", 256),
		MaxOutputMb:       envInt("COMPUTE_MAX_OUTPUT_MB", 200),
		MaxConcurrent:     envInt("COMPUTE_MAX_CONCURRENT", 8),
		QueueMax:          envInt("COMPUTE_QUEUE_MAX", 16),
		LogCapBytes:       envInt("COMPUTE_LOG_CAP_BYTES", 256*1024),
		RunRetention:      time.Duration(envInt("COMPUTE_RUN_RETENTION_MIN", 60)) * time.Minute,
		EgressEnabled:     envBool("COMPUTE_EGRESS_ENABLED", false),
		EgressMaxHosts:    envInt("COMPUTE_EGRESS_MAX_HOSTS", 8),
		MaxRequestBytes:   int64(envInt("COMPUTE_MAX_REQUEST_BYTES", 32*1024*1024)),
		MaxFiles:          envInt("COMPUTE_MAX_FILES", 32),
		ArtefactMIMEAllow: splitCSV(env("COMPUTE_ARTEFACT_MIME_ALLOW", strings.Join(defaultMIME, ","))),
	}
	if err := c.Validate(); err != nil {
		return nil, err
	}
	return c, nil
}

// Validate enforces startup invariants.
func (c *Config) Validate() error {
	if len(c.AuthToken) < minAuthTokenBytes {
		return fmt.Errorf("COMPUTE_AUTH_TOKEN must be at least %d bytes", minAuthTokenBytes)
	}
	switch c.Tier {
	case "", "docker", "gvisor", "microvm":
	default:
		return fmt.Errorf("COMPUTE_TIER must be docker, gvisor, or microvm")
	}
	return nil
}

func env(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func envInt(key string, fallback int) int {
	v := os.Getenv(key)
	if v == "" {
		return fallback
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		return fallback
	}
	return n
}

func envFloat(key string, fallback float64) float64 {
	v := os.Getenv(key)
	if v == "" {
		return fallback
	}
	f, err := strconv.ParseFloat(v, 64)
	if err != nil {
		return fallback
	}
	return f
}

func envBool(key string, fallback bool) bool {
	v := strings.ToLower(strings.TrimSpace(os.Getenv(key)))
	switch v {
	case "1", "true", "yes", "on":
		return true
	case "0", "false", "no", "off":
		return false
	default:
		return fallback
	}
}

func splitCSV(s string) []string {
	parts := strings.Split(s, ",")
	out := make([]string, 0, len(parts))
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p != "" {
			out = append(out, p)
		}
	}
	return out
}

var defaultMIME = []string{
	"image/png",
	"image/jpeg",
	"image/gif",
	"image/webp",
	"image/svg+xml",
	"application/pdf",
	"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
	"application/vnd.openxmlformats-officedocument.wordprocessingml.document",
	"application/vnd.openxmlformats-officedocument.presentationml.presentation",
	"application/vnd.oasis.opendocument.spreadsheet",
	"application/vnd.oasis.opendocument.text",
	"application/vnd.oasis.opendocument.presentation",
	"text/csv",
	"application/json",
	"text/plain",
	"application/zip",
}
