package auth

import (
	"crypto/subtle"
	"net/http"
	"strings"

	"github.com/metadist/synaplan-compute/pkg/contract"
)

const minTokenBytes = 32

// Bearer checks Authorization: Bearer with a constant-time compare.
type Bearer struct {
	token string
}

// New fails if token is shorter than 32 bytes.
func New(token string) (*Bearer, error) {
	if len(token) < minTokenBytes {
		return nil, errShortToken
	}
	return &Bearer{token: token}, nil
}

var errShortToken = errString("COMPUTE_AUTH_TOKEN must be at least 32 bytes")

type errString string

func (e errString) Error() string { return string(e) }

// Wrap authenticates every request except GET /v1/health.
func (b *Bearer) Wrap(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodGet && (r.URL.Path == "/v1/health" || r.URL.Path == "/healthz") {
			next.ServeHTTP(w, r)
			return
		}
		if !b.Allow(r.Header.Get("Authorization")) {
			writeUnauthorized(w)
			return
		}
		next.ServeHTTP(w, r)
	})
}

// Allow reports a match using constant-time compare.
func (b *Bearer) Allow(header string) bool {
	const prefix = "Bearer "
	if !strings.HasPrefix(header, prefix) {
		return false
	}
	got := header[len(prefix):]
	if len(got) != len(b.token) {
		// Compare against token anyway so length is not a fast reject that
		// leaks through timing of the early return alone; still require equal length.
		subtle.ConstantTimeCompare([]byte(got), []byte(got))
		return false
	}
	return subtle.ConstantTimeCompare([]byte(got), []byte(b.token)) == 1
}

func writeUnauthorized(w http.ResponseWriter) {
	w.Header().Set("Content-Type", "application/problem+json")
	w.WriteHeader(http.StatusUnauthorized)
	_, _ = w.Write([]byte(`{"error":{"code":"` + contract.ErrUnauthorized + `","message":"unauthorized"}}`))
}
