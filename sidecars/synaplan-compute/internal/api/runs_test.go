package api

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/metadist/synaplan-compute/pkg/config"
	"github.com/metadist/synaplan-compute/pkg/contract"
)

func authed(t *testing.T, method, url string, body io.Reader, contentType string) *http.Response {
	t.Helper()
	req, err := http.NewRequest(method, url, body)
	if err != nil {
		t.Fatal(err)
	}
	req.Header.Set("Authorization", "Bearer "+testToken)
	if contentType != "" {
		req.Header.Set("Content-Type", contentType)
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	return resp
}

func submitJSON(t *testing.T, ts *httptest.Server, body contract.RunRequest) string {
	t.Helper()
	raw, _ := json.Marshal(body)
	resp := authed(t, http.MethodPost, ts.URL+"/v1/runs", bytes.NewReader(raw), "application/json")
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusAccepted {
		b, _ := io.ReadAll(resp.Body)
		t.Fatalf("submit: %d %s", resp.StatusCode, b)
	}
	var acc contract.RunAccepted
	if err := json.NewDecoder(resp.Body).Decode(&acc); err != nil {
		t.Fatal(err)
	}
	return acc.RunID
}

func getStatus(t *testing.T, ts *httptest.Server, id string) (contract.RunStatus, int) {
	t.Helper()
	resp := authed(t, http.MethodGet, ts.URL+"/v1/runs/"+id, nil, "")
	defer resp.Body.Close()
	var st contract.RunStatus
	if resp.StatusCode == http.StatusOK {
		if err := json.NewDecoder(resp.Body).Decode(&st); err != nil {
			t.Fatal(err)
		}
	}
	return st, resp.StatusCode
}

func waitFor(t *testing.T, ts *httptest.Server, id string, pred func(contract.RunStatus) bool) contract.RunStatus {
	t.Helper()
	deadline := time.Now().Add(10 * time.Second)
	for time.Now().Before(deadline) {
		st, code := getStatus(t, ts, id)
		if code == http.StatusOK && pred(st) {
			return st
		}
		time.Sleep(5 * time.Millisecond)
	}
	st, _ := getStatus(t, ts, id)
	t.Fatalf("run %s did not reach the expected state: %+v", id, st)
	return st
}

func readFixture(t *testing.T, name string) []byte {
	t.Helper()
	dir, err := os.Getwd()
	if err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 8; i++ {
		p := filepath.Join(dir, "tests", "fixtures", "compute-contract", name)
		if b, err := os.ReadFile(p); err == nil {
			return b
		}
		dir = filepath.Dir(dir)
	}
	t.Fatalf("fixture %s not found", name)
	return nil
}

func finished(st contract.RunStatus) bool {
	return st.Status == contract.StatusSucceeded || st.Status == contract.StatusFailed || st.Status == contract.StatusCancelled
}

func TestConcurrencyBoundIsEnforced(t *testing.T) {
	fr := newFakeRunner()
	fr.waitDelay = 10 * time.Millisecond
	_, ts := newTestServer(t, testOpts{runner: fr, cfg: func(c *config.Config) {
		c.MaxConcurrent = 2
		c.QueueMax = 100
	}})
	const n = 40
	ids := make([]string, 0, n)
	var mu sync.Mutex
	var wg sync.WaitGroup
	for i := 0; i < n; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			id := submitJSON(t, ts, validRun())
			mu.Lock()
			ids = append(ids, id)
			mu.Unlock()
		}()
	}
	wg.Wait()
	for _, id := range ids {
		waitFor(t, ts, id, finished)
	}
	if got := fr.maxInflight.Load(); got > 2 {
		t.Fatalf("max in-flight containers %d, want <= 2", got)
	}
	if fr.created.Load() != n || fr.removed.Load() != n {
		t.Fatalf("created %d removed %d", fr.created.Load(), fr.removed.Load())
	}
	resp := authed(t, http.MethodGet, ts.URL+"/v1/health", nil, "")
	defer resp.Body.Close()
	var h contract.Health
	_ = json.NewDecoder(resp.Body).Decode(&h)
	if h.Capacity.Running != 0 || h.Capacity.Queued != 0 {
		t.Fatalf("counters must return to zero: %+v", h.Capacity)
	}
}

