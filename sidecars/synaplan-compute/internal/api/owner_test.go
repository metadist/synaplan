package api

import (
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/metadist/synaplan-compute/pkg/contract"
)

// readAs performs an authenticated GET for the given owner; an empty owner
// sends no owner header.
func readAs(t *testing.T, ts *httptest.Server, path, owner string) int {
	t.Helper()
	return requestAs(t, ts, http.MethodGet, path, owner)
}

// requestAs performs an authenticated request for the given owner; an empty
// owner sends no owner header.
func requestAs(t *testing.T, ts *httptest.Server, method, path, owner string) int {
	t.Helper()
	req, err := http.NewRequest(method, ts.URL+path, nil)
	if err != nil {
		t.Fatal(err)
	}
	req.Header.Set("Authorization", "Bearer "+testToken)
	if owner != "" {
		req.Header.Set(OwnerHeader, owner)
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	return resp.StatusCode
}

func TestARunIsReadOnlyByTheOwnerThatSubmittedIt(t *testing.T) {
	ts := testServer(t)
	id := submitJSON(t, ts, validRun())

	for _, suffix := range []string{"", "/logs", "/artefacts", "/artefacts/out.txt"} {
		path := "/v1/runs/" + id + suffix
		if got := readAs(t, ts, path, "user:999"); got != http.StatusNotFound {
			t.Errorf("GET %s as another owner = %d, want 404", path, got)
		}
		if got := readAs(t, ts, path, ""); got != http.StatusBadRequest {
			t.Errorf("GET %s without an owner = %d, want 400", path, got)
		}
	}
	if got := readAs(t, ts, "/v1/runs/"+id, testOwner); got != http.StatusOK {
		t.Errorf("GET run as its owner = %d, want 200", got)
	}
}

func TestAFolderIsReadOnlyByItsOwner(t *testing.T) {
	ts := testServer(t)
	id := createWorkspace(t, ts, testOwner, 16)

	for _, suffix := range []string{"/usage", "/files", "/files/notes.txt"} {
		path := "/v1/workspaces/" + id + suffix
		if got := readAs(t, ts, path, "user:999"); got != http.StatusForbidden {
			t.Errorf("GET %s as another owner = %d, want 403", path, got)
		}
		if got := readAs(t, ts, path, ""); got != http.StatusBadRequest {
			t.Errorf("GET %s without an owner = %d, want 400", path, got)
		}
	}
	for _, suffix := range []string{"/usage", "/files"} {
		if got := readAs(t, ts, "/v1/workspaces/"+id+suffix, testOwner); got != http.StatusOK {
			t.Errorf("GET %s as the owner = %d, want 200", suffix, got)
		}
	}
}

func TestARunIsCancelledOnlyByTheOwnerThatSubmittedIt(t *testing.T) {
	fr := newFakeRunner()
	fr.waitDelay = 2 * time.Second
	_, ts := newTestServer(t, testOpts{runner: fr})
	id := submitJSON(t, ts, validRun())
	path := "/v1/runs/" + id

	if got := requestAs(t, ts, http.MethodDelete, path, "user:999"); got != http.StatusNotFound {
		t.Errorf("DELETE run as another owner = %d, want 404", got)
	}
	if got := requestAs(t, ts, http.MethodDelete, path, ""); got != http.StatusBadRequest {
		t.Errorf("DELETE run without an owner = %d, want 400", got)
	}
	if st, _ := getStatus(t, ts, id); st.Status == contract.StatusCancelled {
		t.Fatal("a refused cancel must leave the run alone")
	}
	if got := requestAs(t, ts, http.MethodDelete, path, testOwner); got != http.StatusNoContent {
		t.Errorf("DELETE run as its owner = %d, want 204", got)
	}
	if st, _ := getStatus(t, ts, id); st.Status != contract.StatusCancelled {
		t.Errorf("status after the owner's cancel = %q, want cancelled", st.Status)
	}
}

func TestAFolderIsDeletedOnlyByItsOwner(t *testing.T) {
	ts := testServer(t)
	id := createWorkspace(t, ts, testOwner, 16)
	path := "/v1/workspaces/" + id

	if got := requestAs(t, ts, http.MethodDelete, path, "user:999"); got != http.StatusForbidden {
		t.Errorf("DELETE folder as another owner = %d, want 403", got)
	}
	if got := requestAs(t, ts, http.MethodDelete, path, ""); got != http.StatusBadRequest {
		t.Errorf("DELETE folder without an owner = %d, want 400", got)
	}
	if got := readAs(t, ts, path+"/usage", testOwner); got != http.StatusOK {
		t.Fatalf("folder after refused deletes = %d, want 200", got)
	}
	if got := requestAs(t, ts, http.MethodDelete, path, testOwner); got != http.StatusNoContent {
		t.Errorf("DELETE folder as its owner = %d, want 204", got)
	}
	if got := readAs(t, ts, path+"/usage", testOwner); got != http.StatusNotFound {
		t.Errorf("folder after the owner's delete = %d, want 404", got)
	}
}
