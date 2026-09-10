package auth

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

const testToken = "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"

func TestAllowConstantTimeMatch(t *testing.T) {
	t.Parallel()
	b, err := New(testToken)
	if err != nil {
		t.Fatal(err)
	}
	if !b.Allow("Bearer " + testToken) {
		t.Fatal("expected match")
	}
	if b.Allow("Bearer " + testToken[:31] + "B") {
		t.Fatal("wrong token must not match")
	}
	if b.Allow("Bearer short") {
		t.Fatal("short token must not match")
	}
	if b.Allow(testToken) {
		t.Fatal("missing Bearer prefix")
	}
}

func TestNewRejectsShortToken(t *testing.T) {
	t.Parallel()
	if _, err := New("too-short"); err == nil {
		t.Fatal("expected error")
	}
}

func TestHealthBypassesAuth(t *testing.T) {
	t.Parallel()
	b, err := New(testToken)
	if err != nil {
		t.Fatal(err)
	}
	inner := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"ok":true}`))
	})
	h := b.Wrap(inner)

	rec := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/v1/health", nil)
	h.ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("health: %d", rec.Code)
	}

	rec = httptest.NewRecorder()
	req = httptest.NewRequest(http.MethodPost, "/v1/runs", nil)
	h.ServeHTTP(rec, req)
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("runs without token: %d", rec.Code)
	}
}
