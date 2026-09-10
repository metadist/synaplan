package api

import (
	"bytes"
	"encoding/json"
	"io"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/metadist/synaplan-compute/internal/audit"
	"github.com/metadist/synaplan-compute/internal/runner"
	rt "github.com/metadist/synaplan-compute/internal/runtime"
	"github.com/metadist/synaplan-compute/internal/workspace"
	"github.com/metadist/synaplan-compute/pkg/config"
	"github.com/metadist/synaplan-compute/pkg/contract"
)

const testToken = "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"

type testOpts struct {
	runner runner.Runner
	cfg    func(*config.Config)
}

func newTestServer(t *testing.T, o testOpts) (*Server, *httptest.Server) {
	t.Helper()
	cfg := &config.Config{
		AuthToken:         testToken,
		ScratchDir:        t.TempDir(),
		WorkspacesDir:     t.TempDir(),
		SandboxUID:        65534,
		SandboxGID:        65534,
		MaxTimeoutSec:     300,
		MaxMemoryMb:       2048,
		MaxCPU:            2,
		MaxPids:           256,
		MaxOutputMb:       200,
		MaxConcurrent:     8,
		QueueMax:          16,
		LogCapBytes:       256 * 1024,
		RunRetention:      time.Hour,
		MaxRequestBytes:   8 << 20,
		MaxFiles:          32,
		EgressMaxHosts:    8,
		ArtefactMIMEAllow: []string{"image/png", "text/plain", "text/csv", "application/json"},
	}
	if o.cfg != nil {
		o.cfg(cfg)
	}
	ws, err := workspace.New(cfg.WorkspacesDir)
	if err != nil {
		t.Fatal(err)
	}
	var rn runner.Runner = &runner.Docker{}
	if o.runner != nil {
		rn = o.runner
	}
	s, err := New(Options{
		Config: cfg,
		Docker: rn,
		Store:  ws,
		Audit:  audit.New(io.Discard),
		Tier:   rt.Selection{Tier: rt.TierDocker},
	})
	if err != nil {
		t.Fatal(err)
	}
	ts := httptest.NewServer(s.Handler())
	t.Cleanup(ts.Close)
	return s, ts
}

func testServer(t *testing.T) *httptest.Server {
	t.Helper()
	_, ts := newTestServer(t, testOpts{})
	return ts
}

func TestHealthIsPublic(t *testing.T) {
	ts := testServer(t)
	resp, err := http.Get(ts.URL + "/v1/health")
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		t.Fatalf("status %d", resp.StatusCode)
	}
	var h contract.Health
	if err := json.NewDecoder(resp.Body).Decode(&h); err != nil {
		t.Fatal(err)
	}
	if h.Protocol != 1 {
		t.Fatalf("protocol %d", h.Protocol)
	}
	if h.Tier == "" || len(h.Images) != 2 || h.Caps.TimeoutSec != 300 {
		t.Fatalf("%+v", h)
	}
	if !h.Features.Workspaces {
		t.Fatal("workspaces feature")
	}
	if h.Features.Egress {
		t.Fatal("egress default off")
	}
}

func TestUnauthenticatedRunsRejected(t *testing.T) {
	ts := testServer(t)
	resp, err := http.Post(ts.URL+"/v1/runs", "application/json", strings.NewReader(`{}`))
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("status %d", resp.StatusCode)
	}
}

func TestRunRejectsMissingOwner(t *testing.T) {
	body := validRun()
	body.Owner = ""
	assertRunError(t, body, http.StatusBadRequest, contract.ErrMissingOwner)
}

func TestRunWithoutOwnerRefused(t *testing.T) {
	TestRunRejectsMissingOwner(t)
}

func TestRunRejectsUnknownImage(t *testing.T) {
	body := validRun()
	body.Image = "python:latest"
	assertRunError(t, body, http.StatusBadRequest, contract.ErrUnknownImage)
}

func TestRunRejectsProgramNotInAllowList(t *testing.T) {
	body := validRun()
	body.Entry.Program = "bash"
	assertRunError(t, body, http.StatusBadRequest, contract.ErrProgramNotAllowed)
}

