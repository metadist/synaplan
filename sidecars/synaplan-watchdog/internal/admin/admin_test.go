package admin

import (
	"os"
	"strings"
	"testing"
)

func TestParsePoolsReadsSpikeLabels(t *testing.T) {
	body, err := os.ReadFile("../../testdata/metrics.txt")
	if err != nil {
		t.Fatal(err)
	}
	pools := ParsePools(string(body))
	streams := pools["streams"]
	if streams.Total != 4 || streams.Busy != 3 {
		t.Fatalf("streams = %+v", streams)
	}
	api := pools["api"]
	if api.Total != 4 || api.Busy != 1 {
		t.Fatalf("api = %+v", api)
	}
}

func TestParsePoolsAcceptsPlainWorkerNames(t *testing.T) {
	body := `
frankenphp_total_workers{worker="streams"} 16
frankenphp_busy_workers{worker="streams"} 2
frankenphp_total_workers{worker="api"} 8
frankenphp_busy_workers{worker="api"} 0
`
	pools := ParsePools(body)
	if pools["streams"].Total != 16 || pools["streams"].Busy != 2 {
		t.Fatalf("streams = %+v", pools["streams"])
	}
	if pools["api"].Total != 8 || pools["api"].Busy != 0 {
		t.Fatalf("api = %+v", pools["api"])
	}
}

func TestSummarizeThreadsDropsRequestPaths(t *testing.T) {
	body, err := os.ReadFile("../../testdata/threads.json")
	if err != nil {
		t.Fatal(err)
	}
	summary, err := SummarizeThreads(body)
	if err != nil {
		t.Fatal(err)
	}
	if summary.Count != 2 || summary.Busy != 1 {
		t.Fatalf("summary = %+v", summary)
	}
	if strings.Contains(string(body), "/api/v1/chats/42/stream") == false {
		t.Fatal("fixture should contain a path so the drop is meaningful")
	}
}

func TestSummarizeWrappedThreadList(t *testing.T) {
	body := []byte(`{"threads":[{"state":"running"},{"state":"ready"}]}`)
	summary, err := SummarizeThreads(body)
	if err != nil {
		t.Fatal(err)
	}
	if summary.Count != 2 || summary.Busy != 1 {
		t.Fatalf("summary = %+v", summary)
	}
}
