package api

import (
	"encoding/json"
	"net/http"

	"github.com/metadist/synaplan-compute/pkg/contract"
)

func writeError(w http.ResponseWriter, status int, code, message string, details any) {
	w.Header().Set("Content-Type", "application/problem+json")
	if status == http.StatusTooManyRequests {
		w.Header().Set("Retry-After", "5")
	}
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(contract.ErrorBody{
		Error: contract.ErrorDetail{Code: code, Message: message, Details: details},
	})
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}