func TestQueueFullReturns429(t *testing.T) {
	fr := newFakeRunner()
	fr.waitDelay = 10 * time.Second
	_, ts := newTestServer(t, testOpts{runner: fr, cfg: func(c *config.Config) {
		c.MaxConcurrent = 1
		c.QueueMax = 1
	}})
	first := submitJSON(t, ts, validRun())
	waitFor(t, ts, first, func(st contract.RunStatus) bool { return st.Status == contract.StatusRunning })
	submitJSON(t, ts, validRun())
	assertRunErrorOn(t, ts, validRun(), http.StatusTooManyRequests, contract.ErrCapacityExceeded)
}

func TestLogsCapturedAndStreamedAsSSE(t *testing.T) {
	fr := newFakeRunner()
	fr.stdout = "rows=3\n"
	fr.stderr = "warn\n"
	_, ts := newTestServer(t, testOpts{runner: fr})
	id := submitJSON(t, ts, validRun())
	st := waitFor(t, ts, id, finished)
	if st.Status != contract.StatusSucceeded || st.ExitCode == nil || *st.ExitCode != 0 {
		t.Fatalf("%+v", st)
	}
	resp := authed(t, http.MethodGet, ts.URL+"/v1/runs/"+id+"/logs", nil, "")
	defer resp.Body.Close()
	if ct := resp.Header.Get("Content-Type"); ct != "text/event-stream" {
		t.Fatal(ct)
	}
	events, err := contract.ParseSSE(resp.Body)
	if err != nil {
		t.Fatal(err)
	}
	var kinds []string
	for _, e := range events {
		kinds = append(kinds, e.Event)
		if e.ID == "" {
			t.Fatalf("event without id: %+v", e)
		}
	}
	if strings.Join(kinds, ",") != "stdout,stderr,status,done" {
		t.Fatalf("events %v", kinds)
	}
	// The contract fixture is the same stream without stderr.
	fixture, err := contract.ParseSSE(bytes.NewReader(readFixture(t, "logs.sse")))
	if err != nil {
		t.Fatal(err)
	}
	var fixtureKinds []string
	for _, e := range fixture {
		fixtureKinds = append(fixtureKinds, e.Event)
	}
	var serverKinds []string
	for _, k := range kinds {
		if k != contract.LogEventStderr {
			serverKinds = append(serverKinds, k)
		}
	}
	if strings.Join(fixtureKinds, ",") != strings.Join(serverKinds, ",") {
		t.Fatalf("fixture %v vs server %v", fixtureKinds, serverKinds)
	}
	for i, e := range fixture {
		if e.ID != fmt.Sprint(i+1) {
			t.Fatalf("fixture ids must be sequential like the server's: %+v", e)
		}
	}
	var out contract.LogChunk
	if err := contract.DecodeJSON(strings.NewReader(events[0].Data), &out); err != nil || out.Text != "rows=3\n" {
		t.Fatalf("stdout %+v %v", out, err)
	}
	var done contract.LogDone
	if err := contract.DecodeJSON(strings.NewReader(events[3].Data), &done); err != nil {
		t.Fatal(err)
	}
	if done.Status != contract.StatusSucceeded || done.ExitCode == nil || *done.ExitCode != 0 {
		t.Fatalf("done %+v", done)
	}
}

func TestLogTruncationIsReported(t *testing.T) {
	fr := newFakeRunner()
	fr.stdout = strings.Repeat("x", 100)
	_, ts := newTestServer(t, testOpts{runner: fr, cfg: func(c *config.Config) { c.LogCapBytes = 16 }})
	id := submitJSON(t, ts, validRun())
	st := waitFor(t, ts, id, finished)
	if !st.Truncated.Stdout || st.Truncated.Stderr {
		t.Fatalf("truncated flags %+v", st.Truncated)
	}
	resp := authed(t, http.MethodGet, ts.URL+"/v1/runs/"+id+"/logs", nil, "")
	defer resp.Body.Close()
	events, err := contract.ParseSSE(resp.Body)
	if err != nil {
		t.Fatal(err)
	}
	seen := false
	for _, e := range events {
		if e.Event == contract.LogEventTruncated {
			seen = true
			var tr contract.Truncated
			if err := contract.DecodeJSON(strings.NewReader(e.Data), &tr); err != nil || !tr.Stdout {
				t.Fatalf("%+v %v", tr, err)
			}
		}
	}
	if !seen {
		t.Fatal("truncated event missing")
	}
}

