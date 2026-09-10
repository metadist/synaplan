#!/usr/bin/env bash
# Submit the CSV→chart example to a running synaplan-compute and poll it.
# request.json declares data.csv and main.py; both are sent as multipart parts
# with the same names (undeclared parts are refused with bad_file_name).
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
: "${COMPUTE_URL:=http://127.0.0.1:8080}"
: "${COMPUTE_AUTH_TOKEN:?set COMPUTE_AUTH_TOKEN}"
auth=(-H "Authorization: Bearer ${COMPUTE_AUTH_TOKEN}")

accepted="$(curl -sS "${auth[@]}" \
  -F "request.json=@${here}/request.json;type=application/json" \
  -F "data.csv=@${here}/data.csv" \
  -F "main.py=@${here}/main.py" \
  "${COMPUTE_URL}/v1/runs")"
echo "${accepted}"
run_id="$(printf '%s' "${accepted}" | sed -n 's/.*"runId":"\([^"]*\)".*/\1/p')"
[[ -n "${run_id}" ]] || exit 1

for _ in $(seq 1 120); do
  status="$(curl -sS "${auth[@]}" "${COMPUTE_URL}/v1/runs/${run_id}")"
  case "${status}" in
    *'"status":"queued"'*|*'"status":"running"'*) sleep 1 ;;
    *) echo "${status}"; break ;;
  esac
done
curl -sS "${auth[@]}" "${COMPUTE_URL}/v1/runs/${run_id}/artefacts"
echo
echo "Download: curl ${auth[*]} -o chart.png ${COMPUTE_URL}/v1/runs/${run_id}/artefacts/chart.png"
