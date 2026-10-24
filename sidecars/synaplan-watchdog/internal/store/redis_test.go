package store

import (
	"context"
	"os"
	"testing"
	"time"
)

func TestRedisRoundTripWhenReachable(t *testing.T) {
	url := os.Getenv("REDIS_URL")
	if url == "" {
		url = "redis://127.0.0.1:6379/15"
	}
	opened, err := Open(url)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	if err := opened.Ping(ctx); err != nil {
		t.Skip("redis is not reachable")
	}
	key := "capacity:server:watchdog-test"
	if err := opened.Put(ctx, key, []byte(`{"server_id":"watchdog-test"}`), 20*time.Second); err != nil {
		t.Fatal(err)
	}
	body, err := opened.Get(ctx, key)
	if err != nil {
		t.Fatal(err)
	}
	if string(body) != `{"server_id":"watchdog-test"}` {
		t.Fatalf("body = %s", body)
	}
	missing, err := opened.Get(ctx, key+"-missing")
	if err != nil || missing != nil {
		t.Fatalf("missing = %q err %v", missing, err)
	}
}