func TestOOMKilledMapsToOOM(t *testing.T) {
	fr := newFakeRunner()
	fr.exitCode = 137
	fr.oom = true
	_, ts := newTestServer(t, testOpts{runner: fr})
	id := submitJSON(t, ts, validRun())
	st := waitFor(t, ts, id, finished)
	if st.Status != contract.StatusFailed || st.Reason != contract.ReasonOOM {
		t.Fatalf("%+v", st)
	}
}

func TestExit137WithoutOOMIsProgramError(t *testing.T) {
	fr := newFakeRunner()
	fr.exitCode = 137
	_, ts := newTestServer(t, testOpts{runner: fr})
	id := submitJSON(t, ts, validRun())
	st := waitFor(t, ts, id, finished)
	if st.Status != contract.StatusFailed || st.Reason != contract.ReasonProgramError {
		t.Fatalf("%+v", st)
	}
}

func TestTimeoutKillsAndReportsTimeout(t *testing.T) {
	fr := newFakeRunner()
	fr.waitDelay = 30 * time.Second
	_, ts := newTestServer(t, testOpts{runner: fr})
	body := validRun()
	body.Limits.TimeoutSec = 1
	id := submitJSON(t, ts, body)
	st := waitFor(t, ts, id, finished)
	if st.Status != contract.StatusFailed || st.Reason != contract.ReasonTimeout {
		t.Fatalf("%+v", st)
	}
	if fr.removed.Load() != 1 {
		t.Fatal("container must be removed after timeout")
	}
	if st.Usage.WallMs < 900 {
		t.Fatalf("wall time %d", st.Usage.WallMs)
	}
}

func TestOutputLimitAfterRunBlocksArtefacts(t *testing.T) {
	fr := newFakeRunner()
	fr.onCreate = func(spec runnerSpec) {
		_ = os.WriteFile(filepath.Join(spec.OutHost, "big.txt"), bytes.Repeat([]byte("a"), 1<<20), 0o644)
		_ = os.WriteFile(filepath.Join(spec.WorkHost, "scratch.txt"), bytes.Repeat([]byte("b"), 1<<20), 0o644)
	}
	_, ts := newTestServer(t, testOpts{runner: fr})
	body := validRun()
	body.Limits.OutputMb = 1
	id := submitJSON(t, ts, body)
	st := waitFor(t, ts, id, finished)
	if st.Status != contract.StatusFailed || st.Reason != contract.ReasonOutputLimit {
		t.Fatalf("%+v", st)
	}
	if st.Usage.BytesOut != 1<<20 {
		t.Fatalf("bytesOut %d", st.Usage.BytesOut)
	}
	resp := authed(t, http.MethodGet, ts.URL+"/v1/runs/"+id+"/artefacts", nil, "")
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusConflict {
		t.Fatalf("list must be refused: %d", resp.StatusCode)
	}
	resp2 := authed(t, http.MethodGet, ts.URL+"/v1/runs/"+id+"/artefacts/big.txt", nil, "")
	defer resp2.Body.Close()
	if resp2.StatusCode != http.StatusConflict {
		t.Fatalf("download must be refused: %d", resp2.StatusCode)
	}
}

