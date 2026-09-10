package egress

import (
	"net"
	"testing"

	"github.com/metadist/synaplan-compute/pkg/contract"
)

func TestEgressEmptyMeansNoNetwork(t *testing.T) {
	t.Parallel()
	res, err := Validate(Config{Enabled: false}, contract.Egress{Allow: nil})
	if err != nil {
		t.Fatal(err)
	}
	if !EmptyMeansNone(res.Decision) {
		t.Fatalf("NetworkMode = %q, want none", res.Decision.NetworkMode)
	}
	res, err = Validate(Config{Enabled: true}, contract.Egress{Allow: []contract.EgressHost{}})
	if err != nil {
		t.Fatal(err)
	}
	if res.Decision.NetworkMode != "none" {
		t.Fatal(res.Decision.NetworkMode)
	}
}

func TestEgressDisabledRefusesAllowList(t *testing.T) {
	t.Parallel()
	_, err := Validate(Config{Enabled: false}, contract.Egress{Allow: []contract.EgressHost{{
		Host: "api.example.com",
		Port: 443,
		IPs:  []string{"93.184.216.34"},
	}}})
	if err == nil {
		t.Fatal("expected refusal")
	}
	ref, ok := err.(*Refused)
	if !ok || ref.Code != contract.ErrEgressNotAllowed {
		t.Fatalf("got %#v", err)
	}
}

func TestProxyRefusesUnpinnedHost(t *testing.T) {
	t.Parallel()
	p := NewProxy([]contract.EgressHost{{
		Host: "api.example.com",
		Port: 443,
		IPs:  []string{"93.184.216.34"},
	}})
	ip := net.ParseIP("1.2.3.4")
	if p.Allowed("api.example.com", 443, ip) {
		t.Fatal("unpinned IP must be refused")
	}
	if p.Allowed("evil.example.com", 443, net.ParseIP("93.184.216.34")) {
		t.Fatal("wrong host must be refused")
	}
	if !p.Allowed("api.example.com", 443, net.ParseIP("93.184.216.34")) {
		t.Fatal("pinned destination must be allowed")
	}
}

func TestProxyRefusesPrivateIp(t *testing.T) {
	t.Parallel()
	_, err := Validate(Config{Enabled: true, MaxHosts: 8}, contract.Egress{Allow: []contract.EgressHost{{
		Host: "internal.example.com",
		Port: 443,
		IPs:  []string{"10.0.0.1"},
	}}})
	if err == nil {
		t.Fatal("private IP must be refused")
	}
	_, err = Validate(Config{Enabled: true}, contract.Egress{Allow: []contract.EgressHost{{
		Host: "localhost",
		Port: 80,
		IPs:  []string{"127.0.0.1"},
	}}})
	if err == nil {
		t.Fatal("loopback must be refused")
	}
	_, err = Validate(Config{Enabled: true}, contract.Egress{Allow: []contract.EgressHost{{
		Host: "api.example.com",
		Port: 443,
	}}})
	if err == nil {
		t.Fatal("missing ips must be refused")
	}
}
