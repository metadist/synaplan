package config

import "testing"

func TestFromEnvFallsBackToRedisDSN(t *testing.T) {
	cfg := From(func(key string) string {
		switch key {
		case "REDIS_DSN":
			return "redis://redis:6379"
		case "HOSTNAME":
			return "synaplan-backend-1"
		default:
			return ""
		}
	})
	if cfg.RedisURL != "redis://redis:6379" {
		t.Fatal(cfg.RedisURL)
	}
	if cfg.ServerID != "synaplan-backend-1" || cfg.Listen != ":8098" || cfg.PHPReadyURL != "http://127.0.0.1/api/health/ready" {
		t.Fatalf("%+v", cfg)
	}
}

func TestServerIDDropsUnsafeCharacters(t *testing.T) {
	cfg := From(func(key string) string {
		if key == "SYNAPLAN_SERVER_ID" {
			return "web 1:evil"
		}
		return ""
	})
	if cfg.ServerID != "web1evil" {
		t.Fatal(cfg.ServerID)
	}
}
