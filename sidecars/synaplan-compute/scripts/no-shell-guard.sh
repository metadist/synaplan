#!/usr/bin/env bash
# Fail if API-side code constructs a shell string.
# The sandbox may run the program "sh" with an args array; that is not a
# shell string in the API. This guard scans cmd/, internal/, and pkg/ only.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

matches="$(grep -RnF -- 'sh -c' cmd internal pkg 2>/dev/null || true)"
if [[ -n "${matches}" ]]; then
	echo "no-shell-guard: found a shell string in API-side paths:" >&2
	echo "${matches}" >&2
	exit 1
fi

echo "no-shell-guard: ok (no shell string in cmd/, internal/, pkg/)"
