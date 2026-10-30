package proxy

import (
	"net"
	"strconv"
	"strings"
)

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
	return ip.IsLoopback() || ip.IsPrivate() || ip.IsUnspecified() ||
		ip.IsMulticast() || ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast()
}
