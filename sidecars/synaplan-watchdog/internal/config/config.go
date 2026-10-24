// Package config reads the watchdog's environment.
package config

import (
	"os"
	"strings"
	"time"
)

// Config is everything the process reads at boot.
type Config struct {
	Listen      string
	ServerID    string
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
	cfg := Config{
		Listen:      ":8098",
		ServerID:    serverID(env),
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

func serverID(env func(string) string) string {
	raw := first(env("SYNAPLAN_SERVER_ID"), env("HOSTNAME"))
	safe := sanitize(raw)
	if safe == "" {
		return "synaplan"
	}
	return safe
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
