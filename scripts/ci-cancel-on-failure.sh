#!/usr/bin/env bash
# Cancel the rest of this workflow run after one test job has failed.
#
# Pull requests and pushes that do not publish (develop) have nothing in
# flight except tests, so the remaining jobs are pure waste. Main and version
# tags are left alone: the arm64 image and the transcriber manifest publish
# while the tests are still running, and cancelling the run would cut that
# publish off. E2E shards stop each other separately, on every event.
#
# The All Checks Passed job uses if: always(), so a cancelled run still
# reports a red required check. Fork pull requests receive a read-only token;
# the cancel call then warns and that run keeps going.
set -euo pipefail

event="${GITHUB_EVENT_NAME:-}"
ref="${GITHUB_REF:-}"

cancel=false
case "$event" in
  pull_request) cancel=true ;;
  push)
    case "$ref" in
      refs/heads/main | refs/tags/*) cancel=false ;;
      *) cancel=true ;;
    esac
    ;;
esac

if [ "$cancel" != true ]; then
  echo "Not cancelling this run (${event:-unknown} ${ref:-unknown}): a publish may be in progress."
  exit 0
fi

if [ -z "${GITHUB_RUN_ID:-}" ]; then
  echo "::warning::GITHUB_RUN_ID is unset; the rest of the run was not cancelled."
  exit 0
fi

if ! gh run cancel "$GITHUB_RUN_ID"; then
  echo "::warning::Could not cancel the rest of this run."
fi
