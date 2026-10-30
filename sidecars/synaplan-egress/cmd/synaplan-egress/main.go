// Command synaplan-egress is the optional installation CONNECT proxy.
// It does not terminate TLS.
package main

import (
	"context"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/metadist/synaplan-egress/internal/proxy"
	"github.com/metadist/synaplan-egress/internal/store"
)

func main() {
	log.SetFlags(0)
	addr := getenv("EGRESS_LISTEN", ":3128")
	redisAddr := getenv("REDIS_ADDR", "redis:6379")
	client, err := store.Open(redisAddr, os.Getenv("REDIS_KEY_PREFIX"))
	if err != nil {
		log.Fatalf("egress: redis: %v", err)
	}
	server := &proxy.Server{
		Store:    client,
		Upstream: os.Getenv("UPSTREAM_PROXY"),
		Log:      log.Default(),
		Resolve:  proxy.DefaultResolve,
	}
	httpServer := &http.Server{Addr: addr, Handler: server, ReadHeaderTimeout: 10 * time.Second}
	go func() {
		log.Printf("egress listening on %s", addr)
		if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatal(err)
		}
	}()
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	<-stop
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	_ = httpServer.Shutdown(ctx)
}

func getenv(name, fallback string) string {
	if value := os.Getenv(name); value != "" {
		return value
	}
	return fallback
}
