// Package token is the attach token from 03 §8.
//
// token = base64url(runId, ownerKey, fromSeq, exp) + "." + base64url(HMAC-SHA256).
// The cursor is inside the signature, so a client cannot widen it.
// PHP signs the same bytes. The shared test vector is in token_test.go.
package token

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"errors"
	"strconv"
	"strings"
)

// ErrInvalid means the token is malformed, signed with another secret, or expired.
var ErrInvalid = errors.New("invalid attach token")

// Sign builds a token. exp is a unix second.
func Sign(secret, runID, ownerKey string, fromSeq int64, exp int64) string {
	payload := payload(runID, ownerKey, fromSeq, exp)
	return b64([]byte(payload)) + "." + b64(mac(secret, payload))
}

// Verify checks the signature, the expiry, and that the cursor was not widened.
func Verify(secret, token, runID, ownerKey string, fromSeq int64, now int64) error {
	parts := strings.Split(token, ".")
	if len(parts) != 2 {
		return ErrInvalid
	}
	raw, err := b64dec(parts[0])
	if err != nil {
		return ErrInvalid
	}
	got, err := b64dec(parts[1])
	if err != nil {
		return ErrInvalid
	}
	fields := strings.Split(string(raw), "\n")
	if len(fields) != 4 || fields[0] != runID || fields[1] != ownerKey {
		return ErrInvalid
	}
	seq, err := strconv.ParseInt(fields[2], 10, 64)
	if err != nil || seq != fromSeq {
		return ErrInvalid
	}
	exp, err := strconv.ParseInt(fields[3], 10, 64)
	if err != nil || now > exp {
		return ErrInvalid
	}
	want := mac(secret, string(raw))
	if !hmac.Equal(got, want) {
		return ErrInvalid
	}
	return nil
}

func payload(runID, ownerKey string, fromSeq, exp int64) string {
	return runID + "\n" + ownerKey + "\n" + strconv.FormatInt(fromSeq, 10) + "\n" + strconv.FormatInt(exp, 10)
}

func mac(secret, payload string) []byte {
	h := hmac.New(sha256.New, []byte(secret))
	_, _ = h.Write([]byte(payload))
	return h.Sum(nil)
}

func b64(raw []byte) string {
	return base64.RawURLEncoding.EncodeToString(raw)
}

func b64dec(s string) ([]byte, error) {
	return base64.RawURLEncoding.DecodeString(s)
}
