// Command synaplan-watchdog reports this server's capacity.
// It does not relay client connections.
package main

import (
	"context"
	"errors"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/metadist/synaplan-watchdog/internal/agent"
	"github.com/metadist/synaplan-watchdog/internal/config"
	"github.com/metadist/synaplan-watchdog/internal/host"
	"github.com/metadist/synaplan-watchdog/internal/httpapi"
	"github.com/metadist/synaplan-watchdog/internal/scrape"
	"github.com/metadist/synaplan-watchdog/internal/store"
)

func main() {
	log.SetFlags(0)
	cfg := config.FromEnv()
	addr, err := httpapi.ListenAddr(cfg.Listen)
	if err != nil {
		log.Fatal(err)
	}
	st, err := store.Open(cfg.RedisURL)
	if err != nil {
		log.Fatalf("watchdog: redis: %v", err)
	}
	ag := &agent.Agent{
		ID:      cfg.ServerID,
		Version: cfg.Version,
		Prefix:  cfg.KeyPrefix,
		Read:    func() host.Facts { return host.Read(cfg.HostRoot, os.Getenv) },
		Store:   st,
	}
	// A worker or the scheduler has no FrankenPHP to ask. Its report is its
	// place, memory and processor, and its readiness is Redis alone.
	if cfg.ServesPHP() {
		client := &http.Client{Timeout: 2 * time.Second}
		ag.Admin = scrape.Admin{BaseURL: cfg.AdminURL, Client: client}
		ag.Probe = scrape.PHP{URL: cfg.PHPReadyURL, Client: client}
	}
	srv := httpapi.New(addr, ag, st)

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()

	errCh := make(chan error, 1)
	go func() {
		errCh <- srv.ListenAndServe()
	}()

	ag.Tick(ctx, time.Now())
	ticker := time.NewTicker(cfg.Interval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			shut, cancel := context.WithTimeout(context.Background(), 2*time.Second)
			_ = srv.Shutdown(shut)
			cancel()
			return
		case err := <-errCh:
			if err != nil && !errors.Is(err, http.ErrServerClosed) {
				log.Fatal(err)
			}
			return
		case now := <-ticker.C:
			ag.Tick(ctx, now)
		}
	}
}
