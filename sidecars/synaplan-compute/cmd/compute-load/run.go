package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"os"
	"runtime"
	"sort"
	"strconv"
	"time"
)

type accepted struct {
	RunID string `json:"runId"`
}

type runStatus struct {
	Status     string `json:"status"`
	Reason     string `json:"reason"`
	StartedAt  string `json:"startedAt"`
	FinishedAt string `json:"finishedAt"`
}

type apiError struct {
	Error struct {
		Code string `json:"code"`
	} `json:"error"`
}

// fireOne submits a single run and follows it to a terminal state.
func fireOne(client *http.Client, base, token string, tpl Template, deadline time.Time) outcome {
	out := outcome{name: tpl.Name, timeoutSec: tpl.TimeoutSec, expectTimout: tpl.ExpectTimeout}
	if time.Now().After(deadline) {
		out.refused = "client-deadline"
		return out
	}
	files := map[string]string{}
	for name, content := range tpl.Files {
		files[name] = content
	}
	reqBody := map[string]any{
		"protocol": 1,
		"owner":    "load-test",
		"workspace": map[string]string{
			"kind": "run",
		},
		"image": tpl.Image,
		"entry": map[string]any{"program": tpl.Program, "args": tpl.Args},
		"limits": map[string]any{
			"timeoutSec": tpl.TimeoutSec, "memoryMb": tpl.MemoryMb, "cpu": tpl.CPU,
			"pids": tpl.Pids, "outputMb": tpl.OutputMb,
		},
		"egress": map[string]any{"allow": []any{}},
	}
	declared := []map[string]string{}
	for name := range files {
		declared = append(declared, map[string]string{"name": name, "role": "input"})
	}
	reqBody["files"] = declared

	var buf bytes.Buffer
	mw := multipart.NewWriter(&buf)
	raw, _ := json.Marshal(reqBody)
	w, _ := mw.CreateFormFile("request.json", "request.json")
	_, _ = w.Write(raw)
	for name, content := range files {
		p, _ := mw.CreateFormFile(name, name)
		_, _ = io.WriteString(p, content)
	}
	_ = mw.Close()

	submitAt := time.Now()
	req, _ := http.NewRequest(http.MethodPost, base+"/v1/runs", &buf)
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Content-Type", mw.FormDataContentType())
	resp, err := client.Do(req)
	out.submitMs = time.Since(submitAt).Milliseconds()
	if err != nil {
		out.refused = "submit-error"
		return out
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 64*1024))
	if resp.StatusCode != http.StatusAccepted {
		var apiErr apiError
		_ = json.Unmarshal(body, &apiErr)
		code := apiErr.Error.Code
		if code == "" {
			code = "http-" + strconv.Itoa(resp.StatusCode)
		}
		out.refused = code
		return out
	}
	var acc accepted
	if err := json.Unmarshal(body, &acc); err != nil || acc.RunID == "" {
		out.refused = "bad-accepted-body"
		return out
	}

	// Follow to terminal, recording first-running and done timestamps.
	runningAt := time.Time{}
	tick := time.NewTicker(time.Second)
	defer tick.Stop()
	for {
		if time.Now().After(deadline) {
			out.refused = "client-deadline"
			return out
		}
		st, ok := pollStatus(client, base, token, acc.RunID)
		if !ok {
			out.refused = "status-error"
			return out
		}
		if st.Status == "running" && runningAt.IsZero() {
			runningAt = time.Now()
		}
		if st.Status == "succeeded" || st.Status == "failed" || st.Status == "cancelled" {
			out.terminal = st.Status
			out.reason = st.Reason
			if !runningAt.IsZero() {
				out.toRunningMs = runningAt.Sub(submitAt).Milliseconds()
			}
			out.toDoneMs = time.Since(submitAt).Milliseconds()
			// Timeout enforcement precision uses server timestamps to
			// avoid client clock skew.
			if st.StartedAt != "" && st.FinishedAt != "" {
				if start, err1 := time.Parse(time.RFC3339Nano, st.StartedAt); err1 == nil {
					if finish, err2 := time.Parse(time.RFC3339Nano, st.FinishedAt); err2 == nil {
						if over := finish.Sub(start) - time.Duration(tpl.TimeoutSec)*time.Second; over > 0 {
							out.overtimeMs = over.Milliseconds()
						}
					}
				}
			}
			return out
		}
		<-tick.C
	}
}

func pollStatus(client *http.Client, base, token, id string) (runStatus, bool) {
	var st runStatus
	req, _ := http.NewRequest(http.MethodGet, base+"/v1/runs/"+id, nil)
	req.Header.Set("Authorization", "Bearer "+token)
	resp, err := client.Do(req)
	if err != nil {
		return st, false
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return st, false
	}
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 64*1024))
	if err := json.Unmarshal(body, &st); err != nil {
		return st, false
	}
	return st, true
}

