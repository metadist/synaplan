package egress

import (
	"net"
	"testing"

	"github.com/metadist/synaplan-compute/pkg/contract"
)

func TestEgressEmptyMeansNoNetwork(t *testing.T) {
	t.Parallel()
	if err := Validate(Config{Enabled: false}, contract.Egress{Allow: nil}); err != nil {
		t.Fatal(err)
	}
	if err := Validate(Config{Enabled: true}, contract.Egress{Allow: []contract.EgressHost{}}); err != nil {
		t.Fatal(err)
	}
}

func TestEgressFailsClosedEvenWhenEnabled(t *testing.T) {
	t.Parallel()
	entry := contract.Egress{Allow: []contract.EgressHost{{
		Host: "api.example.com",
		Port: 443,
		IPs:  []string{"93.184.216.34"},
	}}}
	for _, cfg := range []Config{{Enabled: false}, {Enabled: true, MaxHosts: 8}} {
		err := Validate(cfg, entry)
		if err == nil {
			t.Fatalf("enabled=%v: any allow-list must be refused until a proxy exists", cfg.Enabled)
		}
		ref, ok := err.(*Refused)
		if !ok || ref.Code != contract.ErrEgressNotAllowed {
			t.Fatalf("got %#v", err)
		}
	}
}

func TestEgressDisabledRefusesAllowList(t *testing.T) {
	t.Parallel()
	_, err := CheckAllowList(Config{Enabled: false}, contract.Egress{Allow: []contract.EgressHost{{
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
	hosts, err := CheckAllowList(Config{Enabled: true}, contract.Egress{Allow: []contract.EgressHost{{
		Host: "api.example.com",
		Port: 443,
		IPs:  []string{"93.184.216.34"},
	}}})
	if err != nil || len(hosts) != 1 || hosts[0] != "api.example.com" {
		t.Fatalf("hosts=%v err=%v", hosts, err)
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
	refused := []string{
		"10.0.0.1", "172.16.5.5", "192.168.1.1", "127.0.0.1", "0.0.0.0", "0.1.2.3",
		"169.254.169.254", "224.0.0.1", "100.64.0.1", "100.127.255.254",
		"192.0.0.8", "198.18.0.1", "198.19.255.255", "255.255.255.255",
		"::1", "fe80::1", "fc00::1", "ff02::1",
	}
	for _, ip := range refused {
		_, err := CheckAllowList(Config{Enabled: true, MaxHosts: 8}, contract.Egress{Allow: []contract.EgressHost{{
			Host: "internal.example.com",
			Port: 443,
			IPs:  []string{ip},
		}}})
		if err == nil {
			t.Fatalf("%s must be refused", ip)
		}
	}
	for _, ip := range []string{"93.184.216.34", "8.8.8.8", "100.128.0.1", "198.20.0.1", "2606:2800:220:1:248:1893:25c8:1946"} {
		_, err := CheckAllowList(Config{Enabled: true, MaxHosts: 8}, contract.Egress{Allow: []contract.EgressHost{{
			Host: "public.example.com",
			Port: 443,
			IPs:  []string{ip},
		}}})
		if err != nil {
			t.Fatalf("%s is public and must pass: %v", ip, err)
		}
	}
	_, err := CheckAllowList(Config{Enabled: true}, contract.Egress{Allow: []contract.EgressHost{{
		Host: "api.example.com",
		Port: 443,
	}}})
	if err == nil {
		t.Fatal("missing ips must be refused")
	}
	many := make([]contract.EgressHost, 9)
	for i := range many {
		many[i] = contract.EgressHost{Host: "h.example.com", Port: 443, IPs: []string{"93.184.216.34"}}
	}
	if _, err := CheckAllowList(Config{Enabled: true, MaxHosts: 8}, contract.Egress{Allow: many}); err == nil {
		t.Fatal("more than MaxHosts must be refused")
	}
}