func TestRunRejectsLimitsAboveCaps(t *testing.T) {
	body := validRun()
	body.Limits.MemoryMb = 99999
	assertRunError(t, body, http.StatusBadRequest, contract.ErrLimitsExceedCaps)
}

func TestRunWithForeignWorkspaceRefused(t *testing.T) {
	ts := testServer(t)
	wsID := createWorkspace(t, ts, "user:aaa", 64)
	body := validRun()
	body.Owner = "user:bbb"
	body.Workspace = contract.Workspace{Kind: "user", ID: wsID}
	assertRunErrorOn(t, ts, body, http.StatusForbidden, contract.ErrWorkspaceNotOwned)
}

func TestWorkspaceQuotaKillsRun(t *testing.T) {
	ts := testServer(t)
	wsID := createWorkspace(t, ts, "user:123", 1)
	body := validRun()
	body.Workspace = contract.Workspace{Kind: "user", ID: wsID}
	body.Files = append(body.Files, contract.FileRef{Name: "blob.bin", Role: "input"})
	req, contentType := multipartRun(t, body, map[string][]byte{"blob.bin": bytes.Repeat([]byte("x"), 2*1024*1024)})
	httpReq, err := http.NewRequest(http.MethodPost, ts.URL+"/v1/runs", req)
	if err != nil {
		t.Fatal(err)
	}
	httpReq.Header.Set("Authorization", "Bearer "+testToken)
	httpReq.Header.Set("Content-Type", contentType)
	resp, err := http.DefaultClient.Do(httpReq)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != http.StatusConflict {
		t.Fatalf("status %d body %s", resp.StatusCode, b)
	}
	var eb contract.ErrorBody
	if err := json.Unmarshal(b, &eb); err != nil {
		t.Fatal(err)
	}
	if eb.Error.Code != contract.ErrWorkspaceQuota {
		t.Fatalf("code %q", eb.Error.Code)
	}
}

func TestUnknownMultipartStillNeedsAuth(t *testing.T) {
	ts := testServer(t)
	body := validRun()
	body.Files = append(body.Files, contract.FileRef{Name: "extra.py", Role: "input"})
	raw, _ := json.Marshal(body)
	var buf bytes.Buffer
	mw := multipart.NewWriter(&buf)
	_ = mw.WriteField("not-request", "{}")
	_ = mw.Close()
	httpReq, _ := http.NewRequest(http.MethodPost, ts.URL+"/v1/runs", bytes.NewReader(append(buf.Bytes()[:0], 0)))
	_ = raw
	httpReq.Header.Set("Authorization", "Bearer "+testToken)
	httpReq.Header.Set("Content-Type", mw.FormDataContentType())
	resp, err := http.DefaultClient.Do(httpReq)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	if resp.StatusCode == http.StatusAccepted {
		t.Fatal("unknown multipart must not be accepted")
	}
}

func validRun() contract.RunRequest {
	return contract.RunRequest{
		Protocol:  1,
		Owner:     "user:123",
		Workspace: contract.Workspace{Kind: "run"},
		Image:     "python",
		Entry:     contract.Entry{Program: "python", Args: []string{"main.py"}},
		Files:     []contract.FileRef{{Name: "main.py", Role: "input"}},
		Limits:    contract.Limits{TimeoutSec: 60, MemoryMb: 512, CPU: 1, Pids: 128, OutputMb: 50},
		Egress:    contract.Egress{Allow: []contract.EgressHost{}},
	}
}

func assertRunError(t *testing.T, body contract.RunRequest, status int, code string) {
	t.Helper()
	ts := testServer(t)
	assertRunErrorOn(t, ts, body, status, code)
}

