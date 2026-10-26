#!/usr/bin/env python3
"""Time to first text token through the gateway, against test-model.

One full stream checks that the answer is the canned test-provider text, so a
mis-routed call cannot continue into a paid provider. The timed runs stop at
the first text delta. The API key is read from SYNAPLAN_API_KEY and never
printed.

Usage:
  gateway_ttft.py --base http://synaplan-backend:8090 --runs 5 --expect-canned
"""

from __future__ import annotations

import argparse
import json
import os
import re
import statistics
import time
import urllib.parse
import http.client


MIN_CANNED_SECONDS = 5.0


def scrub(text: str) -> str:
    return re.sub(r"sk_[A-Za-z0-9]+", "sk_redacted", text)[:240]


def percentile(values: list[float], pct: float) -> float:
    ordered = sorted(values)
    index = min(len(ordered) - 1, max(0, round(pct / 100 * (len(ordered) - 1))))
    return ordered[index]


def connect(base: urllib.parse.ParseResult) -> http.client.HTTPConnection:
    return http.client.HTTPConnection(base.hostname, base.port, timeout=120)


def text_of(event: dict) -> str:
    parts: list[str] = []
    for choice in event.get("choices") or []:
        content = (choice.get("delta") or {}).get("content")
        if isinstance(content, str) and content:
            parts.append(content)
    return "".join(parts)


def read_stream(base, key: str, model: str, prompt: str, stop_at_text: bool) -> dict:
    body = json.dumps({
        "model": model,
        "stream": True,
        "max_tokens": 64,
        "messages": [{"role": "user", "content": prompt}],
    })
    started = time.monotonic()
    record: dict = {"status": None, "ttft": None, "duration": None, "text": "", "done": False, "error": None, "first_line": ""}
    try:
        conn = connect(base)
        conn.request("POST", "/v1/chat/completions", body=body, headers={
            "Authorization": f"Bearer {key}",
            "Content-Type": "application/json",
            "Accept": "text/event-stream",
        })
        resp = conn.getresponse()
        record["status"] = resp.status
        if resp.status != 200:
            record["error"] = f"HTTP {resp.status}"
            resp.read()
            conn.close()
            record["duration"] = time.monotonic() - started
            return record
        while True:
            line = resp.readline()
            if not line:
                break
            if not line.startswith(b"data:"):
                if not record["first_line"] and line.strip():
                    record["first_line"] = line.decode("utf-8", "replace")[:160]
                continue
            payload = line[5:].strip()
            if payload == b"[DONE]":
                record["done"] = True
                break
            try:
                event = json.loads(payload)
            except json.JSONDecodeError:
                continue
            if "error" in event:
                err = event["error"]
                message = err.get("message") if isinstance(err, dict) else str(err)
                record["error"] = scrub(str(message))
                break
            piece = text_of(event)
            if piece:
                if record["ttft"] is None:
                    record["ttft"] = time.monotonic() - started
                record["text"] += piece
                if stop_at_text:
                    break
        conn.close()
    except Exception as exc:  # noqa: BLE001 - the harness records the failure
        record["error"] = type(exc).__name__
    record["duration"] = time.monotonic() - started
    return record


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", default="http://synaplan-backend:8090")
    parser.add_argument("--model", default="test-model")
    parser.add_argument("--prompt", default="hello")
    parser.add_argument("--runs", type=int, default=5)
    parser.add_argument("--expect-canned", action="store_true")
    args = parser.parse_args()

    key = os.environ.get("SYNAPLAN_API_KEY", "")
    if not key:
        print(json.dumps({"error": "SYNAPLAN_API_KEY is not set"}))
        return 1
    base = urllib.parse.urlparse(args.base)

    canned = read_stream(base, key, args.model, args.prompt, stop_at_text=False)
    is_canned = "demo mode" in canned["text"].lower()
    if args.expect_canned and not is_canned:
        print(json.dumps({
            "canned": False,
            "status": canned["status"],
            "error": canned["error"] or "response was not the test-provider text",
            "duration_s": round(canned["duration"] or 0, 3),
            "text_len": len(canned["text"]),
            "text_prefix": canned["text"][:120],
            "first_line": canned["first_line"],
        }))
        return 2
    if args.expect_canned and (canned["duration"] or 0) < MIN_CANNED_SECONDS:
        print(json.dumps({
            "canned": True,
            "error": "stream finished too fast for the 400 ms chunk delay",
            "duration_s": round(canned["duration"] or 0, 3),
        }))
        return 3

    samples: list[float] = []
    failed = 0
    if canned["ttft"] is not None and not canned["error"]:
        samples.append(canned["ttft"])
    else:
        failed += 1
    for _ in range(max(0, args.runs - 1)):
        timed = read_stream(base, key, args.model, args.prompt, stop_at_text=True)
        if timed["ttft"] is None or timed["error"]:
            failed += 1
        else:
            samples.append(timed["ttft"])

    if not samples:
        print(json.dumps({"canned": is_canned, "ok": 0, "failed": failed, "error": "no time-to-first-token sample"}))
        return 1

    print(json.dumps({
        "canned": is_canned,
        "full_duration_s": round(canned["duration"] or 0, 3),
        "ok": len(samples),
        "failed": failed,
        "ttft_p50_ms": round(statistics.median(samples) * 1000, 3),
        "ttft_p95_ms": round(percentile(samples, 95) * 1000, 3),
    }))
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