func TestArtefactDownloadHeadersAndRefusals(t *testing.T) {
	fr := newFakeRunner()
	fr.onCreate = func(spec runnerSpec) {
		_ = os.WriteFile(filepath.Join(spec.OutHost, "chart.png"), []byte("\x89PNG\r\n\x1a\n"), 0o644)
		_ = os.WriteFile(filepath.Join(spec.OutHost, "evil.bin"), []byte{0, 1, 2, 3}, 0o644)
		_ = os.Symlink("/etc/passwd", filepath.Join(spec.OutHost, "passwd"))
	}
	_, ts := newTestServer(t, testOpts{runner: fr})
	body := validRun()
	body.Files = append(body.Files, contract.FileRef{Name: "data.csv", Role: "input"})
	req, ct := multipartRun(t, body, map[string][]byte{"main.py": []byte("print(1)"), "data.csv": []byte("a,b\n1,2\n")})
	resp := authed(t, http.MethodPost, ts.URL+"/v1/runs", req, ct)
	var acc contract.RunAccepted
	_ = json.NewDecoder(resp.Body).Decode(&acc)
	resp.Body.Close()
	st := waitFor(t, ts, acc.RunID, finished)
	if st.Status != contract.StatusSucceeded {
		t.Fatalf("%+v", st)
	}
	if st.Usage.BytesIn != int64(len("print(1)")+len("a,b\n1,2\n")) {
		t.Fatalf("bytesIn %d", st.Usage.BytesIn)
	}

	list := authed(t, http.MethodGet, ts.URL+"/v1/runs/"+acc.RunID+"/artefacts", nil, "")
	var items []contract.Artefact
	_ = json.NewDecoder(list.Body).Decode(&items)
	list.Body.Close()
	if len(items) != 2 {
		t.Fatalf("symlink must be omitted: %+v", items)
	}
	for _, it := range items {
		if it.Name == "evil.bin" && it.Rejected != contract.ErrMimeNotAllowed {
			t.Fatalf("%+v", it)
		}
	}

	ok := authed(t, http.MethodGet, ts.URL+"/v1/runs/"+acc.RunID+"/artefacts/chart.png", nil, "")
	defer ok.Body.Close()
	if ok.StatusCode != http.StatusOK {
		t.Fatal(ok.StatusCode)
	}
	if ok.Header.Get("Content-Type") != "image/png" || ok.Header.Get("X-Content-Type-Options") != "nosniff" ||
		!strings.HasPrefix(ok.Header.Get("Content-Disposition"), "attachment") {
		t.Fatalf("headers %+v", ok.Header)
	}
	refused := authed(t, http.MethodGet, ts.URL+"/v1/runs/"+acc.RunID+"/artefacts/evil.bin", nil, "")
	defer refused.Body.Close()
	if refused.StatusCode != http.StatusForbidden {
		t.Fatalf("rejected artefact must not download: %d", refused.StatusCode)
	}
	var eb contract.ErrorBody
	_ = json.NewDecoder(refused.Body).Decode(&eb)
	if eb.Error.Code != contract.ErrMimeNotAllowed {
		t.Fatal(eb.Error.Code)
	}
	link := authed(t, http.MethodGet, ts.URL+"/v1/runs/"+acc.RunID+"/artefacts/passwd", nil, "")
	defer link.Body.Close()
	if link.StatusCode != http.StatusNotFound {
		t.Fatalf("symlink must be 404: %d", link.StatusCode)
	}
}

func TestListArtefactsReportsInternalError(t *testing.T) {
	fr := newFakeRunner()
	s, ts := newTestServer(t, testOpts{runner: fr})
	id := submitJSON(t, ts, validRun())
	st := waitFor(t, ts, id, finished)
	if st.Status != contract.StatusSucceeded {
		t.Fatalf("%+v", st)
	}
	out := filepath.Join(s.scratchFor(id), "out")
	if err := os.RemoveAll(out); err != nil {
		t.Fatal(err)
	}
	outside := t.TempDir()
	if err := os.WriteFile(filepath.Join(outside, "secret.txt"), []byte("host"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outside, out); err != nil {
		t.Fatal(err)
	}
	resp := authed(t, http.MethodGet, ts.URL+"/v1/runs/"+id+"/artefacts", nil, "")
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusInternalServerError {
		t.Fatalf("list I/O error must not look empty: %d", resp.StatusCode)
	}
	var eb contract.ErrorBody
	if err := json.NewDecoder(resp.Body).Decode(&eb); err != nil {
		t.Fatal(err)
	}
	if eb.Error.Code != contract.ErrInternal {
		t.Fatal(eb.Error.Code)
	}
}

func TestDeleteRunningRunStaysCancelled(t *testing.T) {
	fr := newFakeRunner()
	fr.waitDelay = 30 * time.Second
	s, ts := newTestServer(t, testOpts{runner: fr})
	id := submitJSON(t, ts, validRun())
	waitFor(t, ts, id, func(st contract.RunStatus) bool { return st.Status == contract.StatusRunning })
	scratch := s.scratchFor(id)
	del := authed(t, http.MethodDelete, ts.URL+"/v1/runs/"+id, nil, "")
	del.Body.Close()
	if del.StatusCode != http.StatusNoContent {
		t.Fatal(del.StatusCode)
	}
	st, _ := getStatus(t, ts, id)
	if st.Status != contract.StatusCancelled || st.Reason != contract.ReasonCancelled {
		t.Fatalf("%+v", st)
	}
	deadline := time.Now().Add(5 * time.Second)
	for fr.removed.Load() == 0 && time.Now().Before(deadline) {
		time.Sleep(5 * time.Millisecond)
	}
	if fr.removed.Load() != 1 {
		t.Fatal("container must be killed and removed")
	}
	deadline = time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if _, err := os.Stat(scratch); os.IsNotExist(err) {
			break
		}
		time.Sleep(5 * time.Millisecond)
	}
	if _, err := os.Stat(scratch); !os.IsNotExist(err) {
		t.Fatal("scratch must be removed once execute exits")
	}
	time.Sleep(20 * time.Millisecond)
	st, _ = getStatus(t, ts, id)
	if st.Status != contract.StatusCancelled {
		t.Fatalf("status flipped after cancel: %+v", st)
	}
	del2 := authed(t, http.MethodDelete, ts.URL+"/v1/runs/"+id, nil, "")
	del2.Body.Close()
	if del2.StatusCode != http.StatusNoContent {
		t.Fatal(del2.StatusCode)
	}
	art := authed(t, http.MethodGet, ts.URL+"/v1/runs/"+id+"/artefacts", nil, "")
	art.Body.Close()
	if art.StatusCode != http.StatusNotFound {
		t.Fatalf("artefacts of a deleted run: %d", art.StatusCode)
	}
}

