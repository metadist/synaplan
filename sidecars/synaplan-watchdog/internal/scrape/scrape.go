// Package scrape calls the local admin API and the PHP readiness URL.
package scrape

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

// Admin reads FrankenPHP on localhost.
type Admin struct {
	BaseURL string
	Client  *http.Client
}

// Metrics returns the Prometheus text.
func (a Admin) Metrics(ctx context.Context) (string, error) {
	body, err := a.get(ctx, "/metrics")
	if err != nil {
		return "", err
	}
	return string(body), nil
}

// Threads returns the raw thread list.
func (a Admin) Threads(ctx context.Context) ([]byte, error) {
	return a.get(ctx, "/frankenphp/threads")
}

func (a Admin) get(ctx context.Context, path string) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, strings.TrimRight(a.BaseURL, "/")+path, nil)
	if err != nil {
		return nil, err
	}
	res, err := a.client().Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	body, err := io.ReadAll(io.LimitReader(res.Body, 2<<20))
	if err != nil {
		return nil, err
	}
	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("admin %s: %s", path, res.Status)
	}
	return body, nil
}

func (a Admin) client() *http.Client {
	if a.Client != nil {
		return a.Client
	}
	return &http.Client{Timeout: 2 * time.Second}
}

// PHP asks GET /api/health/ready. A non-200 answer is not ready.
type PHP struct {
	URL    string
	Client *http.Client
}

// Probe reports whether PHP answered 200, and how long it took.
func (p PHP) Probe(ctx context.Context) (bool, time.Duration) {
	start := time.Now()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, p.URL, nil)
	if err != nil {
		return false, time.Since(start)
	}
	client := p.Client
	if client == nil {
		client = &http.Client{Timeout: 2 * time.Second}
	}
	res, err := client.Do(req)
	latency := time.Since(start)
	if err != nil {
		return false, latency
	}
	defer res.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(res.Body, 1<<20))
	return res.StatusCode == http.StatusOK, latency
}
