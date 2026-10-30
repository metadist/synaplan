package proxy

import (
	"net"
	"strconv"
	"strings"
)

// specialRanges are IPv4 blocks net.IP does not treat as private: shared
// address space, "this" network, IETF protocol assignments, benchmarking,
// and the limited broadcast. Same set as the compute proxy.
var specialRanges = mustCIDRs(
	"100.64.0.0/10",
	"0.0.0.0/8",
	"192.0.0.0/24",
	"198.18.0.0/15",
	"255.255.255.255/32",
)

func mustCIDRs(cidrs ...string) []*net.IPNet {
	out := make([]*net.IPNet, 0, len(cidrs))
	for _, cidr := range cidrs {
		_, network, err := net.ParseCIDR(cidr)
		if err != nil {
			panic(err)
		}
		out = append(out, network)
	}
	return out
}

// Match reports whether host:port is on a rule list. A rule is a host, a
// host:port, a one-label wildcard (*.example.org), or a suffix (.example.org).
func Match(rules []string, host string, port int) bool {
	host = strings.ToLower(strings.TrimSuffix(strings.TrimSpace(host), "."))
	for _, raw := range rules {
		raw = strings.ToLower(strings.TrimSpace(raw))
		if raw == "" {
			continue
		}
		rulePort := 0
		if i := strings.LastIndex(raw, ":"); i > 0 && !strings.HasPrefix(raw, "[") {
			parsed, err := strconv.Atoi(raw[i+1:])
			if err != nil {
				continue
			}
			rulePort = parsed
			raw = raw[:i]
		}
		if rulePort != 0 && rulePort != port {
			continue
		}
		switch {
		case strings.HasPrefix(raw, "*."):
			suffix := raw[1:]
			label := strings.TrimSuffix(host, suffix)
			if label != host && label != "" && !strings.Contains(label, ".") {
				return true
			}
		case strings.HasPrefix(raw, "."):
			if host == raw[1:] || strings.HasSuffix(host, raw) {
				return true
			}
		default:
			if host == raw {
				return true
			}
		}
	}
	return false
}

func Private(ip net.IP) bool {
	if ip.IsLoopback() || ip.IsPrivate() || ip.IsUnspecified() ||
		ip.IsMulticast() || ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast() ||
		ip.IsInterfaceLocalMulticast() {
		return true
	}
	for _, network := range specialRanges {
		if network.Contains(ip) {
			return true
		}
	}
	return false
}