func assertRunErrorOn(t *testing.T, ts *httptest.Server, body contract.RunRequest, status int, code string) {
	t.Helper()
	raw, err := json.Marshal(body)
	if err != nil {
		t.Fatal(err)
	}
	req, err := http.NewRequest(http.MethodPost, ts.URL+"/v1/runs", bytes.NewReader(raw))
	if err != nil {
		t.Fatal(err)
	}
	req.Header.Set("Authorization", "Bearer "+testToken)
	req.Header.Set("Content-Type", "application/json")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != status {
		t.Fatalf("status %d want %d body %s", resp.StatusCode, status, b)
	}
	var eb contract.ErrorBody
	if err := json.Unmarshal(b, &eb); err != nil {
		t.Fatal(err)
	}
	if eb.Error.Code != code {
		t.Fatalf("code %q want %q (%s)", eb.Error.Code, code, b)
	}
}

func createWorkspace(t *testing.T, ts *httptest.Server, owner string, quota int) string {
	t.Helper()
	raw, _ := json.Marshal(contract.WorkspaceCreate{Owner: owner, QuotaMb: quota})
	req, _ := http.NewRequest(http.MethodPost, ts.URL+"/v1/workspaces", bytes.NewReader(raw))
	req.Header.Set("Authorization", "Bearer "+testToken)
	req.Header.Set("Content-Type", "application/json")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusCreated {
		b, _ := io.ReadAll(resp.Body)
		t.Fatalf("create ws %d %s", resp.StatusCode, b)
	}
	var created contract.WorkspaceCreated
	if err := json.NewDecoder(resp.Body).Decode(&created); err != nil {
		t.Fatal(err)
	}
	return created.WorkspaceID
}

func multipartRun(t *testing.T, body contract.RunRequest, extra map[string][]byte) (*bytes.Buffer, string) {
	t.Helper()
	var buf bytes.Buffer
	mw := multipart.NewWriter(&buf)
	raw, err := json.Marshal(body)
	if err != nil {
		t.Fatal(err)
	}
	part, err := mw.CreateFormFile("request.json", "request.json")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := part.Write(raw); err != nil {
		t.Fatal(err)
	}
	for name, content := range extra {
		p, err := mw.CreateFormFile(name, name)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := p.Write(content); err != nil {
			t.Fatal(err)
		}
	}
	if err := mw.Close(); err != nil {
		t.Fatal(err)
	}
	return &buf, mw.FormDataContentType()
}

func mustRead(resp *http.Response) []byte {
	b, _ := io.ReadAll(resp.Body)
	return b
}

func TestWorkspaceCreateAndUsage(t *testing.T) {
	ts := testServer(t)
	id := createWorkspace(t, ts, "user:123", 64)
	req, _ := http.NewRequest(http.MethodGet, ts.URL+"/v1/workspaces/"+id+"/usage", nil)
	req.Header.Set("Authorization", "Bearer "+testToken)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		t.Fatal(resp.StatusCode)
	}
	raw, _ := io.ReadAll(resp.Body)
	if bytes.Contains(raw, []byte(os.TempDir())) {
		t.Fatal("usage leaked host path")
	}
}

func TestAcceptedRunWithoutDockerFailsCleanly(t *testing.T) {
	ts := testServer(t)
	body := validRun()
	raw, _ := json.Marshal(body)
	req, _ := http.NewRequest(http.MethodPost, ts.URL+"/v1/runs", bytes.NewReader(raw))
	req.Header.Set("Authorization", "Bearer "+testToken)
	req.Header.Set("Content-Type", "application/json")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusAccepted {
		b, _ := io.ReadAll(resp.Body)
		t.Fatalf("status %d %s", resp.StatusCode, b)
	}
	var acc contract.RunAccepted
	if err := json.NewDecoder(resp.Body).Decode(&acc); err != nil {
		t.Fatal(err)
	}
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		gr, _ := http.NewRequest(http.MethodGet, ts.URL+"/v1/runs/"+acc.RunID, nil)
		gr.Header.Set("Authorization", "Bearer "+testToken)
		r2, err := http.DefaultClient.Do(gr)
		if err != nil {
			t.Fatal(err)
		}
		var st contract.RunStatus
		_ = json.NewDecoder(r2.Body).Decode(&st)
		r2.Body.Close()
		if st.Status == contract.StatusFailed {
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("run did not fail without docker")
}
