#!/usr/bin/env bash
# Submit the CSV→chart example to a running synaplan-compute.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
: "${COMPUTE_URL:=http://127.0.0.1:8080}"
: "${COMPUTE_AUTH_TOKEN:?set COMPUTE_AUTH_TOKEN}"
curl -sS -H "Authorization: Bearer ${COMPUTE_AUTH_TOKEN}" \
  -F "request.json=@${here}/../fixtures-not-used.json;filename=request.json" \
  "${COMPUTE_URL}/v1/runs" || true
echo "Use tests/fixtures/compute-contract/run_request_python.json as request.json with data.csv and main.py parts."
