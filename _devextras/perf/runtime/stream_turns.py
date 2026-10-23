#!/usr/bin/env python3
"""Hold N in-app chat streams open at once, signed in as one user.

Signs in through POST /api/v1/auth/login, opens one chat per stream and reads
GET /api/v1/messages/stream until the turn's terminal event. A turn counts as
done only when its `complete` event arrived. Prints one JSON object.

The password is read from SYNAPLAN_PERF_PASSWORD and never printed.
"""

from __future__ import annotations

import argparse
import http.client
import json
import os
import statistics
import threading
import time
import urllib.parse


def connect(base: urllib.parse.ParseResult, timeout: float) -> http.client.HTTPConnection:
    cls = http.client.HTTPSConnection if base.scheme == "https" else http.client.HTTPConnection
    return cls(base.hostname, base.port, timeout=timeout)


def sign_in(base: urllib.parse.ParseResult, email: str, password: str) -> str:
    conn = connect(base, 30)
    conn.request("POST", "/api/v1/auth/login", body=json.dumps({"email": email, "password": password}),
                 headers={"Content-Type": "application/json"})
    resp = conn.getresponse()
    resp.read()
    if resp.status != 200:
        raise SystemExit(f"login answered HTTP {resp.status}")
    cookies = [header.split(";", 1)[0] for name, header in resp.getheaders() if name.lower() == "set-cookie"]
    conn.close()
    if not cookies:
        raise SystemExit("login set no cookie")
    return "; ".join(cookies)


def new_chat(base: urllib.parse.ParseResult, cookie: str) -> int:
    conn = connect(base, 30)
    conn.request("POST", "/api/v1/chats", body=json.dumps({"title": "perf db connections"}),
                 headers={"Content-Type": "application/json", "Cookie": cookie})
    resp = conn.getresponse()
    body = json.loads(resp.read() or b"{}")
    conn.close()
    chat_id = (body.get("chat") or {}).get("id") or body.get("id")
    if resp.status not in (200, 201) or not chat_id:
        raise SystemExit(f"creating a chat answered HTTP {resp.status}")
    return int(chat_id)


def delete_chat(base: urllib.parse.ParseResult, cookie: str, chat_id: int) -> None:
    conn = connect(base, 30)
    conn.request("DELETE", f"/api/v1/chats/{chat_id}", headers={"Cookie": cookie})
    conn.getresponse().read()
    conn.close()


def run_turn(base, cookie, chat_id, message, gate, record):
    gate.wait()
    started = time.monotonic()
    query = urllib.parse.urlencode({"chatId": chat_id, "message": message})
    try:
        conn = connect(base, 600)
        conn.request("GET", f"/api/v1/messages/stream?{query}",
                     headers={"Cookie": cookie, "Accept": "text/event-stream"})
        resp = conn.getresponse()
        record["status"] = resp.status
        while True:
            line = resp.readline()
            if not line:
                break
            if not line.startswith(b"data:"):
                continue
            try:
                event = json.loads(line[5:])
            except json.JSONDecodeError:
                continue
            status = event.get("status")
            if status == "complete":
                record["complete"] = True
                break
            if status == "error":
                record["error"] = str(event.get("error") or event.get("message") or "error")[:200]
                break
        conn.close()
    except Exception as exc:  # noqa: BLE001 - a benchmark records every failure
        record["error"] = f"{type(exc).__name__}: {exc}"
    record["duration"] = time.monotonic() - started


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base", default="http://synaplan-backend:8090")
    parser.add_argument("--email", default="demo@synaplan.com")
    parser.add_argument("--streams", type=int, default=8)
    parser.add_argument("--message", default="Tell me a short story about a lighthouse.")
    args = parser.parse_args()

    base = urllib.parse.urlparse(args.base)
    cookie = sign_in(base, args.email, os.environ.get("SYNAPLAN_PERF_PASSWORD", "demo123"))
    chats = [new_chat(base, cookie) for _ in range(args.streams)]
    gate = threading.Event()
    records = [{} for _ in chats]
    threads = [threading.Thread(target=run_turn, args=(base, cookie, chat_id, args.message, gate, rec))
               for chat_id, rec in zip(chats, records)]
    for t in threads:
        t.start()
    gate.set()
    for t in threads:
        t.join()
    for chat_id in chats:
        delete_chat(base, cookie, chat_id)

    done = [r for r in records if r.get("status") == 200 and r.get("complete") and not r.get("error")]
    durations = [r["duration"] for r in done]
    print(json.dumps({
        "streams": args.streams,
        "done": len(done),
        "failed": [{k: r.get(k) for k in ("status", "error")} for r in records if r not in done],
        "duration_p50_s": round(statistics.median(durations), 2) if durations else None,
        "duration_max_s": round(max(durations), 2) if durations else None,
    }))
    return 0 if len(done) == args.streams else 1


if __name__ == "__main__":
    raise SystemExit(main())
