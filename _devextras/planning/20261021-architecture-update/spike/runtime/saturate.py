#!/usr/bin/env python3
"""Hold N streaming AI requests open and measure what happens to everyone else.

Stdlib only. Against a running Synaplan stack:

  * N concurrent streams through the OpenAI-compatible gateway
    (`POST /v1/chat/completions`, `stream: true`);
  * a probe loop that times a cheap PHP request (`GET /api/health`) every
    250 ms while the streams run, plus a baseline before they start;
  * a sampler that reads FrankenPHP's per-thread state from the Caddy admin
    API (`/frankenphp/threads`) once a second through `docker exec`.

The API key is read from SYNAPLAN_API_KEY or from --key-file (the JSON the
`POST /api/v1/apikeys` call returns). It is never printed.

Usage:
  saturate.py --streams 30 --model claude-haiku-4-5-20251001 --out result.json
"""

from __future__ import annotations

import argparse
import json
import os
import statistics
import subprocess
import threading
import time
import urllib.parse
import http.client


def percentile(values: list[float], pct: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    index = min(len(ordered) - 1, max(0, round(pct / 100 * (len(ordered) - 1))))
    return ordered[index]


def summary(values: list[float]) -> dict[str, float]:
    if not values:
        return {"n": 0}
    return {
        "n": len(values),
        "p50": round(percentile(values, 50), 3),
        "p95": round(percentile(values, 95), 3),
        "max": round(max(values), 3),
        "mean": round(statistics.fmean(values), 3),
    }


def connect(base: urllib.parse.ParseResult, timeout: float) -> http.client.HTTPConnection:
    cls = http.client.HTTPSConnection if base.scheme == "https" else http.client.HTTPConnection
    return cls(base.hostname, base.port, timeout=timeout)


def run_stream(base, key, model, prompt, max_tokens, record, start_gate):
    start_gate.wait()
    body = json.dumps({
        "model": model,
        "stream": True,
        "max_tokens": max_tokens,
        "messages": [{"role": "user", "content": prompt}],
    })
    started = time.monotonic()
    record["started"] = started
    try:
        conn = connect(base, timeout=600)
        conn.request("POST", "/v1/chat/completions", body=body, headers={
            "Authorization": f"Bearer {key}",
            "Content-Type": "application/json",
            "Accept": "text/event-stream",
        })
        resp = conn.getresponse()
        record["status"] = resp.status
        chunks = 0
        first = None
        while True:
            line = resp.readline()
            if not line:
                break
            if line.startswith(b"data:"):
                if first is None:
                    first = time.monotonic()
                chunks += 1
                if line.strip() == b"data: [DONE]":
                    break
        record["ttfb"] = (first - started) if first else None
        record["chunks"] = chunks
        conn.close()
    except Exception as exc:  # noqa: BLE001 - a spike records every failure
        record["error"] = f"{type(exc).__name__}: {exc}"
    record["duration"] = time.monotonic() - started


def probe_once(base, path) -> tuple[float, int]:
    started = time.monotonic()
    conn = connect(base, timeout=120)
    conn.request("GET", path)
    resp = conn.getresponse()
    resp.read()
    conn.close()
    return time.monotonic() - started, resp.status


def probe_loop(base, path, stop, samples):
    while not stop.is_set():
        try:
            latency, status = probe_once(base, path)
            samples.append({"t": time.monotonic(), "latency": latency, "status": status})
        except Exception as exc:  # noqa: BLE001
            samples.append({"t": time.monotonic(), "error": str(exc)})
        stop.wait(0.25)


ADMIN_URL = ""


def admin_get(path: str) -> str:
    """Read the Caddy admin API, over HTTP when --admin-url is set, else through docker exec."""
    if ADMIN_URL:
        parsed = urllib.parse.urlparse(ADMIN_URL)
        conn = connect(parsed, timeout=10)
        conn.request("GET", path)
        body = conn.getresponse().read().decode()
        conn.close()
        return body
    out = subprocess.run(
        ["docker", "exec", CONTAINER, "curl", "-s", f"localhost:2019{path}"],
        capture_output=True, text=True, timeout=10, check=False,
    )
    return out.stdout


CONTAINER = "synaplan-backend"


def read_threads(_container=None) -> list[dict]:
    try:
        return json.loads(admin_get("/frankenphp/threads")).get("ThreadDebugStates", [])
    except (json.JSONDecodeError, OSError):
        return []


def process_rss_mb() -> float:
    for line in admin_get("/metrics").splitlines():
        if line.startswith("process_resident_memory_bytes"):
            return round(float(line.split()[-1]) / 1e6, 1)
    return 0.0


def sampler_loop(container, stop, samples):
    while not stop.is_set():
        threads = read_threads(container)
        if threads:
            busy = [t for t in threads if t.get("IsBusy")]
            samples.append({
                "t": time.monotonic(),
                "total": len(threads),
                "busy": len(busy),
                "busy_mem_mb": [round(t.get("MemoryUsage", 0) / 1e6, 1) for t in busy],
                "busy_uris": sorted({t.get("CurrentURI", "") for t in busy}),
            })
        stop.wait(1.0)


def container_stats(container) -> str:
    if ADMIN_URL:
        return f"frankenphp rss {process_rss_mb()} MB"
    out = subprocess.run(
        ["docker", "stats", "--no-stream", "--format", "{{.MemUsage}} {{.CPUPerc}}", container],
        capture_output=True, text=True, timeout=30, check=False,
    )
    return out.stdout.strip()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base", default="http://localhost:8000")
    parser.add_argument("--key-file", default="/tmp/syn-key.json")
    parser.add_argument("--model", default="test-model")
    parser.add_argument("--streams", type=int, default=10)
    parser.add_argument("--max-tokens", type=int, default=1200)
    parser.add_argument("--prompt", default="Write a 700-word essay on the history of lighthouses.")
    parser.add_argument("--probe-path", default="/api/health")
    parser.add_argument("--container", default="synaplan-backend")
    parser.add_argument("--admin-url", default="", help="Caddy admin API over HTTP, e.g. http://synaplan-backend:2020")
    parser.add_argument("--out", default="")
    args = parser.parse_args()

    global ADMIN_URL, CONTAINER
    ADMIN_URL = args.admin_url
    CONTAINER = args.container

    key = os.environ.get("SYNAPLAN_API_KEY")
    if not key:
        with open(args.key_file, encoding="utf-8") as fh:
            key = json.load(fh)["api_key"]["key"]
    base = urllib.parse.urlparse(args.base)

    baseline = []
    for _ in range(20):
        latency, _status = probe_once(base, args.probe_path)
        baseline.append(latency)
    idle_threads = read_threads(args.container)
    stats_before = container_stats(args.container)

    stop = threading.Event()
    probes: list[dict] = []
    thread_samples: list[dict] = []
    gate = threading.Event()
    records = [{} for _ in range(args.streams)]
    workers = [
        threading.Thread(target=run_stream, args=(base, key, args.model, args.prompt, args.max_tokens, rec, gate))
        for rec in records
    ]
    for w in workers:
        w.start()
    sampler = threading.Thread(target=sampler_loop, args=(args.container, stop, thread_samples))
    prober = threading.Thread(target=probe_loop, args=(base, args.probe_path, stop, probes))
    t0 = time.monotonic()
    gate.set()
    sampler.start()
    prober.start()

    stats_during = ""
    time.sleep(3)
    stats_during = container_stats(args.container)
    for w in workers:
        w.join()
    stop.set()
    prober.join()
    sampler.join()
    wall = time.monotonic() - t0

    ok = [r for r in records if r.get("status") == 200 and not r.get("error")]
    result = {
        "model": args.model,
        "streams": args.streams,
        "wall_seconds": round(wall, 2),
        "threads_total": len(idle_threads),
        "threads_busy_max": max((s["busy"] for s in thread_samples), default=0),
        "busy_thread_mem_mb": summary([m for s in thread_samples for m in s["busy_mem_mb"]]),
        "busy_uris": sorted({u for s in thread_samples for u in s["busy_uris"]}),
        "container_before": stats_before,
        "container_during": stats_during,
        "streams_ok": len(ok),
        "streams_failed": [
            {k: r.get(k) for k in ("status", "error")} for r in records if r not in ok
        ],
        "stream_ttfb_s": summary([r["ttfb"] for r in ok if r.get("ttfb") is not None]),
        "stream_duration_s": summary([r["duration"] for r in ok]),
        "probe_baseline_s": summary(baseline),
        "probe_during_s": summary([p["latency"] for p in probes if "latency" in p]),
        "probe_errors": len([p for p in probes if "error" in p]),
    }
    print(json.dumps(result, indent=2))
    if args.out:
        with open(args.out, "w", encoding="utf-8") as fh:
            json.dump({"summary": result, "threads": thread_samples, "probes": probes}, fh, indent=1)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
