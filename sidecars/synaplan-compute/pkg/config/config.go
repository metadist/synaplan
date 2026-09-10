package config

import (
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

const (
	minAuthTokenBytes = 32
	// DefaultSandboxUID is nobody:nogroup, the uid the sandbox images ship with.
	DefaultSandboxUID = 65534
)

// Config is env-derived service configuration.
type Config struct {
	ListenAddr        string
	AuthToken         string
	ScratchDir        string
	WorkspacesDir     string
	Tier              string
	RuntimeName       string
	SandboxUID        int
	SandboxGID        int
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
// A malformed numeric or boolean value fails startup instead of silently
// falling back to a default.
func Load() (*Config, error) {
	var p parser
	c := &Config{
		ListenAddr:        env("COMPUTE_LISTEN", ":8080"),
		AuthToken:         os.Getenv("COMPUTE_AUTH_TOKEN"),
		ScratchDir:        env("COMPUTE_SCRATCH_DIR", "/var/lib/synaplan-compute/scratch"),
		WorkspacesDir:     env("COMPUTE_WORKSPACES_DIR", "/var/lib/synaplan-compute/workspaces"),
		Tier:              strings.ToLower(strings.TrimSpace(os.Getenv("COMPUTE_TIER"))),
		RuntimeName:       os.Getenv("COMPUTE_RUNTIME_NAME"),
		SandboxUID:        p.int("COMPUTE_SANDBOX_UID", DefaultSandboxUID),
		SandboxGID:        p.int("COMPUTE_SANDBOX_GID", DefaultSandboxUID),
		MaxTimeoutSec:     p.int("COMPUTE_MAX_TIMEOUT_SEC", 300),
		MaxMemoryMb:       p.int("COMPUTE_MAX_MEMORY_MB", 2048),
		MaxCPU:            p.float("COMPUTE_MAX_CPU", 2.0),
		MaxPids:           p.int("COMPUTE_MAX_PIDS", 256),
		MaxOutputMb:       p.int("COMPUTE_MAX_OUTPUT_MB", 200),
		MaxConcurrent:     p.int("COMPUTE_MAX_CONCURRENT", 8),
		QueueMax:          p.int("COMPUTE_QUEUE_MAX", 16),
		LogCapBytes:       p.int("COMPUTE_LOG_CAP_BYTES", 256*1024),
		RunRetention:      time.Duration(p.int("COMPUTE_RUN_RETENTION_MIN", 60)) * time.Minute,
		EgressEnabled:     p.boolean("COMPUTE_EGRESS_ENABLED", false),
		EgressMaxHosts:    p.int("COMPUTE_EGRESS_MAX_HOSTS", 8),
		MaxRequestBytes:   int64(p.int("COMPUTE_MAX_REQUEST_BYTES", 32*1024*1024)),
		MaxFiles:          p.int("COMPUTE_MAX_FILES", 32),
		ArtefactMIMEAllow: splitCSV(env("COMPUTE_ARTEFACT_MIME_ALLOW", strings.Join(defaultMIME, ","))),
	}
	if p.err != nil {
		return nil, p.err
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
		return errors.New("COMPUTE_TIER must be docker, gvisor, or microvm")
	}
	if c.SandboxUID <= 0 || c.SandboxGID <= 0 {
		return errors.New("COMPUTE_SANDBOX_UID and COMPUTE_SANDBOX_GID must be positive (never root)")
	}
	positive := map[string]int{
		"COMPUTE_MAX_TIMEOUT_SEC":   c.MaxTimeoutSec,
		"COMPUTE_MAX_MEMORY_MB":     c.MaxMemoryMb,
		"COMPUTE_MAX_PIDS":          c.MaxPids,
		"COMPUTE_MAX_OUTPUT_MB":     c.MaxOutputMb,
		"COMPUTE_MAX_CONCURRENT":    c.MaxConcurrent,
		"COMPUTE_LOG_CAP_BYTES":     c.LogCapBytes,
		"COMPUTE_MAX_REQUEST_BYTES": int(c.MaxRequestBytes),
		"COMPUTE_MAX_FILES":         c.MaxFiles,
	}
	for name, v := range positive {
		if v <= 0 {
			return fmt.Errorf("%s must be positive", name)
		}
	}
	if c.QueueMax < 0 || c.RunRetention < 0 || c.EgressMaxHosts < 0 {
		return errors.New("COMPUTE_QUEUE_MAX, COMPUTE_RUN_RETENTION_MIN, and COMPUTE_EGRESS_MAX_HOSTS must not be negative")
	}
	if c.MaxCPU <= 0 {
		return errors.New("COMPUTE_MAX_CPU must be positive")
	}
	return nil
}

// SandboxUser renders the container User field.
func (c *Config) SandboxUser() string {
	return fmt.Sprintf("%d:%d", c.SandboxUID, c.SandboxGID)
}

func env(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

// parser records the first malformed value; Load reports it after reading
// every variable so defaults never mask a typo.
type parser struct {
	err error
}

func (p *parser) fail(key, v string, err error) {
	if p.err == nil {
		p.err = fmt.Errorf("%s=%q: %w", key, v, err)
	}
}

func (p *parser) int(key string, fallback int) int {
	v := strings.TrimSpace(os.Getenv(key))
	if v == "" {
		return fallback
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		p.fail(key, v, err)
		return fallback
	}
	return n
}

func (p *parser) float(key string, fallback float64) float64 {
	v := strings.TrimSpace(os.Getenv(key))
	if v == "" {
		return fallback
	}
	f, err := strconv.ParseFloat(v, 64)
	if err != nil {
		p.fail(key, v, err)
		return fallback
	}
	return f
}

func (p *parser) boolean(key string, fallback bool) bool {
	v := strings.ToLower(strings.TrimSpace(os.Getenv(key)))
	switch v {
	case "":
		return fallback
	case "1", "true", "yes", "on":
		return true
	case "0", "false", "no", "off":
		return false
	default:
		p.fail(key, v, errors.New("must be true or false"))
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
