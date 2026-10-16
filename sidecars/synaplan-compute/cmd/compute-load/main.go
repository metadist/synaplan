// Command compute-load hammers a compute sidecar with N concurrent runs from
// a scenario file and checks the SIZING.md pass bars. It exits non-zero when
// any bar fails. It needs a running sidecar plus docker access for the
// leftover-container check (skipped with a warning without it).
//
// Example:
//
//	COMPUTE_TOKEN=... go run ./cmd/compute-load -url http://localhost:8080 \
//	  -scenario scenarios/mixed.json -n 32 -concurrency 4
package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"net/http"
	"os"
	"sync"
	"sync/atomic"
	"time"
)

// Template is one weighted run shape inside a scenario file.
type Template struct {
	Name          string            `json:"name"`
	Weight        int               `json:"weight"`
	Image         string            `json:"image"`
	Program       string            `json:"program"`
	Args          []string          `json:"args"`
	Files         map[string]string `json:"files"`
	TimeoutSec    int               `json:"timeoutSec"`
	MemoryMb      int               `json:"memoryMb"`
	CPU           float64           `json:"cpu"`
	Pids          int               `json:"pids"`
	OutputMb      int               `json:"outputMb"`
	ExpectTimeout bool              `json:"expectTimeout"`
}

// Scenario is the run mix file.
type Scenario struct {
	Runs []Template `json:"runs"`
}

type outcome struct {
	name         string
	submitMs     int64
	toRunningMs  int64
	toDoneMs     int64
	timeoutSec   int
	expectTimout bool
	refused      string
	terminal     string
	reason       string
	overtimeMs   int64
}

type summary struct {
	runs          int
	succeeded     int
	expectedFails int
	refusals      map[string]int
	submitP50     int64
	submitP95     int64
	runningP50    int64
	runningP95    int64
	doneP50       int64
	doneP95       int64
	maxOvertimeMs int64
	loadAvg       float64
	cores         int
	leftover      int
	leftoverKnown bool
	failures      []string
}

func main() {
	url := flag.String("url", "http://localhost:8080", "sidecar base URL")
	scenarioPath := flag.String("scenario", "scenarios/mixed.json", "scenario file")
	n := flag.Int("n", 32, "total runs to submit")
	concurrency := flag.Int("concurrency", 4, "in-flight submissions")
	timeout := flag.Duration("timeout", 10*time.Minute, "overall deadline")
	maxOvertime := flag.Duration("overtime", 2*time.Second, "allowed run overrun past timeoutSec")
	maxLoadFactor := flag.Float64("load-factor", 1.5, "allowed 1m load average as a multiple of cores")
	flag.Parse()

	token := os.Getenv("COMPUTE_TOKEN")
	if token == "" {
		fatal("COMPUTE_TOKEN is required")
	}
	raw, err := os.ReadFile(*scenarioPath)
	if err != nil {
		fatal(err.Error())
	}
	var scenario Scenario
	if err := json.Unmarshal(raw, &scenario); err != nil {
		fatal(err.Error())
	}
	if len(scenario.Runs) == 0 {
		fatal("scenario has no runs")
	}

	deadline := time.Now().Add(*timeout)
	client := &http.Client{Timeout: 60 * time.Second}
	outcomes := make([]outcome, *n)
	var refused atomic.Int64

	jobs := make(chan int, *n)
	for i := range outcomes {
		jobs <- i
	}
	close(jobs)

	var wg sync.WaitGroup
	for w := 0; w < *concurrency; w++ {
		wg.Add(1)
		go func(seed int64) {
			defer wg.Done()
			for i := range jobs {
				outcomes[i] = fireOne(client, *url, token, pick(scenario.Runs, seed+int64(i)), deadline)
				if outcomes[i].refused != "" {
					refused.Add(1)
				}
			}
		}(int64(w) * 1000003)
	}
	wg.Wait()

	sum := summarize(outcomes, *maxOvertime, *maxLoadFactor)
	printSummary(sum)
	if len(sum.failures) > 0 {
		os.Exit(1)
	}
}

// pick selects a template by weight, deterministically per index.
func pick(runs []Template, salt int64) Template {
	total := 0
	for _, r := range runs {
		total += r.Weight
	}
	if total <= 0 {
		return runs[0]
	}
	x := int(salt*2654435761) % total
	if x < 0 {
		x = -x
	}
	for _, r := range runs {
		x -= r.Weight
		if x < 0 {
			return r
		}
	}
	return runs[len(runs)-1]
}

func fatal(msg string) {
	fmt.Fprintln(os.Stderr, "compute-load:", msg)
	os.Exit(2)
}
