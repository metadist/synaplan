#!/usr/bin/env bash

# Resolves a just-pushed tag to the digest of the multi-arch index it names,
# and refuses to answer until that index is the one this run built.
#
# Usage: resolve-manifest-digest.sh <image-ref> <linux/amd64 digest> <linux/arm64 digest>
#
# GHCR answers a tag from a cache that can still hold the previous value for a
# moment after a push, so `imagetools create --tag latest` followed immediately
# by `imagetools inspect latest` can report the PREVIOUS release. That is not a
# cosmetic race: the publish job passes that digest on as its job output, and
# release-version-pin writes it into the Umbrel package as the pinned
# tag@digest. A stale read there pins the store submission to the wrong image.
#
# A tag lookup alone cannot tell a fresh answer from a cached one, so every
# candidate digest is re-fetched by digest — immutable, therefore never
# cached-stale — and accepted only if it names the amd64 image that passed the
# gates and the arm64 image built next to it.

set -Eeuo pipefail

image="${1:?image reference required}"
expected_amd64="${2:?linux/amd64 digest required}"
expected_arm64="${3:?linux/arm64 digest required}"

# Observed staleness is on the order of a second; the ceiling only exists so a
# registry that never converges fails the build instead of hanging on it.
timeout_seconds="${MANIFEST_TIMEOUT_SECONDS:-60}"
poll_interval_seconds="${MANIFEST_POLL_INTERVAL_SECONDS:-2}"

child_digest() {
    local raw="$1" architecture="$2"
    jq -r --arg architecture "$architecture" '
        [.manifests[]?
            | select(.platform.os == "linux" and .platform.architecture == $architecture)
            | .digest]
        | first // ""
    ' <<<"$raw"
}

deadline=$((SECONDS + timeout_seconds))
candidate=""
amd64=""
arm64=""

while :; do
    candidate="$(
        docker buildx imagetools inspect "$image" \
            --format '{{ .Manifest.Digest }}' 2>/dev/null || true
    )"

    if [[ -n "$candidate" ]]; then
        raw="$(
            docker buildx imagetools inspect --raw "${image%:*}@${candidate}" 2>/dev/null || true
        )"
        amd64="$(child_digest "$raw" amd64)"
        arm64="$(child_digest "$raw" arm64)"

        if [[ "$amd64" == "$expected_amd64" && "$arm64" == "$expected_arm64" ]]; then
            printf '%s\n' "$candidate"
            exit 0
        fi
    fi

    if ((SECONDS >= deadline)); then
        {
            echo "::error::${image} still resolves to ${candidate:-nothing} after ${timeout_seconds}s"
            echo "::error::its linux/amd64 is ${amd64:-none} (expected ${expected_amd64})"
            echo "::error::its linux/arm64 is ${arm64:-none} (expected ${expected_arm64})"
        } >&2
        exit 1
    fi

    sleep "$poll_interval_seconds"
done
