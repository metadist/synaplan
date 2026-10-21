// Spike: what does it cost a Go process to hold many slow AI streams?
//
// One binary, three modes, standard library only:
//
//	upstream  a stub provider: each POST streams N SSE tokens, one every --interval
//	relay     accepts a client stream, opens the upstream stream, copies and flushes
//	          every event, and reports active streams, goroutines and RSS on /stats
//	load      opens --clients concurrent streams through the relay, prints TTFB and
//	          duration percentiles and the relay's peak RSS
//
// The relay does no translation; the plan keeps the wire formats in PHP. This
// measures the floor: memory and CPU per held stream in Go, to compare with a
// PHP worker per stream.
package main

import (
	"bufio"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"runtime"
	"sort"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

var active atomic.Int64
var peakActive atomic.Int64

func main() {
	mode := flag.String("mode", "", "upstream | relay | load")
	listen := flag.String("listen", ":9000", "listen address (upstream, relay)")
	upstream := flag.String("upstream", "http://127.0.0.1:9001/stream", "upstream URL (relay)")
	target := flag.String("target", "http://127.0.0.1:9000/relay", "relay URL (load)")
	stats := flag.String("stats", "http://127.0.0.1:9000/stats", "relay stats URL (load)")
	clients := flag.Int("clients", 100, "concurrent streams (load)")
	tokens := flag.Int("tokens", 200, "tokens per stream (upstream)")
	interval := flag.Duration("interval", 50*time.Millisecond, "delay between tokens (upstream)")
	flag.Parse()

	switch *mode {
	case "upstream":
		serveUpstream(*listen, *tokens, *interval)
	case "relay":
		serveRelay(*listen, *upstream)
	case "load":
		runLoad(*target, *stats, *clients)
	default:
		fmt.Fprintln(os.Stderr, "--mode upstream|relay|load")
		os.Exit(2)
	}
}

func serveUpstream(listen string, tokens int, interval time.Duration) {
	http.HandleFunc("/stream", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/event-stream")
		flusher := w.(http.Flusher)
		for i := 0; i < tokens; i++ {
			fmt.Fprintf(w, "data: {\"choices\":[{\"delta\":{\"content\":\"tok%d \"}}]}\n\n", i)
			flusher.Flush()
			select {
			case <-r.Context().Done():
				return
			case <-time.After(interval):
			}
		}
		fmt.Fprint(w, "data: [DONE]\n\n")
		flusher.Flush()
	})
	log.Fatal(http.ListenAndServe(listen, nil))
}

func serveRelay(listen, upstream string) {
	client := &http.Client{Transport: &http.Transport{
		Proxy:               http.ProxyFromEnvironment, // HTTPS_PROXY / NO_PROXY, as central egress requires
		MaxIdleConnsPerHost: 4096,
	}}
	http.HandleFunc("/relay", func(w http.ResponseWriter, r *http.Request) {
		n := active.Add(1)
		for {
			peak := peakActive.Load()
			if n <= peak || peakActive.CompareAndSwap(peak, n) {
				break
			}
		}
		defer active.Add(-1)

		req, _ := http.NewRequestWithContext(r.Context(), http.MethodPost, upstream, r.Body)
		resp, err := client.Do(req)
		if err != nil {
			http.Error(w, "upstream unreachable", http.StatusBadGateway)
			return
		}
		defer resp.Body.Close()
		w.Header().Set("Content-Type", "text/event-stream")
		flusher := w.(http.Flusher)
		reader := bufio.NewReader(resp.Body)
		for {
			line, err := reader.ReadBytes('\n')
			if len(line) > 0 {
				w.Write(line)
				if len(line) == 1 {
					flusher.Flush()
				}
			}
			if err != nil {
				flusher.Flush()
				return
			}
		}
	})
	http.HandleFunc("/stats", func(w http.ResponseWriter, _ *http.Request) {
		json.NewEncoder(w).Encode(map[string]any{
			"active":     active.Load(),
			"peak":       peakActive.Load(),
			"goroutines": runtime.NumGoroutine(),
			"rss_mb":     rssMB(),
			"cpu_s":      cpuSeconds(),
		})
	})
	log.Fatal(http.ListenAndServe(listen, nil))
}

