// Package egress holds the per-run network allow-list policy.
//
// Phases A0–A2 do not implement egress: there is no proxy listener and the
// runner never selects a NetworkMode other than none. Validate therefore
// fails closed on any non-empty allow-list. CheckAllowList and Proxy are the
// future policy (pinned IPs, no private ranges, host cap) and are kept under
// test so the contract does not drift before the proxy exists.
package egress

import (
	"net"
	"strconv"
	"strings"

	"github.com/metadist/synaplan-compute/pkg/contract"
)

// Config is the service-level egress policy. Enabled is reserved: until a
// proxy ships it does not open the network.
type Config struct {
	Enabled  bool
	MaxHosts int
}

// Validate is what POST /v1/runs applies: an empty allow-list is the only
// accepted value. Any entry is refused with egress_not_allowed.
func Validate(_ Config, eg contract.Egress) error {
	if len(eg.Allow) == 0 {
		return nil
	}
	return &Refused{Code: contract.ErrEgressNotAllowed, Message: "egress is not available yet; every run is network-isolated"}
}

// CheckAllowList is the future policy for a non-empty list: refused when the
// feature is disabled, when any entry lacks pinned IPs, names a private or
// special-purpose range, or exceeds MaxHosts. It returns the host names.
func CheckAllowList(cfg Config, eg contract.Egress) ([]string, error) {
	if len(eg.Allow) == 0 {
		return nil, nil
	}
	if !cfg.Enabled {
		return nil, &Refused{Code: contract.ErrEgressNotAllowed, Message: "egress is disabled"}
	}
	max := cfg.MaxHosts
	if max <= 0 {
		max = 8
	}
	if len(eg.Allow) > max {
		return nil, &Refused{Code: contract.ErrEgressNotAllowed, Message: "too many egress hosts"}
	}
	names := make([]string, 0, len(eg.Allow))
	for _, h := range eg.Allow {
		if err := checkHost(h); err != nil {
			return nil, err
		}
		names = append(names, h.Host)
	}
	return names, nil
}

func checkHost(h contract.EgressHost) error {
	if h.Host == "" || h.Port <= 0 || h.Port > 65535 {
		return &Refused{Code: contract.ErrEgressNotAllowed, Message: "egress host, port, and ips are required"}
	}
	if len(h.IPs) == 0 {
		return &Refused{Code: contract.ErrEgressNotAllowed, Message: "egress entry must include pinned ips"}
	}
	for _, s := range h.IPs {
		ip := net.ParseIP(strings.TrimSpace(s))
		if ip == nil {
			return &Refused{Code: contract.ErrEgressNotAllowed, Message: "invalid egress ip"}
		}
		if privateIP(ip) {
			return &Refused{Code: contract.ErrEgressNotAllowed, Message: "private egress ip refused"}
		}
	}
	return nil
}

// specialRanges are IPv4 blocks that net.IP predicates do not cover: shared
// address space (CGNAT), "this" network, IETF protocol assignments,
// benchmarking, and limited broadcast.
var specialRanges = mustCIDRs(
	"100.64.0.0/10",
	"0.0.0.0/8",
	"192.0.0.0/24",
	"198.18.0.0/15",
	"255.255.255.255/32",
)

func mustCIDRs(cidrs ...string) []*net.IPNet {
	out := make([]*net.IPNet, 0, len(cidrs))
	for _, c := range cidrs {
		_, n, err := net.ParseCIDR(c)
		if err != nil {
			panic(err)
		}
		out = append(out, n)
	}
	return out
}

func privateIP(ip net.IP) bool {
	if ip.IsLoopback() || ip.IsPrivate() || ip.IsUnspecified() ||
		ip.IsMulticast() || ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast() ||
		ip.IsInterfaceLocalMulticast() {
		return true
	}
	for _, n := range specialRanges {
		if n.Contains(ip) {
			return true
		}
	}
	return false
}

// Refused is a 400 egress_not_allowed.
type Refused struct {
	Code    string
	Message string
}

func (e *Refused) Error() string { return e.Message }

// Proxy is the pin map a future CONNECT proxy dials from. It has no listener
// in A0–A2.
type Proxy struct {
	allow map[string][]net.IP
}

// NewProxy builds a pin map. Unknown hosts get 403 and the caller should
// emit audit event egress.refused.
func NewProxy(allow []contract.EgressHost) *Proxy {
	m := make(map[string][]net.IP)
	for _, h := range allow {
		key := net.JoinHostPort(h.Host, strconv.Itoa(h.Port))
		for _, s := range h.IPs {
			if ip := net.ParseIP(s); ip != nil {
				m[key] = append(m[key], ip)
			}
		}
	}
	return &Proxy{allow: m}
}

// Allowed reports whether host:port may be dialed to ip.
func (p *Proxy) Allowed(host string, port int, ip net.IP) bool {
	if p == nil {
		return false
	}
	key := net.JoinHostPort(host, strconv.Itoa(port))
	for _, pinned := range p.allow[key] {
		if pinned.Equal(ip) {
			return true
		}
	}
	return false
}
