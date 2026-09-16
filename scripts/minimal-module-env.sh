#!/usr/bin/env bash
# Apply the Intermezzo S4 minimal-module overlay.
#
# Symfony dotenv does not override variables already in the process environment,
# so this file must be exported as real env (GitHub Actions `GITHUB_ENV`,
# compose `environment:`, or `set -a; source …` in a shell) — appending
# `backend/.env.minimal` after `.env.test` is a no-op.
#
# Usage:
#   source scripts/minimal-module-env.sh          # export into the current shell
#   scripts/minimal-module-env.sh --github-env    # append to $GITHUB_ENV
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FILE="${ROOT}/backend/.env.minimal"

if [[ ! -f "${FILE}" ]]; then
  echo "missing ${FILE}" >&2
  exit 1
fi

GITHUB_ENV_MODE=0
if [[ "${1:-}" == "--github-env" ]]; then
  GITHUB_ENV_MODE=1
fi

while IFS= read -r line || [[ -n "${line}" ]]; do
  [[ -z "${line}" || "${line}" =~ ^[[:space:]]*# ]] && continue
  key="${line%%=*}"
  value="${line#*=}"
  if [[ "${GITHUB_ENV_MODE}" -eq 1 ]]; then
    printf '%s=%s\n' "${key}" "${value}" >> "${GITHUB_ENV:?GITHUB_ENV is not set}"
  else
    export "${key}=${value}"
  fi
done < "${FILE}"
