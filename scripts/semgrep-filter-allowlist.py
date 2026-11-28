#!/usr/bin/env python3
"""Drop Semgrep findings that are on the dummy-secret list, then report the rest.

A finding is dropped only when every provider-shaped token on its matched
line is listed in .github/dummy-secrets.allow. A line that also carries an
unlisted token still fails the scan. The match text itself is never printed.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

TOKEN = re.compile(
    r"sk_test_[A-Za-z0-9]{8,}"
    r"|sk_live_[A-Za-z0-9]{8,}"
    r"|whsec_[A-Za-z0-9_]{8,}"
    r"|sk-proj-[A-Za-z0-9]{8,}"
    r"|sk-[A-Za-z0-9]{16,}"
    r"|gsk_[A-Za-z0-9]{8,}"
    r"|ya29\.[A-Za-z0-9_-]{8,}"
    r"|\$2[aby]\$\d{2}\$[A-Za-z0-9./]{20,}"
)


def load_allowlist(path: Path) -> set[str]:
    values: set[str] = set()
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        values.add(line)
    return values


def snippet_of(result: dict) -> str:
    location = (result.get("locations") or [{}])[0].get("physicalLocation") or {}
    region = location.get("region") or {}
    snippet = region.get("snippet") or {}
    return str(snippet.get("text") or "")


def listed(result: dict, allow: set[str]) -> bool:
    rule = str(result.get("ruleId") or "")
    # A PHP finding on a line that also mentions a dummy must still fail.
    if "secret" not in rule.lower():
        return False
    tokens = TOKEN.findall(snippet_of(result))
    return bool(tokens) and all(token in allow for token in tokens)


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: semgrep-filter-allowlist.py SARIF ALLOWLIST", file=sys.stderr)
        return 2
    sarif_path = Path(sys.argv[1])
    allow = load_allowlist(Path(sys.argv[2]))
    data = json.loads(sarif_path.read_text(encoding="utf-8"))
    for run in data.get("runs") or []:
        run["results"] = [
            result
            for result in (run.get("results") or [])
            if not listed(result, allow)
        ]
    sarif_path.write_text(json.dumps(data), encoding="utf-8")

    remaining = 0
    for run in data.get("runs") or []:
        for result in run.get("results") or []:
            remaining += 1
            location = (result.get("locations") or [{}])[0].get("physicalLocation") or {}
            path = (location.get("artifactLocation") or {}).get("uri") or "?"
            line = (location.get("region") or {}).get("startLine") or "?"
            rule = result.get("ruleId") or "?"
            print(f"{path}:{line}: {rule}", file=sys.stderr)
    return 1 if remaining else 0


if __name__ == "__main__":
    raise SystemExit(main())
