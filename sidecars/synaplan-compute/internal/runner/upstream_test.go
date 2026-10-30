package runner

import (
	"strings"
	"testing"
)

func TestUpstreamProxyEnv(t *testing.T) {
	t.Setenv("EGRESS_UPSTREAM", "")
	t.Setenv("EGRESS_UPSTREAM_TOKEN", "")
	env, err := upstreamProxyEnv()
	if err != nil || env != nil {
		t.Fatalf("unset: env=%v err=%v", env, err)
	}

	t.Setenv("EGRESS_UPSTREAM", "203.0.113.8:3128")
	t.Setenv("EGRESS_UPSTREAM_TOKEN", "")
	if _, err := upstreamProxyEnv(); err == nil {
		t.Fatal("a token is required with the address")
	}

	t.Setenv("EGRESS_UPSTREAM_TOKEN", "compute-token")
	env, err = upstreamProxyEnv()
	if err != nil {
		t.Fatal(err)
	}
	if len(env) != 2 || env[0] != "EGRESS_UPSTREAM=203.0.113.8:3128" || !strings.Contains(env[1], "compute-token") {
		t.Fatalf("env %v", env)
	}
}
