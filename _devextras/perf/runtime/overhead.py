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


def first_text(event: dict) -> bool:
    """True when an SSE event carries answer text (not role, metadata or an empty delta)."""
    if event.get("type") == "content_block_delta":
        delta = event.get("delta") or {}
        return delta.get("type") == "text_delta" and bool(delta.get("text"))
    for choice in event.get("choices") or []:
        if (choice.get("delta") or {}).get("content"):
            return True
    return False


CONNECTIONS: dict[tuple, http.client.HTTPConnection] = {}


def connection(parsed: urllib.parse.ParseResult) -> http.client.HTTPConnection:
    """One keep-alive connection per destination, so direct and gateway calls are both warm."""
    key = (parsed.scheme, parsed.hostname, parsed.port)
    if key not in CONNECTIONS:
        cls = http.client.HTTPSConnection if parsed.scheme == "https" else http.client.HTTPConnection
        CONNECTIONS[key] = cls(parsed.hostname, parsed.port, timeout=120)
    return CONNECTIONS[key]


def ttft(url: str, headers: dict[str, str], body: dict) -> float | None:
    """Seconds to the first text delta; None for a non-200, an error event or no text at all."""
    parsed = urllib.parse.urlparse(url)
    conn = connection(parsed)
    started = time.monotonic()
    try:
        conn.request("POST", parsed.path, body=json.dumps(body), headers={**headers, "Content-Type": "application/json"})
        resp = conn.getresponse()
    except (http.client.HTTPException, OSError):
        CONNECTIONS.pop((parsed.scheme, parsed.hostname, parsed.port), None)
        conn = connection(parsed)
        started = time.monotonic()
        conn.request("POST", parsed.path, body=json.dumps(body), headers={**headers, "Content-Type": "application/json"})
        resp = conn.getresponse()
    first = None
    failed = resp.status != 200
    while not failed:
        line = resp.readline()
        if not line:
            break
        if not line.startswith(b"data:"):
            continue
        payload = line[5:].strip()
        if payload == b"[DONE]":
            break
        try:
            event = json.loads(payload)
        except json.JSONDecodeError:
            continue
        if "error" in event or event.get("type") == "error":
            failed = True
        elif first is None and first_text(event):
            first = time.monotonic()
    resp.read()
    if failed or first is None:
        return None
    return first - started


MAX_TOKENS = 400  # room for a reasoning model (gpt-oss) to reach answer text


def body_for(style: str, model: str) -> dict:
    return {"model": model, "max_tokens": MAX_TOKENS, "stream": True,
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
    parser.add_argument("--max-tokens", type=int, default=400)
    parser.add_argument("--warmup", type=int, default=1, help="untimed calls per path to open the connections")
    args = parser.parse_args()
    global MAX_TOKENS
    MAX_TOKENS = args.max_tokens

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
        samples: dict[str, list[float]] = {"direct": [], "chat": [], "messages": []}
        failures: dict[str, int] = {"direct": 0, "chat": 0, "messages": 0}
        calls = {
            "direct": (case["direct_url"], headers_for(case["style"], provider_key), body_for(case["style"], case["model"])),
            "chat": (f"{args.base}/v1/chat/completions", headers_for("openai", syn_key), body_for("openai", case["model"])),
            "messages": (f"{args.base}/v1/messages",
                         {"Authorization": f"Bearer {syn_key}", "anthropic-version": "2023-06-01"},
                         body_for("anthropic", case["model"])),
        }
        paths = ["direct", "chat"] + ([] if args.skip_messages else ["messages"])
        for _ in range(args.warmup):
            for path in paths:
                ttft(*calls[path])
        for _ in range(args.runs):
            for path in paths:
                value = ttft(*calls[path])
                if value is None:
                    failures[path] += 1
                else:
                    samples[path].append(value)
        print(f"{name} ({case['model']}), {args.runs} runs, time to first text token")
        labels = {"direct": "direct", "chat": "Synaplan /v1/chat", "messages": "Synaplan /v1/messages"}
        for path in paths:
            if not samples[path]:
                print(f"  {labels[path]:22} no successful run ({failures[path]} failed)")
                continue
            delta = ""
            if path != "direct" and samples["direct"]:
                delta = f"  (+{statistics.median(samples[path]) - statistics.median(samples['direct']):.3f}s median)"
            print(f"  {labels[path]:22} {stats(samples[path])}  failed={failures[path]}{delta}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
