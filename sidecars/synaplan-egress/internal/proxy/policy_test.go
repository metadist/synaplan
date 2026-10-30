package proxy

import "testing"

func TestMatch(t *testing.T) {
	rules := []string{"api.openai.com", "*.example.org", "files.example.com:443"}
	if !Match(rules, "api.openai.com", 8443) || !Match(rules, "a.example.org", 80) {
		t.Fatal("expected a host match")
	}
	if Match(rules, "a.b.example.org", 443) || Match(rules, "files.example.com", 80) {
		t.Fatal("wildcard depth and port must hold")
	}
}
