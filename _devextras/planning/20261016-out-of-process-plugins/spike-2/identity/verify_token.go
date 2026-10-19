// Review spike (2026-10-18) — not product code. Verifies the 02_contracts.md §3
// call-token vector the way a Go service could, with the standard library only.
//
//	go run verify_token.go vector.json
//
// A real service checks the clock with time.Now(); the vector pins "now".
package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"math/big"
	"os"
	"strings"
)

const (
	tokenType = "synaplan-call+jwt"
	now       = int64(1792130030)
	leeway    = int64(30)
)

type jwk struct {
	Kid string `json:"kid"`
	X   string `json:"x"`
	Y   string `json:"y"`
}

type claims struct {
	Aud string `json:"aud"`
	Sub string `json:"sub"`
	Iat int64  `json:"iat"`
	Exp int64  `json:"exp"`
	Htm string `json:"htm"`
	Htu string `json:"htu"`
	Bh  string `json:"bh"`
}

type vector struct {
	Jwks struct {
		Keys []jwk `json:"keys"`
	} `json:"jwks"`
	Request struct {
		Method string `json:"method"`
		URL    string `json:"url"`
		Body   string `json:"body"`
	} `json:"request"`
	Claims claims `json:"claims"`
	Token  string `json:"token"`
}

var b64 = base64.RawURLEncoding

func verify(keys []jwk, token, audience, method, url string, body []byte, at int64) (*claims, error) {
	parts := strings.Split(token, ".")
	if len(parts) != 3 {
		return nil, errors.New("not a JWT")
	}
	rawHeader, err := b64.DecodeString(parts[0])
	if err != nil {
		return nil, err
	}
	var header struct{ Typ, Alg, Kid string }
	if err := json.Unmarshal(rawHeader, &header); err != nil {
		return nil, err
	}
	if header.Typ != tokenType || header.Alg != "ES256" {
		return nil, errors.New("wrong typ or alg")
	}
	var key *ecdsa.PublicKey
	for _, k := range keys {
		if k.Kid == header.Kid {
			x, errX := b64.DecodeString(k.X)
			y, errY := b64.DecodeString(k.Y)
			if errX != nil || errY != nil {
				return nil, errors.New("bad key")
			}
			key = &ecdsa.PublicKey{Curve: elliptic.P256(), X: new(big.Int).SetBytes(x), Y: new(big.Int).SetBytes(y)}
		}
	}
	if key == nil {
		return nil, errors.New("unknown key id")
	}
	sig, err := b64.DecodeString(parts[2])
	if err != nil || len(sig) != 64 {
		return nil, errors.New("bad signature encoding")
	}
	digest := sha256.Sum256([]byte(parts[0] + "." + parts[1]))
	if !ecdsa.Verify(key, digest[:], new(big.Int).SetBytes(sig[:32]), new(big.Int).SetBytes(sig[32:])) {
		return nil, errors.New("signature verification failed")
	}
	rawClaims, err := b64.DecodeString(parts[1])
	if err != nil {
		return nil, err
	}
	var c claims
	if err := json.Unmarshal(rawClaims, &c); err != nil {
		return nil, err
	}
	bodyHash := sha256.Sum256(body)
	switch {
	case c.Aud != audience:
		return nil, errors.New("wrong audience")
	case at > c.Exp+leeway || c.Iat > at+leeway:
		return nil, errors.New("expired or not yet valid")
	case c.Htm != method || c.Htu != url:
		return nil, errors.New("wrong method or URL")
	case c.Bh != b64.EncodeToString(bodyHash[:]):
		return nil, errors.New("body hash mismatch")
	}
	return &c, nil
}

func main() {
	raw, err := os.ReadFile(os.Args[1])
	if err != nil {
		panic(err)
	}
	var v vector
	if err := json.Unmarshal(raw, &v); err != nil {
		panic(err)
	}
	parts := strings.Split(v.Token, ".")
	payload, _ := b64.DecodeString(parts[1])
	forgedPayload := strings.Replace(string(payload), v.Claims.Sub, "u_"+strings.Repeat("0", 32), 1)
	forged := parts[0] + "." + b64.EncodeToString([]byte(forgedPayload)) + "." + parts[2]
	body := []byte(v.Request.Body)
	cases := []struct {
		label, token, audience, method string
		body                           []byte
		at                             int64
		expectOk                       bool
	}{
		{"valid", v.Token, v.Request.URL, v.Request.Method, body, now, true},
		{"changed body", v.Token, v.Request.URL, v.Request.Method, []byte(strings.Replace(v.Request.Body, "hello", "HELLO", 1)), now, false},
		{"other audience", v.Token, "http://other-tool:8000/mcp", v.Request.Method, body, now, false},
		{"other method", v.Token, v.Request.URL, "DELETE", body, now, false},
		{"forged subject", forged, v.Request.URL, v.Request.Method, body, now, false},
		{"expired (now + 170 s)", v.Token, v.Request.URL, v.Request.Method, body, now + 170, false},
	}
	failed := 0
	for _, tc := range cases {
		_, err := verify(v.Jwks.Keys, tc.token, tc.audience, tc.method, v.Request.URL, tc.body, tc.at)
		ok := (err == nil) == tc.expectOk
		if !ok {
			failed++
		}
		outcome := " (accepted)"
		if err != nil {
			outcome = " (refused: " + err.Error() + ")"
		}
		status := "as expected"
		if !ok {
			status = "UNEXPECTED"
		}
		fmt.Printf("go     %-24s %s%s\n", tc.label+":", status, outcome)
	}
	if failed > 0 {
		os.Exit(1)
	}
}
