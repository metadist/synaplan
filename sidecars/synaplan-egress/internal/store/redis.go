// Package store reads the allowlist and one-shot grants PHP writes to Redis.
package store

import (
	"bufio"
	"context"
	"fmt"
	"io"
	"net"
	"strings"
	"time"

	"github.com/metadist/synaplan-egress/internal/proxy"
)

// Redis speaks the two commands the gateway needs. GETDEL removes a grant
// so it cannot be reused. prefix is the same string PHP's RedisService
// puts in front of every key (synaplan:<env>:).
type Redis struct {
	addr   string
	prefix string
}

// Open checks that the address is usable. The connection is per command.
func Open(addr, prefix string) (*Redis, error) {
	if addr == "" {
		return nil, fmt.Errorf("redis address is empty")
	}
	return &Redis{addr: addr, prefix: prefix}, nil
}

func (r *Redis) Rules(ctx context.Context) ([]string, error) {
	body, ok, err := r.command(ctx, "GET", r.prefix+"egress:allow")
	if err != nil || !ok || body == "" {
		return nil, err
	}
	return strings.Split(body, "\n"), nil
}

func (r *Redis) Take(ctx context.Context, id string) (proxy.Grant, bool, error) {
	if id == "" || strings.ContainsAny(id, "\r\n") {
		return proxy.Grant{}, false, nil
	}
	body, ok, err := r.command(ctx, "GETDEL", r.prefix+"egress:grant:"+id)
	if err != nil || !ok {
		return proxy.Grant{}, false, err
	}
	grant, err := proxy.DecodeGrant(body)
	if err != nil {
		return proxy.Grant{}, false, nil
	}
	return grant, true, nil
}

func (r *Redis) command(ctx context.Context, args ...string) (string, bool, error) {
	dial, err := (&net.Dialer{}).DialContext(ctx, "tcp", r.addr)
	if err != nil {
		return "", false, err
	}
	defer func() { _ = dial.Close() }()
	_ = dial.SetDeadline(time.Now().Add(2 * time.Second))
	if _, err := fmt.Fprint(dial, encode(args)); err != nil {
		return "", false, err
	}
	return readReply(bufio.NewReader(dial))
}

func readReply(reader *bufio.Reader) (string, bool, error) {
	line, err := reader.ReadString('\n')
	if err != nil {
		return "", false, err
	}
	if strings.HasPrefix(line, "$-1") {
		return "", false, nil
	}
	if !strings.HasPrefix(line, "$") {
		return "", false, fmt.Errorf("redis: %s", strings.TrimSpace(line))
	}
	var size int
	if _, err := fmt.Sscanf(line, "$%d", &size); err != nil || size < 0 {
		return "", false, fmt.Errorf("redis: bad bulk length")
	}
	buf := make([]byte, size+2)
	if _, err := io.ReadFull(reader, buf); err != nil {
		return "", false, err
	}
	if string(buf[size:]) != "\r\n" {
		return "", false, fmt.Errorf("redis: bulk trailer")
	}
	return string(buf[:size]), true, nil
}

func encode(args []string) string {
	var b strings.Builder
	fmt.Fprintf(&b, "*%d\r\n", len(args))
	for _, arg := range args {
		fmt.Fprintf(&b, "$%d\r\n%s\r\n", len(arg), arg)
	}
	return b.String()
}
