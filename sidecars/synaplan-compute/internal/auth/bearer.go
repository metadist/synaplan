package auth

import (
	"crypto/sha256"
	"crypto/subtle"
	"net/http"
	"strings"

	"github.com/metadist/synaplan-compute/pkg/contract"
)

const minTokenBytes = 32

// Bearer checks Authorization: Bearer with a constant-time compare over
// SHA-256 digests, so neither length nor content of the presented token
// changes the comparison time.
type Bearer struct {
	digest [sha256.Size]byte
}

// New fails if token is shorter than 32 bytes.
func New(token string) (*Bearer, error) {
	if len(token) < minTokenBytes {
		return nil, errShortToken
	}
	return &Bearer{digest: sha256.Sum256([]byte(token))}, nil
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
	got := sha256.Sum256([]byte(header[len(prefix):]))
	return subtle.ConstantTimeCompare(got[:], b.digest[:]) == 1
}

func writeUnauthorized(w http.ResponseWriter) {
	w.Header().Set("Content-Type", "application/problem+json")
	w.WriteHeader(http.StatusUnauthorized)
	_, _ = w.Write([]byte(`{"error":{"code":"` + contract.ErrUnauthorized + `","message":"unauthorized"}}`))
}
