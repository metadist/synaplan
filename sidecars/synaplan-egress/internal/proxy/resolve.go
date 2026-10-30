package proxy

import (
	"context"
	"net"
)

// DefaultResolve is the process resolver. Grant tunnels never call it.
func DefaultResolve(ctx context.Context, host string) ([]net.IP, error) {
	return net.DefaultResolver.LookupIP(ctx, "ip", host)
}
