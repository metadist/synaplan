package store

import (
	"context"
	"fmt"
	"io"
	"net"
	"strings"
	"testing"
)

func TestTakeDeletesTheGrant(t *testing.T) {
	body := `{"host":"customer.example","port":443,"ips":["203.0.113.9"]}`
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	command := make(chan string, 1)
	go func() {
		conn, err := ln.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		buf := make([]byte, 256)
		n, _ := conn.Read(buf)
		command <- string(buf[:n])
		_, _ = io.WriteString(conn, fmt.Sprintf("$%d\r\n%s\r\n", len(body), body))
	}()

	redis, err := Open(ln.Addr().String(), "synaplan:test:")
	if err != nil {
		t.Fatal(err)
	}
	grant, ok, err := redis.Take(context.Background(), "abc")
	if err != nil || !ok || grant.Host != "customer.example" || grant.IPs[0] != "203.0.113.9" {
		t.Fatalf("grant %+v ok=%v err=%v", grant, ok, err)
	}
	got := <-command
	if !strings.Contains(got, "GETDEL") || !strings.Contains(got, "synaplan:test:egress:grant:abc") {
		t.Fatalf("command %q", got)
	}
}