func rssMB() float64 {
	data, err := os.ReadFile("/proc/self/status")
	if err != nil {
		return 0
	}
	for _, line := range strings.Split(string(data), "\n") {
		if strings.HasPrefix(line, "VmRSS:") {
			var kb float64
			fmt.Sscanf(strings.TrimSpace(strings.TrimPrefix(line, "VmRSS:")), "%f", &kb)
			return kb / 1024
		}
	}
	return 0
}

func cpuSeconds() float64 {
	data, err := os.ReadFile("/proc/self/stat")
	if err != nil {
		return 0
	}
	fields := strings.Fields(string(data))
	var utime, stime float64
	fmt.Sscanf(fields[13], "%f", &utime)
	fmt.Sscanf(fields[14], "%f", &stime)
	return (utime + stime) / 100 // USER_HZ
}

type relayStats struct {
	Active     int64   `json:"active"`
	Peak       int64   `json:"peak"`
	Goroutines int     `json:"goroutines"`
	RSSMB      float64 `json:"rss_mb"`
	CPUS       float64 `json:"cpu_s"`
}

func readStats(url string) relayStats {
	var s relayStats
	resp, err := http.Get(url)
	if err != nil {
		return s
	}
	defer resp.Body.Close()
	json.NewDecoder(resp.Body).Decode(&s)
	return s
}

func runLoad(target, statsURL string, clients int) {
	before := readStats(statsURL)
	client := &http.Client{Transport: &http.Transport{MaxIdleConnsPerHost: clients, MaxConnsPerHost: 0}}
	var wg sync.WaitGroup
	var mu sync.Mutex
	var ttfb, total []float64
	failed := 0
	var peakRSS float64
	stop := make(chan struct{})
	samplerDone := make(chan struct{})
	go func() {
		defer close(samplerDone)
		for {
			select {
			case <-stop:
				return
			case <-time.After(500 * time.Millisecond):
				if s := readStats(statsURL); s.RSSMB > peakRSS {
					peakRSS = s.RSSMB
				}
			}
		}
	}()
	started := time.Now()
	for i := 0; i < clients; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			t0 := time.Now()
			resp, err := client.Post(target, "application/json", strings.NewReader("{}"))
			if err != nil {
				mu.Lock()
				failed++
				mu.Unlock()
				return
			}
			defer resp.Body.Close()
			reader := bufio.NewReader(resp.Body)
			first := time.Duration(0)
			done := false
			var readErr error
			for {
				line, err := reader.ReadBytes('\n')
				if first == 0 && len(line) > 1 {
					first = time.Since(t0)
				}
				if strings.TrimSpace(string(line)) == "data: [DONE]" {
					done = true
				}
				if err != nil {
					if err != io.EOF {
						readErr = err
					}
					break
				}
			}
			mu.Lock()
			defer mu.Unlock()
			if resp.StatusCode != http.StatusOK || !done || readErr != nil {
				failed++
				return
			}
			ttfb = append(ttfb, first.Seconds())
			total = append(total, time.Since(t0).Seconds())
		}()
	}
	wg.Wait()
	close(stop)
	<-samplerDone
	after := readStats(statsURL)
	fmt.Printf("clients=%d ok=%d failed=%d (ok = HTTP 200 and [DONE] received) wall=%.1fs\n", clients, len(total), failed, time.Since(started).Seconds())
	fmt.Printf("  ttfb     %s\n", pct(ttfb))
	fmt.Printf("  duration %s\n", pct(total))
	fmt.Printf("  relay: peak active=%d rss idle=%.1f MB peak=%.1f MB -> %.1f KB per stream; cpu %.2fs for %d streams\n",
		after.Peak, before.RSSMB, peakRSS, (peakRSS-before.RSSMB)*1024/float64(clients), after.CPUS-before.CPUS, clients)
}

func pct(values []float64) string {
	if len(values) == 0 {
		return "n=0"
	}
	sort.Float64s(values)
	at := func(p float64) float64 { return values[int(p*float64(len(values)-1))] }
	return fmt.Sprintf("p50=%.3fs p95=%.3fs max=%.3fs", at(0.5), at(0.95), values[len(values)-1])
}
