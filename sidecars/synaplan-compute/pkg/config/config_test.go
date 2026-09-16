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
	if c.SandboxUID != 65534 || c.SandboxGID != 65534 || c.SandboxUser() != "65534:65534" {
		t.Fatalf("sandbox user default: %+v", c)
	}
}

func TestLoadFailsOnMalformedValues(t *testing.T) {
	t.Setenv("COMPUTE_AUTH_TOKEN", "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA")
	cases := map[string]string{
		"COMPUTE_MAX_TIMEOUT_SEC": "ten",
		"COMPUTE_MAX_CPU":         "two",
		"COMPUTE_EGRESS_ENABLED":  "maybe",
		"COMPUTE_SANDBOX_UID":     "0",
		"COMPUTE_MAX_CONCURRENT":  "0",
		"COMPUTE_QUEUE_MAX":       "-1",
	}
	for key, val := range cases {
		t.Run(key, func(t *testing.T) {
			t.Setenv(key, val)
			if _, err := Load(); err == nil {
				t.Fatalf("%s=%q must fail startup", key, val)
			}
		})
	}
}

func TestLoadSandboxUserOverride(t *testing.T) {
	t.Setenv("COMPUTE_AUTH_TOKEN", "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA")
	t.Setenv("COMPUTE_SANDBOX_UID", "70000")
	t.Setenv("COMPUTE_SANDBOX_GID", "70001")
	c, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if c.SandboxUser() != "70000:70001" {
		t.Fatal(c.SandboxUser())
	}
}