func TestDeleteQueuedRunNeverCreatesContainer(t *testing.T) {
	fr := newFakeRunner()
	fr.waitDelay = 30 * time.Second
	_, ts := newTestServer(t, testOpts{runner: fr, cfg: func(c *config.Config) { c.MaxConcurrent = 1 }})
	first := submitJSON(t, ts, validRun())
	waitFor(t, ts, first, func(st contract.RunStatus) bool { return st.Status == contract.StatusRunning })
	second := submitJSON(t, ts, validRun())
	if st, _ := getStatus(t, ts, second); st.Status != contract.StatusQueued {
		t.Fatalf("%+v", st)
	}
	del := authed(t, http.MethodDelete, ts.URL+"/v1/runs/"+second, nil, "")
	del.Body.Close()
	st, _ := getStatus(t, ts, second)
	if st.Status != contract.StatusCancelled {
		t.Fatalf("%+v", st)
	}
	del = authed(t, http.MethodDelete, ts.URL+"/v1/runs/"+first, nil, "")
	del.Body.Close()
	waitFor(t, ts, first, func(st contract.RunStatus) bool { return st.Status == contract.StatusCancelled })
	deadline := time.Now().Add(5 * time.Second)
	for fr.removed.Load() == 0 && time.Now().Before(deadline) {
		time.Sleep(5 * time.Millisecond)
	}
	time.Sleep(20 * time.Millisecond)
	if fr.created.Load() != 1 {
		t.Fatalf("queued run must never create a container, created=%d", fr.created.Load())
	}
	resp := authed(t, http.MethodGet, ts.URL+"/v1/health", nil, "")
	defer resp.Body.Close()
	var h contract.Health
	_ = json.NewDecoder(resp.Body).Decode(&h)
	if h.Capacity.Running != 0 || h.Capacity.Queued != 0 {
		t.Fatalf("counters: %+v", h.Capacity)
	}
}

func TestJanitorPrunesFinishedRuns(t *testing.T) {
	fr := newFakeRunner()
	s, ts := newTestServer(t, testOpts{runner: fr, cfg: func(c *config.Config) { c.RunRetention = time.Millisecond }})
	id := submitJSON(t, ts, validRun())
	waitFor(t, ts, id, finished)
	scratch := s.scratchFor(id)
	if _, err := os.Stat(scratch); err != nil {
		t.Fatal("scratch must exist for a finished run")
	}
	if n := s.Prune(time.Now().Add(-time.Hour)); n != 0 {
		t.Fatal("young runs must be kept")
	}
	if n := s.Prune(time.Now().Add(time.Second)); n != 1 {
		t.Fatalf("pruned %d", n)
	}
	if _, code := getStatus(t, ts, id); code != http.StatusNotFound {
		t.Fatalf("pruned run must be gone: %d", code)
	}
	if _, err := os.Stat(scratch); !os.IsNotExist(err) {
		t.Fatal("scratch must be deleted")
	}
	ctx, cancel := contextWithTimeout(50 * time.Millisecond)
	defer cancel()
	s.StartJanitor(ctx, time.Millisecond)
	<-ctx.Done()
}

