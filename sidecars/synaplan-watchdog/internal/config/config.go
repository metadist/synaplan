// Package config reads the watchdog's environment.
package config

import (
	"os"
	"strings"
	"time"
)

// Config is everything the process reads at boot.
type Config struct {
	Listen   string
	ServerID string
	Role     string
	// KeyPrefix is `synaplan:<APP_ENV>:`, the application's Redis namespace.
	KeyPrefix   string
	Version     string
	RedisURL    string
	AdminURL    string
	PHPReadyURL string
	Interval    time.Duration
	HostRoot    string
}

// FromEnv reads the process environment.
func FromEnv() Config {
	return From(os.Getenv)
}

// From reads an injected environment, used by tests.
func From(env func(string) string) Config {
	role := first(env("SYNAPLAN_ROLE"), "web")
	cfg := Config{
		Listen:      ":8098",
		ServerID:    serverID(env, role),
		Role:        role,
		KeyPrefix:   keyPrefix(env("APP_ENV")),
		Version:     first(env("APP_VERSION"), "dev"),
		RedisURL:    first(env("REDIS_URL"), env("REDIS_DSN")),
		AdminURL:    first(env("WATCHDOG_ADMIN_URL"), "http://127.0.0.1:2019"),
		PHPReadyURL: first(env("WATCHDOG_PHP_READY_URL"), "http://127.0.0.1/api/health/ready"),
		Interval:    5 * time.Second,
		HostRoot:    env("WATCHDOG_HOST_ROOT"),
	}
	if raw := env("WATCHDOG_INTERVAL"); raw != "" {
		if parsed, err := time.ParseDuration(raw); err == nil && parsed > 0 {
			cfg.Interval = parsed
		}
	}
	return cfg
}

// ServesPHP is true for the role that runs FrankenPHP. The other roles have
// no admin API and no readiness URL to probe.
func (c Config) ServesPHP() bool {
	return c.Role == "web"
}

// keyPrefix mirrors RedisService's `synaplan:<env>:`. An unset APP_ENV is
// prod, which is what the image runs when nothing says otherwise.
func keyPrefix(appEnv string) string {
	appEnv = sanitize(appEnv)
	if appEnv == "" {
		appEnv = "prod"
	}
	return "synaplan:" + appEnv + ":"
}

// roleSuffixes are the ids a compose file gives the roles that share one
// machine with the web container (platform: web1-worker, web1-bulk, …).
var roleSuffixes = []string{"-worker", "-bulk", "-scheduler"}

// serverID is SYNAPLAN_SERVER_ID, else the hostname. Several containers on
// one machine often load the same env file, so an explicit id on a worker
// or the scheduler gets its role appended unless the id already carries
// one; otherwise two roles would overwrite one snapshot in turns.
func serverID(env func(string) string, role string) string {
	explicit := sanitize(env("SYNAPLAN_SERVER_ID"))
	if explicit == "" {
		hostname := sanitize(env("HOSTNAME"))
		if hostname == "" {
			return "synaplan"
		}
		return hostname
	}
	if role == "web" || hasRoleSuffix(explicit) {
		return explicit
	}
	return explicit + "-" + sanitize(role)
}

func hasRoleSuffix(id string) bool {
	for _, suffix := range roleSuffixes {
		if strings.HasSuffix(id, suffix) && len(id) > len(suffix) {
			return true
		}
	}
	return false
}

func sanitize(raw string) string {
	var b strings.Builder
	for _, r := range raw {
		switch {
		case r >= 'a' && r <= 'z', r >= 'A' && r <= 'Z', r >= '0' && r <= '9', r == '.', r == '_', r == '-':
			b.WriteRune(r)
		}
	}
	return b.String()
}

func first(values ...string) string {
	for _, value := range values {
		if value != "" {
			return value
		}
	}
	return ""
}
