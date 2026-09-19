package egress

import (
	"bufio"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/metadist/synaplan-compute/pkg/contract"
)

func allowList() []contract.EgressHost {
	return []contract.EgressHost{
		{Host: "example.com", Port: 443, IPs: []string{"93.184.216.34"}},
		{Host: "data.example.org", Port: 80, IPs: []string{"93.184.216.35"}},
	}
}

func TestConnectUnknownHostRefused(t *testing.T) {
	t.Parallel()
	var refused []string
	srv := NewServer(allowList(), Option{OnRefused: func(host string, port int, reason string) {
		refused = append(refused, reason)
	}})
	req := httptest.NewRequest(http.MethodConnect, "evil.com:443", nil)
	req.Host = "evil.com:443"
	rec := httptest.NewRecorder()
	srv.ServeHTTP(rec, req)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("code = %d, want 403", rec.Code)
	}
	if len(refused) != 1 || refused[0] != "unknown_host" {
		t.Fatalf("refused = %v, want [unknown_host]", refused)
	}
}

func TestConnectIPLiteralRefused(t *testing.T) {
	t.Parallel()
	var refused []string
	srv := NewServer(allowList(), Option{OnRefused: func(host string, port int, reason string) {
		refused = append(refused, reason)
	}})
	// Even a pinned IP must be named by its host; literals never dial.
	req := httptest.NewRequest(http.MethodConnect, "93.184.216.34:443", nil)
	req.Host = "93.184.216.34:443"
	rec := httptest.NewRecorder()
	srv.ServeHTTP(rec, req)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("code = %d, want 403", rec.Code)
	}
	if len(refused) != 1 || refused[0] != "ip_literal" {
		t.Fatalf("refused = %v, want [ip_literal]", refused)
	}
}

func TestConnectPrivatePinRefused(t *testing.T) {
	t.Parallel()
	srv := NewServer([]contract.EgressHost{
		{Host: "internal.example", Port: 443, IPs: []string{"10.9.0.4"}},
	}, Option{})
	req := httptest.NewRequest(http.MethodConnect, "internal.example:443", nil)
	req.Host = "internal.example:443"
	rec := httptest.NewRecorder()
	srv.ServeHTTP(rec, req)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("code = %d, want 403", rec.Code)
	}
}

func TestConnectWrongPortRefused(t *testing.T) {
	t.Parallel()
	srv := NewServer(allowList(), Option{})
	req := httptest.NewRequest(http.MethodConnect, "example.com:8443", nil)
	req.Host = "example.com:8443"
	rec := httptest.NewRecorder()
	srv.ServeHTTP(rec, req)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("code = %d, want 403", rec.Code)
	}
}

func TestForwardRejectsBadMethod(t *testing.T) {
	t.Parallel()
	srv := NewServer(allowList(), Option{})
	req := httptest.NewRequest(http.MethodTrace, "http://data.example.org/", nil)
	rec := httptest.NewRecorder()
	srv.ServeHTTP(rec, req)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("code = %d, want 403", rec.Code)
	}
}

func TestNonProxyRequestRejected(t *testing.T) {
	t.Parallel()
	srv := NewServer(allowList(), Option{})
	req := httptest.NewRequest(http.MethodGet, "/local-path", nil)
	rec := httptest.NewRecorder()
	srv.ServeHTTP(rec, req)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("code = %d, want 400", rec.Code)
	}
}

func TestRelayEchoesBothDirections(t *testing.T) {
	t.Parallel()
	srv := NewServer(nil, Option{})
	client, serverEnd := net.Pipe()
	upstream, farEnd := net.Pipe()
	defer client.Close()
	defer upstream.Close()
	defer farEnd.Close()
	go func() {
		buf := make([]byte, 64)
		for {
			n, err := farEnd.Read(buf)
			if err != nil {
				return
			}
			_, _ = farEnd.Write(append([]byte("echo:"), buf[:n]...))
		}
	}()
	done := make(chan struct{})
	go func() {
		defer close(done)
		srv.relay(t.Context(), "example.com", 443, serverEnd, bufio.NewReader(serverEnd), upstream)
	}()
	if _, err := client.Write([]byte("ping")); err != nil {
		t.Fatal(err)
	}
	_ = client.SetReadDeadline(time.Now().Add(5 * time.Second))
	got := make([]byte, len("echo:ping"))
	if _, err := io.ReadFull(client, got); err != nil {
		t.Fatal(err)
	}
	if string(got) != "echo:ping" {
		t.Fatalf("got %q, want echo:ping", got)
	}
	_ = client.Close()
	_ = farEnd.Close()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("relay did not finish")
	}
}

func TestRelayEnforcesBodyCap(t *testing.T) {
	t.Parallel()
	var cuts []string
	srv := NewServer(nil, Option{
		MaxBodyBytes: 8,
		OnRefused: func(host string, port int, reason string) {
			cuts = append(cuts, reason)
		},
	})
	client, serverEnd := net.Pipe()
	upstream, farEnd := net.Pipe()
	defer client.Close()
	defer upstream.Close()
	defer farEnd.Close()
	go func() {
		_, _ = farEnd.Write([]byte("0123456789abcdef"))
	}()
	done := make(chan struct{})
	go func() {
		defer close(done)
		srv.relay(t.Context(), "example.com", 443, serverEnd, bufio.NewReader(serverEnd), upstream)
	}()
	_ = client.SetReadDeadline(time.Now().Add(300 * time.Millisecond))
	got, _ := io.ReadAll(client)
	_ = client.Close()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("relay did not finish")
	}
	if len(got) > 8 {
		t.Fatalf("relayed %d bytes past an 8-byte cap", len(got))
	}
	if len(cuts) != 1 || cuts[0] != "body_cap" {
		t.Fatalf("cuts = %v, want [body_cap]", cuts)
	}
}

func TestPinsLookup(t *testing.T) {
	t.Parallel()
	p := NewProxy(allowList())
	if got := p.Pins("example.com", 443); len(got) != 1 || got[0].String() != "93.184.216.34" {
		t.Fatalf("pins = %v", got)
	}
	if got := p.Pins("example.com", 80); got != nil {
		t.Fatalf("pins for wrong port = %v, want nil", got)
	}
	if got := p.Pins("evil.com", 443); got != nil {
		t.Fatalf("pins for unknown host = %v, want nil", got)
	}
}
