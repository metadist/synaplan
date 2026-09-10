package main

import (
	"context"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/metadist/synaplan-compute/internal/api"
	"github.com/metadist/synaplan-compute/internal/audit"
	"github.com/metadist/synaplan-compute/internal/images"
	"github.com/metadist/synaplan-compute/internal/runner"
	rt "github.com/metadist/synaplan-compute/internal/runtime"
	"github.com/metadist/synaplan-compute/pkg/config"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		log.Fatal(err)
	}
	imgs := images.Default()
	if err := imgs.RequireDigests(); err != nil {
		log.Fatal(err)
	}
	docker := runner.Connect()
	defer docker.Close()

	var info rt.Info
	if docker.Available() {
		ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
		runtimes, err := docker.InfoRuntimes(ctx)
		cancel()
		if err == nil {
			info.Runtimes = runtimes
		}
		_ = docker.SweepOrphans(context.Background())
	}
	sel, err := rt.Select(cfg.Tier, cfg.RuntimeName, info, docker.Available())
	if err != nil {
		log.Fatal(err)
	}

	srv, err := api.New(api.Options{
		Config: cfg,
		Docker: docker,
		Images: imgs,
		Audit:  audit.New(os.Stdout),
		Tier:   sel,
	})
	if err != nil {
		log.Fatal(err)
	}

	httpSrv := &http.Server{
		Addr:              cfg.ListenAddr,
		Handler:           srv.Handler(),
		ReadHeaderTimeout: 10 * time.Second,
	}
	go func() {
		log.Printf("synaplan-compute listening on %s tier=%s docker=%v", cfg.ListenAddr, sel.Tier, docker.Available())
		if err := httpSrv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatal(err)
		}
	}()

	ch := make(chan os.Signal, 1)
	signal.Notify(ch, syscall.SIGINT, syscall.SIGTERM)
	<-ch
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = httpSrv.Shutdown(ctx)
}
