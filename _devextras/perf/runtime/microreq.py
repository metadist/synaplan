#!/usr/bin/env python3
"""Throughput of short PHP requests on keep-alive connections.

A relay that keeps the long waits out of PHP still calls PHP for the short
steps of a turn (start, tool call, finish). This measures what one worker
pool serves: C concurrent keep-alive clients, each sending R requests.

Only HTTP 200 responses enter the timings; other statuses and transport
errors are counted, and the script exits non-zero when any request failed.

Usage:
  microreq.py --base http://synaplan-backend:8090 --path /api/health --clients 8 --requests 200
"""

from __future__ import annotations

import argparse
import http.client
import json
import statistics
import threading
import time
import urllib.parse


def client(base, path, count, latencies, failures, lock):
    conn = http.client.HTTPConnection(base.hostname, base.port, timeout=60)
    for _ in range(count):
        started = time.monotonic()
        try:
            conn.request("GET", path, headers={"Connection": "keep-alive"})
            resp = conn.getresponse()
            resp.read()
        except (http.client.HTTPException, OSError) as exc:
            with lock:
                failures.append(f"transport: {type(exc).__name__}")
            conn.close()
            conn = http.client.HTTPConnection(base.hostname, base.port, timeout=60)
            continue
        elapsed = time.monotonic() - started
        with lock:
            if resp.status == 200:
                latencies.append(elapsed)
            else:
                failures.append(f"HTTP {resp.status}")
    conn.close()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="http://synaplan-backend:8090")
    parser.add_argument("--path", default="/api/health")
    parser.add_argument("--clients", type=int, default=8)
    parser.add_argument("--requests", type=int, default=200)
    parser.add_argument("--json", action="store_true", help="print one JSON object on stdout")
    args = parser.parse_args()
    base = urllib.parse.urlparse(args.base)

    latencies: list[float] = []
    failures: list[str] = []
    lock = threading.Lock()
    threads = [threading.Thread(target=client, args=(base, args.path, args.requests, latencies, failures, lock))
               for _ in range(args.clients)]
    started = time.monotonic()
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    wall = time.monotonic() - started

    if not latencies:
        message = f"{args.path} clients={args.clients}: no successful request; failures: {sorted(set(failures))}"
        if args.json:
            print(json.dumps({
                "path": args.path, "clients": args.clients, "ok": 0, "failed": len(failures),
                "error": message,
            }))
        else:
            print(message)
        return 1
    ordered = sorted(latencies)
    p95 = ordered[round(0.95 * (len(ordered) - 1))]
    p50_ms = statistics.median(latencies) * 1000
    p95_ms = p95 * 1000
    rps = len(latencies) / wall if wall else 0.0
    if args.json:
        print(json.dumps({
            "path": args.path,
            "clients": args.clients,
            "ok": len(latencies),
            "failed": len(failures),
            "wall_s": round(wall, 3),
            "rps": round(rps, 1),
            "p50_ms": round(p50_ms, 3),
            "p95_ms": round(p95_ms, 3),
        }))
    else:
        print(f"{args.path} clients={args.clients} ok={len(latencies)} failed={len(failures)} wall={wall:.2f}s "
              f"rps(ok)={rps:.0f} p50={p50_ms:.1f}ms p95={p95_ms:.1f}ms")
    if failures:
        if not args.json:
            print(f"  failures: {sorted(set(failures))}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