func TestRequestBodyLimits(t *testing.T) {
	_, ts := newTestServer(t, testOpts{cfg: func(c *config.Config) {
		c.MaxRequestBytes = 2048
		c.MaxFiles = 2
	}})
	big := validRun()
	big.Entry.Args = []string{strings.Repeat("a", 4096)}
	assertRunErrorOn(t, ts, big, http.StatusRequestEntityTooLarge, contract.ErrPayloadTooLarge)

	// Multipart past the limit without a Content-Length header.
	body := validRun()
	body.Files = append(body.Files, contract.FileRef{Name: "blob.bin", Role: "input"})
	buf, ct := multipartRun(t, body, map[string][]byte{"blob.bin": bytes.Repeat([]byte("x"), 4096)})
	req, _ := http.NewRequest(http.MethodPost, ts.URL+"/v1/runs", io.NopCloser(buf))
	req.ContentLength = -1
	req.Header.Set("Authorization", "Bearer "+testToken)
	req.Header.Set("Content-Type", ct)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusRequestEntityTooLarge {
		t.Fatalf("chunked oversize multipart: %d", resp.StatusCode)
	}

	// More file parts than MaxFiles.
	many := validRun()
	many.Files = []contract.FileRef{{Name: "a", Role: "input"}, {Name: "b", Role: "input"}}
	buf, ct = multipartRun(t, many, map[string][]byte{"a": []byte("1"), "b": []byte("2"), "c": []byte("3")})
	resp = authed(t, http.MethodPost, ts.URL+"/v1/runs", buf, ct)
	defer resp.Body.Close()
	var eb contract.ErrorBody
	_ = json.NewDecoder(resp.Body).Decode(&eb)
	if resp.StatusCode != http.StatusBadRequest || eb.Error.Code != contract.ErrTooManyFiles {
		t.Fatalf("%d %+v", resp.StatusCode, eb)
	}
}

func TestUndeclaredMultipartPartRejected(t *testing.T) {
	ts := testServer(t)
	buf, ct := multipartRun(t, validRun(), map[string][]byte{"main.py": []byte("print(1)"), "sneaky.py": []byte("x")})
	resp := authed(t, http.MethodPost, ts.URL+"/v1/runs", buf, ct)
	defer resp.Body.Close()
	var eb contract.ErrorBody
	_ = json.NewDecoder(resp.Body).Decode(&eb)
	if resp.StatusCode != http.StatusBadRequest || eb.Error.Code != contract.ErrBadFileName {
		t.Fatalf("%d %+v", resp.StatusCode, eb)
	}
	var b bytes.Buffer
	mw := multipart.NewWriter(&b)
	p, _ := mw.CreateFormFile("main.py", "main.py")
	_, _ = p.Write([]byte("print(1)"))
	_ = mw.Close()
	resp = authed(t, http.MethodPost, ts.URL+"/v1/runs", &b, mw.FormDataContentType())
	defer resp.Body.Close()
	_ = json.NewDecoder(resp.Body).Decode(&eb)
	if resp.StatusCode != http.StatusBadRequest || eb.Error.Code != contract.ErrInvalidJSON {
		t.Fatalf("missing request.json: %d %+v", resp.StatusCode, eb)
	}
}

