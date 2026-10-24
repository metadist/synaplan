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
	if cfg.KeyPrefix != "synaplan:prod:" {
		t.Fatalf("prefix without APP_ENV = %q", cfg.KeyPrefix)
	}
	dev := From(func(key string) string {
		if key == "APP_ENV" {
			return "dev"
		}
		return ""
	})
	if dev.KeyPrefix != "synaplan:dev:" {
		t.Fatalf("prefix with APP_ENV=dev = %q", dev.KeyPrefix)
	}
}

func TestOnlyTheWebRoleServesPHP(t *testing.T) {
	web := From(func(string) string { return "" })
	if web.Role != "web" || !web.ServesPHP() {
		t.Fatalf("default role = %q serves=%v", web.Role, web.ServesPHP())
	}
	worker := From(func(key string) string {
		if key == "SYNAPLAN_ROLE" {
			return "worker"
		}
		return ""
	})
	if worker.Role != "worker" || worker.ServesPHP() {
		t.Fatalf("worker role = %q serves=%v", worker.Role, worker.ServesPHP())
	}
}

func TestAnExplicitIdOnAHelperRoleCarriesItsRole(t *testing.T) {
	cases := []struct{ id, role, want string }{
		{"web1", "web", "web1"},
		{"web1", "worker", "web1-worker"},
		{"web1", "scheduler", "web1-scheduler"},
		{"web1-worker", "worker", "web1-worker"},
		{"web1-bulk", "worker", "web1-bulk"},
		{"web1-scheduler", "scheduler", "web1-scheduler"},
		{"", "worker", "synaplan-backend-1"},
	}
	for _, c := range cases {
		cfg := From(func(key string) string {
			switch key {
			case "SYNAPLAN_SERVER_ID":
				return c.id
			case "SYNAPLAN_ROLE":
				return c.role
			case "HOSTNAME":
				return "synaplan-backend-1"
			default:
				return ""
			}
		})
		if cfg.ServerID != c.want {
			t.Errorf("id=%q role=%q: got %q, want %q", c.id, c.role, cfg.ServerID, c.want)
		}
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
