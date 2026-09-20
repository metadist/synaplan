#!/usr/bin/env python3
"""Rewrite internal/images/map.go to local-registry digest refs.

Used by compute-nightly.yml after `make images` (tags :local) and a push
to 127.0.0.1:5000. Fails if neither the published GHCR digest form nor
the old placeholder expression is found, so a map.go shape change cannot
silently no-op the way the inlined strings.Repeat rewrite did after the
1.0.0 pin.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

DIGEST_RE = re.compile(r"^sha256:[0-9a-f]{64}$")

# Current published pins, already-rewritten nightly refs, then the
# pre-1.0.0 placeholder expression the workflow used to search for.
PYTHON_PATTERNS = (
    re.compile(
        r'"(?:ghcr\.io/metadist/synaplan-compute-python|127\.0\.0\.1:5000/compute-python)@sha256:[0-9a-fA-F]{64}"'
    ),
    re.compile(
        r'"ghcr\.io/metadist/synaplan-compute-python@sha256:" \+ strings\.Repeat\("a", 64\)'
    ),
)
NODE_PATTERNS = (
    re.compile(
        r'"(?:ghcr\.io/metadist/synaplan-compute-node|127\.0\.0\.1:5000/compute-node)@sha256:[0-9a-fA-F]{64}"'
    ),
    re.compile(
        r'"ghcr\.io/metadist/synaplan-compute-node@sha256:" \+ strings\.Repeat\("b", 64\)'
    ),
)


def require_digest(value: str, label: str) -> str:
    if not DIGEST_RE.fullmatch(value):
        raise ValueError(f"{label} digest {value!r} is not sha256:<64 hex>")
    return value


def replace_one(text: str, patterns: tuple[re.Pattern[str], ...], replacement: str, label: str) -> str:
    for pattern in patterns:
        updated, count = pattern.subn(replacement, text, count=1)
        if count == 1:
            return updated
    raise ValueError(
        f"{label} image ref not found in map.go; nightly pin is stale "
        "(expected a ghcr.io digest pin, a 127.0.0.1:5000 digest pin, "
        "or the pre-1.0.0 strings.Repeat placeholder)"
    )


def pin_map(text: str, python_digest: str, node_digest: str) -> str:
    pyid = require_digest(python_digest, "python")
    nodeid = require_digest(node_digest, "node")
    text = replace_one(
        text,
        PYTHON_PATTERNS,
        f'"127.0.0.1:5000/compute-python@{pyid}"',
        "python",
    )
    return replace_one(
        text,
        NODE_PATTERNS,
        f'"127.0.0.1:5000/compute-node@{nodeid}"',
        "node",
    )


def check_map(text: str) -> None:
    """Fail if the current map cannot be rewritten."""
    pin_map(
        text,
        "sha256:" + "a" * 64,
        "sha256:" + "b" * 64,
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--map", required=True, help="path to internal/images/map.go")
    parser.add_argument("--python", help="RepoDigest id (sha256:hex)")
    parser.add_argument("--node", help="RepoDigest id (sha256:hex)")
    parser.add_argument(
        "--check",
        action="store_true",
        help="verify the map is rewritable; do not write",
    )
    args = parser.parse_args(argv)

    path = Path(args.map)
    text = path.read_text(encoding="utf-8")
    if args.check:
        try:
            check_map(text)
        except ValueError as exc:
            print(exc, file=sys.stderr)
            return 1
        print(f"pin-nightly-map: {path} is rewritable")
        return 0

    if not args.python or not args.node:
        print("--python and --node are required unless --check", file=sys.stderr)
        return 2
    try:
        updated = pin_map(text, args.python, args.node)
    except ValueError as exc:
        print(exc, file=sys.stderr)
        return 1
    path.write_text(updated, encoding="utf-8")
    print(f"pinned {path} to 127.0.0.1:5000 RepoDigests")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