func TestDecodeErrorCodes(t *testing.T) {
	ts := testServer(t)
	resp := authed(t, http.MethodPost, ts.URL+"/v1/runs", strings.NewReader(`{"protocol":`), "application/json")
	var eb contract.ErrorBody
	_ = json.NewDecoder(resp.Body).Decode(&eb)
	resp.Body.Close()
	if resp.StatusCode != http.StatusBadRequest || eb.Error.Code != contract.ErrInvalidJSON {
		t.Fatalf("syntax error: %d %+v", resp.StatusCode, eb)
	}
	resp = authed(t, http.MethodPost, ts.URL+"/v1/runs", strings.NewReader(`{"protocol":1,"shell":"rm -rf /"}`), "application/json")
	_ = json.NewDecoder(resp.Body).Decode(&eb)
	resp.Body.Close()
	if resp.StatusCode != http.StatusBadRequest || eb.Error.Code != contract.ErrUnknownField {
		t.Fatalf("unknown field: %d %+v", resp.StatusCode, eb)
	}
	resp = authed(t, http.MethodPost, ts.URL+"/v1/workspaces", strings.NewReader(`nope`), "application/json")
	_ = json.NewDecoder(resp.Body).Decode(&eb)
	resp.Body.Close()
	if resp.StatusCode != http.StatusBadRequest || eb.Error.Code != contract.ErrInvalidJSON {
		t.Fatalf("workspace syntax error: %d %+v", resp.StatusCode, eb)
	}
}

func TestLimitsErrorCarriesDetails(t *testing.T) {
	ts := testServer(t)
	body := validRun()
	body.Limits.MemoryMb = 4096
	raw, _ := json.Marshal(body)
	resp := authed(t, http.MethodPost, ts.URL+"/v1/runs", bytes.NewReader(raw), "application/json")
	defer resp.Body.Close()
	var eb struct {
		Error struct {
			Code    string               `json:"code"`
			Message string               `json:"message"`
			Details contract.LimitDetail `json:"details"`
		} `json:"error"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&eb); err != nil {
		t.Fatal(err)
	}
	if eb.Error.Code != contract.ErrLimitsExceedCaps || eb.Error.Details.Field != "memoryMb" ||
		eb.Error.Details.Requested != 4096 || eb.Error.Details.Cap != 2048 {
		t.Fatalf("%+v", eb)
	}
}

func TestEgressAllowListRefusedFailClosed(t *testing.T) {
	_, ts := newTestServer(t, testOpts{cfg: func(c *config.Config) { c.EgressEnabled = true }})
	body := validRun()
	body.Egress = contract.Egress{Allow: []contract.EgressHost{{Host: "api.example.com", Port: 443, IPs: []string{"93.184.216.34"}}}}
	assertRunErrorOn(t, ts, body, http.StatusBadRequest, contract.ErrEgressNotAllowed)
	resp := authed(t, http.MethodGet, ts.URL+"/v1/health", nil, "")
	defer resp.Body.Close()
	var h contract.Health
	_ = json.NewDecoder(resp.Body).Decode(&h)
	if h.Features.Egress {
		t.Fatal("health must not advertise egress")
	}
}

func TestWorkspaceDownloadAppliesMimeAllowList(t *testing.T) {
	s, ts := newTestServer(t, testOpts{})
	id := createWorkspace(t, ts, "user:123", 16)
	host := s.ws.HostPath(id)
	if err := os.WriteFile(filepath.Join(host, "note.txt"), []byte("hello"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(host, "evil.bin"), []byte{0, 1, 2, 3}, 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("/etc/passwd", filepath.Join(host, "passwd.txt")); err != nil {
		t.Fatal(err)
	}
	ok := authed(t, http.MethodGet, ts.URL+"/v1/workspaces/"+id+"/files/note.txt", nil, "")
	defer ok.Body.Close()
	if ok.StatusCode != http.StatusOK || ok.Header.Get("Content-Type") != "text/plain" || ok.Header.Get("X-Content-Type-Options") != "nosniff" {
		t.Fatalf("%d %+v", ok.StatusCode, ok.Header)
	}
	refused := authed(t, http.MethodGet, ts.URL+"/v1/workspaces/"+id+"/files/evil.bin", nil, "")
	defer refused.Body.Close()
	if refused.StatusCode != http.StatusForbidden {
		t.Fatalf("mime must be enforced on workspace downloads: %d", refused.StatusCode)
	}
	link := authed(t, http.MethodGet, ts.URL+"/v1/workspaces/"+id+"/files/passwd.txt", nil, "")
	defer link.Body.Close()
	if link.StatusCode != http.StatusBadRequest {
		t.Fatalf("symlink must be refused: %d", link.StatusCode)
	}
	missing := authed(t, http.MethodGet, ts.URL+"/v1/workspaces/"+id+"/files/nope.txt", nil, "")
	defer missing.Body.Close()
	if missing.StatusCode != http.StatusNotFound {
		t.Fatalf("missing file: %d", missing.StatusCode)
	}
}
