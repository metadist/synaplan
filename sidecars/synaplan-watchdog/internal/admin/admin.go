// Package admin reads FrankenPHP's Prometheus metrics and thread list.
// Request paths from the thread list are counted and then dropped.
package admin

import (
	"encoding/json"
	"regexp"
	"strconv"
	"strings"
)

// Pool is one FrankenPHP worker pool.
type Pool struct {
	Total int
	Busy  int
}

// ThreadSummary counts threads without keeping what they are serving.
type ThreadSummary struct {
	Count int
	Busy  int
}

var (
	metricLine = regexp.MustCompile(`^([a-zA-Z_:][a-zA-Z0-9_:]*)(\{[^}]*\})?\s+([0-9eE.+\-]+)`)
	workerAttr = regexp.MustCompile(`(?:^|,)worker="([^"]*)"`)
)

// ParsePools reads frankenphp_total_workers and frankenphp_busy_workers.
// A worker label of m#streams is the same pool as streams.
func ParsePools(body string) map[string]Pool {
	pools := map[string]Pool{}
	for _, line := range strings.Split(body, "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		match := metricLine.FindStringSubmatch(line)
		if match == nil {
			continue
		}
		name := match[1]
		if name != "frankenphp_total_workers" && name != "frankenphp_busy_workers" {
			continue
		}
		poolName := poolFromLabels(match[2])
		if poolName != "streams" && poolName != "api" {
			continue
		}
		value, err := strconv.ParseFloat(match[3], 64)
		if err != nil {
			continue
		}
		pool := pools[poolName]
		if name == "frankenphp_total_workers" {
			pool.Total = int(value)
		} else {
			pool.Busy = int(value)
		}
		pools[poolName] = pool
	}
	return pools
}

func poolFromLabels(labels string) string {
	labels = strings.TrimPrefix(labels, "{")
	labels = strings.TrimSuffix(labels, "}")
	match := workerAttr.FindStringSubmatch(labels)
	if match == nil {
		return ""
	}
	name := match[1]
	name = strings.TrimPrefix(name, "m#")
	return name
}

type thread struct {
	Busy   *bool  `json:"busy"`
	IsBusy *bool  `json:"IsBusy"`
	State  string `json:"state"`
	Upper  string `json:"State"`
}

type threadEnvelope struct {
	Threads           []thread `json:"threads"`
	ThreadDebugStates []thread `json:"ThreadDebugStates"`
}

// SummarizeThreads counts entries. It does not return URIs.
// FrankenPHP 1.12 serves {"ThreadDebugStates":[{"IsBusy":false,"State":"ready","CurrentURI":"..."}]}.
func SummarizeThreads(body []byte) (ThreadSummary, error) {
	list, err := decodeThreads(body)
	if err != nil {
		return ThreadSummary{}, err
	}
	summary := ThreadSummary{Count: len(list)}
	for _, item := range list {
		if threadBusy(item) {
			summary.Busy++
		}
	}
	return summary, nil
}

func decodeThreads(body []byte) ([]thread, error) {
	var list []thread
	if err := json.Unmarshal(body, &list); err == nil {
		return list, nil
	}
	var wrapped threadEnvelope
	if err := json.Unmarshal(body, &wrapped); err != nil {
		return nil, err
	}
	if wrapped.ThreadDebugStates != nil {
		return wrapped.ThreadDebugStates, nil
	}
	return wrapped.Threads, nil
}

func threadBusy(item thread) bool {
	if item.IsBusy != nil {
		return *item.IsBusy
	}
	if item.Busy != nil {
		return *item.Busy
	}
	switch strings.ToLower(firstState(item)) {
	case "busy", "running":
		return true
	default:
		return false
	}
}

func firstState(item thread) string {
	if item.State != "" {
		return item.State
	}
	return item.Upper
}
