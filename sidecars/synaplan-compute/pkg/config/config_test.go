package config

import (
	"testing"
)

func TestLoadRejectsShortToken(t *testing.T) {
	t.Setenv("COMPUTE_AUTH_TOKEN", "short")
	if _, err := Load(); err == nil {
		t.Fatal("expected error")
	}
}

func TestLoadDefaults(t *testing.T) {
	t.Setenv("COMPUTE_AUTH_TOKEN", "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA")
	t.Setenv("COMPUTE_EGRESS_ENABLED", "")
	c, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if c.EgressEnabled {
		t.Fatal("egress default false")
	}
	if c.MaxTimeoutSec != 300 || c.MaxMemoryMb != 2048 {
		t.Fatalf("%+v", c)
	}
}
