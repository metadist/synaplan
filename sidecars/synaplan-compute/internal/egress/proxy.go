package egress

import (
	"net"
	"strconv"
	"strings"

	"github.com/metadist/synaplan-compute/pkg/contract"
)

const networkNone = "none"

// Decision is the networking result for a run.
type Decision struct {
	NetworkMode string
	Env         []string
}

// Config is the service-level egress policy.
type Config struct {
	Enabled  bool
	MaxHosts int
}

// Result of Validate.
type Result struct {
	Decision Decision
	Hosts    []string
}

// Validate applies C4: empty allow keeps NetworkMode none. A non-empty list
// is refused when COMPUTE_EGRESS_ENABLED is false, when any entry lacks
// pinned IPs, names a private range, or exceeds MaxHosts.
func Validate(cfg Config, eg contract.Egress) (Result, error) {
	if len(eg.Allow) == 0 {
		return Result{Decision: Decision{NetworkMode: networkNone}}, nil
	}
	if !cfg.Enabled {
		return Result{}, &Refused{Code: contract.ErrEgressNotAllowed, Message: "egress is disabled"}
	}
	max := cfg.MaxHosts
	if max <= 0 {
		max = 8
	}
	if len(eg.Allow) > max {
		return Result{}, &Refused{Code: contract.ErrEgressNotAllowed, Message: "too many egress hosts"}
	}
	names := make([]string, 0, len(eg.Allow))
	for _, h := range eg.Allow {
		if err := checkHost(h); err != nil {
			return Result{}, err
		}
		names = append(names, h.Host)
	}
	return Result{
		Decision: Decision{
			NetworkMode: "compute-egress",
			Env: []string{
				"HTTP_PROXY=http://172.18.0.1:3128",
				"HTTPS_PROXY=http://172.18.0.1:3128",
				"NO_PROXY=",
			},
		},
		Hosts: names,
	}, nil
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

func privateIP(ip net.IP) bool {
	return ip.IsLoopback() || ip.IsPrivate() || ip.IsUnspecified() ||
		ip.IsMulticast() || ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast()
}

// Refused is a 400 egress_not_allowed.
type Refused struct {
	Code    string
	Message string
}

func (e *Refused) Error() string { return e.Message }

// Proxy is a CONNECT proxy that dials only pinned IPs. Empty allow is not
// attached; NetworkMode stays none.
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

// EmptyMeansNone reports whether the decision is isolated.
func EmptyMeansNone(d Decision) bool {
	return d.NetworkMode == networkNone
}
