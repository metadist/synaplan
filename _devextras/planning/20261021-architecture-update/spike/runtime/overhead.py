#!/usr/bin/env python3
"""Time to first token: the provider directly vs. the same call through Synaplan.

The difference is what the platform adds before the provider answers today
(auth, routing, credentials, usage bookkeeping, translation). It is the
baseline against which the pre-call hooks of the one AI path are budgeted.

Provider keys are read from backend/.env of the checkout given with
--env-file and never printed. The Synaplan key comes from SYNAPLAN_API_KEY or
--key-file.

Usage:
  overhead.py --runs 10 --env-file ../../../../../backend/.env
"""

from __future__ import annotations

import argparse
import http.client
import json
import os
import statistics
import time
import urllib.parse

PROMPT = "Reply with one short sentence about the sea."

CASES = {
    "groq": {
        "direct_url": "https://api.groq.com/openai/v1/chat/completions",
        "env": "GROQ_API_KEY",
        "model": "openai/gpt-oss-20b",
        "style": "openai",
    },
    "anthropic": {
        "direct_url": "https://api.anthropic.com/v1/messages",
        "env": "ANTHROPIC_API_KEY",
        "model": "claude-haiku-4-5-20251001",
        "style": "anthropic",
    },
}


def read_env(path: str) -> dict[str, str]:
    values: dict[str, str] = {}
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            name, value = line.split("=", 1)
            values[name.strip()] = value.strip().strip('"').strip("'")
    return values


def ttfb(url: str, headers: dict[str, str], body: dict) -> tuple[float, float, int]:
    parsed = urllib.parse.urlparse(url)
    cls = http.client.HTTPSConnection if parsed.scheme == "https" else http.client.HTTPConnection
    conn = cls(parsed.hostname, parsed.port, timeout=120)
    started = time.monotonic()
    conn.request("POST", parsed.path, body=json.dumps(body), headers={**headers, "Content-Type": "application/json"})
    resp = conn.getresponse()
    first = None
    while True:
        line = resp.readline()
        if not line:
            break
        if first is None and line.startswith(b"data:") and b"[DONE]" not in line:
            if b"content" in line or b"text_delta" in line:
                first = time.monotonic()
    total = time.monotonic() - started
    conn.close()
    return (first - started) if first else total, total, resp.status


def body_for(style: str, model: str) -> dict:
    if style == "anthropic":
        return {"model": model, "max_tokens": 60, "stream": True,
                "messages": [{"role": "user", "content": PROMPT}]}
    return {"model": model, "max_tokens": 60, "stream": True,
            "messages": [{"role": "user", "content": PROMPT}]}


def headers_for(style: str, key: str) -> dict[str, str]:
    if style == "anthropic":
        return {"x-api-key": key, "anthropic-version": "2023-06-01"}
    return {"Authorization": f"Bearer {key}"}


def stats(values: list[float]) -> str:
    ordered = sorted(values)
    p95 = ordered[min(len(ordered) - 1, round(0.95 * (len(ordered) - 1)))]
    return f"p50={statistics.median(values):.3f}s p95={p95:.3f}s mean={statistics.fmean(values):.3f}s"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--runs", type=int, default=10)
    parser.add_argument("--env-file", required=True)
    parser.add_argument("--key-file", default="/tmp/syn-key.json")
    parser.add_argument("--base", default="http://localhost:8000")
    parser.add_argument("--cases", default="groq,anthropic")
    parser.add_argument("--skip-messages", action="store_true", help="skip /v1/messages (gateway off)")
    args = parser.parse_args()

    env = read_env(args.env_file)
    syn_key = os.environ.get("SYNAPLAN_API_KEY")
    if not syn_key:
        with open(args.key_file, encoding="utf-8") as fh:
            syn_key = json.load(fh)["api_key"]["key"]

    for name in args.cases.split(","):
        case = CASES[name]
        provider_key = env.get(case["env"], "")
        if not provider_key:
            print(f"{name}: {case['env']} not set, skipped")
            continue
        direct, via_openai, via_messages = [], [], []
        for _ in range(args.runs):
            direct.append(ttfb(case["direct_url"], headers_for(case["style"], provider_key),
                               body_for(case["style"], case["model"]))[0])
            via_openai.append(ttfb(f"{args.base}/v1/chat/completions", headers_for("openai", syn_key),
                                   body_for("openai", case["model"]))[0])
            if not args.skip_messages:
                via_messages.append(ttfb(f"{args.base}/v1/messages",
                                         {"Authorization": f"Bearer {syn_key}", "anthropic-version": "2023-06-01"},
                                         body_for("anthropic", case["model"]))[0])
        print(f"{name} ({case['model']}), {args.runs} runs, time to first token")
        print(f"  direct                 {stats(direct)}")
        print(f"  Synaplan /v1/chat      {stats(via_openai)}  (+{statistics.median(via_openai) - statistics.median(direct):.3f}s median)")
        if via_messages:
            print(f"  Synaplan /v1/messages  {stats(via_messages)}  (+{statistics.median(via_messages) - statistics.median(direct):.3f}s median)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