func summarize(outcomes []outcome, maxOvertime time.Duration, maxLoadFactor float64) summary {
	sum := summary{runs: len(outcomes), refusals: map[string]int{}}
	var submit, running, done []int64
	for _, o := range outcomes {
		if o.refused != "" {
			sum.refusals[o.refused]++
			continue
		}
		submit = append(submit, o.submitMs)
		if o.toRunningMs > 0 {
			running = append(running, o.toRunningMs)
		}
		done = append(done, o.toDoneMs)
		if o.overtimeMs > sum.maxOvertimeMs {
			sum.maxOvertimeMs = o.overtimeMs
		}
		if o.expectTimout {
			if o.terminal == "failed" && o.reason == "timeout" {
				sum.expectedFails++
			} else {
				sum.failures = append(sum.failures, fmt.Sprintf("%s: expected timeout kill, got %s:%s", o.name, o.terminal, o.reason))
			}
			continue
		}
		if o.terminal == "succeeded" {
			sum.succeeded++
			continue
		}
		sum.failures = append(sum.failures, fmt.Sprintf("%s: unexpected terminal %s:%s", o.name, o.terminal, o.reason))
	}
	sum.submitP50, sum.submitP95 = pct(submit, 50), pct(submit, 95)
	sum.runningP50, sum.runningP95 = pct(running, 50), pct(running, 95)
	sum.doneP50, sum.doneP95 = pct(done, 50), pct(done, 95)
	sum.loadAvg, sum.cores = hostLoad()
	sum.leftover, sum.leftoverKnown = leftoverContainers()
	for code, n := range sum.refusals {
		if code != "capacity_exceeded" {
			sum.failures = append(sum.failures, fmt.Sprintf("%d unexpected refusal(s): %s", n, code))
		}
	}
	if sum.maxOvertimeMs > maxOvertime.Milliseconds() {
		sum.failures = append(sum.failures, fmt.Sprintf("slowest timeout kill overran by %dms (budget %s)", sum.maxOvertimeMs, maxOvertime))
	}
	if sum.cores > 0 && sum.loadAvg > float64(sum.cores)*maxLoadFactor {
		sum.failures = append(sum.failures, fmt.Sprintf("host load %.2f above %.1fx%d cores", sum.loadAvg, maxLoadFactor, sum.cores))
	}
	if sum.leftoverKnown && sum.leftover > 0 {
		sum.failures = append(sum.failures, fmt.Sprintf("%d run container(s) left behind", sum.leftover))
	}
	sort.Strings(sum.failures)
	return sum
}

func pct(values []int64, p int) int64 {
	if len(values) == 0 {
		return 0
	}
	sorted := append([]int64(nil), values...)
	sort.Slice(sorted, func(i, j int) bool { return sorted[i] < sorted[j] })
	at := (len(sorted) * p) / 100
	if at >= len(sorted) {
		at = len(sorted) - 1
	}
	return sorted[at]
}

// hostLoad reads the 1-minute load average on Linux; elsewhere unknown.
func hostLoad() (float64, int) {
	cores := runtime.NumCPU()
	if runtime.GOOS != "linux" {
		return 0, cores
	}
	raw, err := os.ReadFile("/proc/loadavg")
	if err != nil {
		return 0, cores
	}
	var load float64
	if _, err := fmt.Sscanf(string(raw), "%f", &load); err != nil {
		return 0, cores
	}
	return load, cores
}

// leftoverContainers counts run containers still on the daemon (label
// synaplan.compute.run=1). Unknown without docker access — the nightly
// always has it; local runs without it skip this bar with a warning.
func leftoverContainers() (int, bool) {
	return dockerRunContainerCount()
}

func printSummary(sum summary) {
	fmt.Printf("runs=%d succeeded=%d expected-failures=%d\n", sum.runs, sum.succeeded, sum.expectedFails)
	fmt.Printf("submit p50=%dms p95=%dms\n", sum.submitP50, sum.submitP95)
	fmt.Printf("to-running p50=%dms p95=%dms\n", sum.runningP50, sum.runningP95)
	fmt.Printf("to-done p50=%dms p95=%dms\n", sum.doneP50, sum.doneP95)
	fmt.Printf("max-timeout-overrun=%dms host-load=%.2f/%d\n", sum.maxOvertimeMs, sum.loadAvg, sum.cores)
	if sum.leftoverKnown {
		fmt.Printf("leftover-containers=%d\n", sum.leftover)
	} else {
		fmt.Printf("leftover-containers=unknown (no docker access)\n")
	}
	if len(sum.refusals) > 0 {
		fmt.Printf("refusals=%v\n", sum.refusals)
	}
	if len(sum.failures) == 0 {
		fmt.Println("PASS")
		return
	}
	fmt.Println("FAIL:")
	for _, f := range sum.failures {
		fmt.Println(" -", f)
	}
}
