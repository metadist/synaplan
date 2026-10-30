package proxy

import (
	"bufio"
	"bytes"
	"context"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

type memoryStore struct {
	rules []string
	grant map[string]Grant
}

func (m *memoryStore) Rules(context.Context) ([]string, error) { return m.rules, nil }

func (m *memoryStore) Take(_ context.Context, id string) (Grant, bool, error) {
	grant, ok := m.grant[id]
	delete(m.grant, id)
	return grant, ok, nil
}

func TestAllowlistedNameDialsTheResolvedAddress(t *testing.T) {
	var dialed []string
	server := testServer(t, &memoryStore{rules: []string{"api.openai.com"}}, func(_ context.Context, _, address string) (net.Conn, error) {
		dialed = append(dialed, address)
		a, b := net.Pipe()
		go func() { _, _ = io.Copy(io.Discard, b) }()
		return a, nil
	}, func(context.Context, string) ([]net.IP, error) {
		return []net.IP{net.ParseIP("203.0.113.10"), net.ParseIP("10.1.1.1")}, nil
	})
	status := connect(t, server, "api.openai.com:443", "")
	if status != http.StatusOK {
		t.Fatalf("status %d", status)
	}
	if len(dialed) != 1 || dialed[0] != "203.0.113.10:443" {
		t.Fatalf("dialed %v, want only the public pin", dialed)
	}
}

func TestUnknownHostAndAPrivateAnswerAreRefused(t *testing.T) {
	server := testServer(t, &memoryStore{rules: []string{"api.openai.com"}}, dialFail, func(context.Context, string) ([]net.IP, error) {
		return []net.IP{net.ParseIP("10.1.1.1")}, nil
	})
	if status := connect(t, server, "evil.example:443", ""); status != http.StatusForbidden {
		t.Fatalf("unknown status %d", status)
	}
	if status := connect(t, server, "api.openai.com:443", ""); status != http.StatusForbidden {
		t.Fatalf("private status %d", status)
	}
}

func TestGrantIsSingleUseAndDoesNotResolve(t *testing.T) {
	var dialed []string
	var lines bytes.Buffer
	store := &memoryStore{grant: map[string]Grant{
		"grant-1": {Host: "customer.example", Port: 443, IPs: []string{"203.0.113.20"}},
	}}
	server := testServer(t, store, func(_ context.Context, _, address string) (net.Conn, error) {
		dialed = append(dialed, address)
		a, b := net.Pipe()
		go func() { _, _ = io.Copy(io.Discard, b) }()
		return a, nil
	}, func(context.Context, string) ([]net.IP, error) {
		t.Fatal("a grant must not resolve the name")
		return nil, nil
	})
	server.Log = log.New(&lines, "", 0)

	if status := connect(t, server, "customer.example:443", "Bearer grant-1"); status != http.StatusOK {
		t.Fatalf("first status %d", status)
	}
	if status := connect(t, server, "customer.example:443", "Bearer grant-1"); status != http.StatusForbidden {
		t.Fatalf("reuse status %d", status)
	}
	if status := connect(t, server, "other.example:443", "Bearer missing"); status != http.StatusForbidden {
		t.Fatalf("foreign status %d", status)
	}
	if len(dialed) != 1 || dialed[0] != "203.0.113.20:443" {
		t.Fatalf("dialed %v", dialed)
	}
	if strings.Contains(lines.String(), "grant-1") || strings.Contains(lines.String(), "Bearer") {
		t.Fatalf("log leaked the grant: %s", lines.String())
	}
}

func TestGrantMismatchAndPrivatePinDoNotDial(t *testing.T) {
	var dialed int
	store := &memoryStore{grant: map[string]Grant{
		"wrong-host": {Host: "customer.example", Port: 443, IPs: []string{"203.0.113.20"}},
		"private":    {Host: "customer.example", Port: 443, IPs: []string{"127.0.0.1"}},
	}}
	server := testServer(t, store, func(context.Context, string, string) (net.Conn, error) {
		dialed++
		return nil, io.EOF
	}, nil)
	if status := connect(t, server, "other.example:443", "Bearer wrong-host"); status != http.StatusForbidden {
		t.Fatalf("mismatch status %d", status)
	}
	if status := connect(t, server, "customer.example:443", "Bearer private"); status != http.StatusForbidden {
		t.Fatalf("private status %d", status)
	}
	if dialed != 0 {
		t.Fatalf("dialed %d times", dialed)
	}
}

func TestUpstreamConnectUsesThePinnedAddress(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	got := make(chan string, 1)
	go func() {
		conn, err := ln.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		line, _ := bufio.NewReader(conn).ReadString('\n')
		got <- strings.TrimSpace(line)
		_, _ = io.WriteString(conn, "HTTP/1.1 200 Connection established\r\n\r\n")
		_, _ = io.Copy(io.Discard, conn)
	}()
	store := &memoryStore{grant: map[string]Grant{
		"grant-1": {Host: "customer.example", Port: 443, IPs: []string{"2001:db8::10"}},
	}}
	server := testServer(t, store, func(context.Context, string, string) (net.Conn, error) {
		return net.Dial("tcp", ln.Addr().String())
	}, nil)
	server.Upstream = "proxy.internal:9"
	if status := connect(t, server, "customer.example:443", "Bearer grant-1"); status != http.StatusOK {
		t.Fatalf("status %d", status)
	}
	if line := <-got; line != "CONNECT [2001:db8::10]:443 HTTP/1.1" {
		t.Fatalf("upstream line %q", line)
	}
}

func TestUpstreamRefusalIsLoggedWithoutTheGrant(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	go func() {
		conn, err := ln.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		_, _ = bufio.NewReader(conn).ReadString('\n')
		_, _ = io.WriteString(conn, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n")
	}()
	var lines bytes.Buffer
	store := &memoryStore{grant: map[string]Grant{
		"secret-grant": {Host: "customer.example", Port: 443, IPs: []string{"203.0.113.10"}},
	}}
	server := testServer(t, store, func(context.Context, string, string) (net.Conn, error) {
		return net.Dial("tcp", ln.Addr().String())
	}, nil)
	server.Upstream = "proxy.internal:9"
	server.Log = log.New(&lines, "", 0)
	if status := connect(t, server, "customer.example:443", "Bearer secret-grant"); status != http.StatusBadGateway {
		t.Fatalf("status %d", status)
	}
	if !strings.Contains(lines.String(), "reason=upstream_refused") || strings.Contains(lines.String(), "secret-grant") {
		t.Fatalf("log %q", lines.String())
	}
}

func testServer(t *testing.T, store Store, dial DialFunc, resolve ResolveFunc) *Server {
	t.Helper()
	return &Server{Store: store, Dial: dial, Resolve: resolve, Log: log.New(io.Discard, "", 0)}
}

func connect(t *testing.T, handler http.Handler, target, auth string) int {
	t.Helper()
	proxy := httptest.NewServer(handler)
	t.Cleanup(proxy.Close)
	conn, err := net.Dial("tcp", proxy.Listener.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	fmt.Fprintf(conn, "CONNECT %s HTTP/1.1\r\nHost: %s\r\n", target, target)
	if auth != "" {
		fmt.Fprintf(conn, "Proxy-Authorization: %s\r\n", auth)
	}
	fmt.Fprint(conn, "\r\n")
	resp, err := http.ReadResponse(bufio.NewReader(conn), nil)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	return resp.StatusCode
}

func dialFail(context.Context, string, string) (net.Conn, error) {
	return nil, io.EOF
}
