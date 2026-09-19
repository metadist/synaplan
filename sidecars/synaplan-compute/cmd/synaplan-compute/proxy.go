package main

import (
	"context"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/metadist/synaplan-compute/internal/egress"
	"github.com/metadist/synaplan-compute/pkg/contract"
)

// runProxy serves one run's CONNECT proxy. It runs in a throwaway container
// on the run's private network (see runner EgressNet), never in the main
// sidecar process, so attacker-influenced traffic stays out of the
// privileged daemon. Configuration arrives via environment only.
func runProxy() {
	var allow []contract.EgressHost
	if raw := os.Getenv("EGRESS_ALLOW_JSON"); raw != "" {
		if err := json.Unmarshal([]byte(raw), &allow); err != nil {
			log.Fatalf("proxy: bad EGRESS_ALLOW_JSON: %v", err)
		}
	}
	port := os.Getenv("EGRESS_PORT")
	if port == "" {
		port = "3128"
	}
	srv := egress.NewServer(allow, egress.Option{
		OnRefused: func(host string, p int, reason string) {
			log.Printf("proxy: refused %s:%d (%s)", host, p, reason)
		},
	})
	httpSrv := &http.Server{
		Addr:              ":" + port,
		Handler:           srv,
		ReadHeaderTimeout: 10 * time.Second,
	}
	go func() {
		log.Printf("proxy: listening on :%s for %d host(s)", port, len(allow))
		if err := httpSrv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("proxy: %v", err)
		}
	}()
	ch := make(chan os.Signal, 1)
	signal.Notify(ch, syscall.SIGINT, syscall.SIGTERM)
	<-ch
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	_ = httpSrv.Shutdown(ctx)
}
