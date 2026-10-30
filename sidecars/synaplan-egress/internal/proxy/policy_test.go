package proxy

import (
	"net"
	"testing"
)

func TestMatch(t *testing.T) {
	rules := []string{"api.openai.com", "*.example.org", "files.example.com:443"}
	if !Match(rules, "api.openai.com", 8443) || !Match(rules, "a.example.org", 80) {
		t.Fatal("expected a host match")
	}
	if Match(rules, "a.b.example.org", 443) || Match(rules, "files.example.com", 80) {
		t.Fatal("wildcard depth and port must hold")
	}
}

func TestPrivateIncludesSharedAndReservedRanges(t *testing.T) {
	for _, raw := range []string{"100.64.0.1", "0.0.0.1", "192.0.0.1", "198.18.0.1", "255.255.255.255"} {
		if !Private(net.ParseIP(raw)) {
			t.Fatalf("%s must be refused", raw)
		}
	}
	if Private(net.ParseIP("203.0.113.10")) {
		t.Fatal("203.0.113.10 is the public pin used in the grant tests")
	}
}
