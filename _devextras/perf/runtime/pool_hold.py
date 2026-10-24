#!/usr/bin/env python3
"""Hold slow streams against a two-pool FrankenPHP and time a short probe.

Signs in as the demo user. The password comes from SYNAPLAN_PERF_PASSWORD and
is never printed. A turn counts as busy only when the body is the server_busy
contract. Prints one JSON object.
"""

from __future__ import annotations

import argparse
import http.client
import json
import os
import threading
import time
import urllib.parse


def connect(base: urllib.parse.ParseResult, timeout: float) -> http.client.HTTPConnection:
    return http.client.HTTPConnection(base.hostname, base.port, timeout=timeout)


def request(base, method, path, cookie="", body=None, timeout=30.0):
    conn = connect(base, timeout)
    headers = {"Accept": "application/json"}
    if cookie:
        headers["Cookie"] = cookie
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"
    started = time.monotonic()
    try:
        conn.request(method, path, body=data, headers=headers)
        resp = conn.getresponse()
        payload = resp.read()
        elapsed = time.monotonic() - started
        return resp.status, dict(resp.getheaders()), payload, elapsed
    except Exception as exc:  # noqa: BLE001 — a measurement records every failure
        return 0, {}, f"{type(exc).__name__}: {exc}".encode(), time.monotonic() - started
    finally:
        conn.close()


def sign_in(base, email, password) -> str:
    # A dict of headers drops repeated set-cookie lines, so the session cookie
    # is read from the raw response.
    conn = connect(base, 30)
    conn.request("POST", "/api/v1/auth/login", body=json.dumps({"email": email, "password": password}),
                 headers={"Content-Type": "application/json"})
    resp = conn.getresponse()
    resp.read()
    if resp.status != 200:
        raise SystemExit(f"login answered HTTP {resp.status}")
    cookies = [value.split(";", 1)[0] for name, value in resp.getheaders() if name.lower() == "set-cookie"]
    conn.close()
    if not cookies:
        raise SystemExit("login set no cookie")
    return "; ".join(cookies)


def busy_body(payload: bytes) -> bool:
    try:
        body = json.loads(payload.decode() or "{}")
    except json.JSONDecodeError:
        return False
    if body.get("error") == "server_busy":
        return True
    error = body.get("error")
    if isinstance(error, dict) and (error.get("code") == "server_busy" or error.get("type") == "overloaded_error"):
        return True
    return False


def hold(base, method, path, cookie, record, timeout):
    status, headers, payload, elapsed = request(base, method, path, cookie, timeout=timeout)
    record["status"] = status
    record["seconds"] = round(elapsed, 2)
    record["busy"] = status == 503 and busy_body(payload)
    record["retry_after"] = headers.get("Retry-After") or headers.get("retry-after")
    if status == 503:
        record["body"] = payload[:240].decode("utf-8", "replace")


def percentile(values, fraction):
    if not values:
        return None
    ordered = sorted(values)
    index = min(len(ordered) - 1, max(0, round(fraction * (len(ordered) - 1))))
    return round(ordered[index] * 1000, 1)


def probe(base, samples, record):
    latencies = []
    failures = 0
    for _ in range(samples):
        status, _, _, elapsed = request(base, "GET", "/api/health", timeout=5)
        if status == 200:
            latencies.append(elapsed)
        else:
            failures += 1
    record["ok"] = len(latencies)
    record["failed"] = failures
    record["p95_ms"] = percentile(latencies, 0.95)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", default="http://synaplan-backend:8090")
    parser.add_argument("--email", default="demo@synaplan.com")
    parser.add_argument("--streams", type=int, default=8)
    args = parser.parse_args()
    base = urllib.parse.urlparse(args.base)
    cookie = sign_in(base, args.email, os.environ.get("SYNAPLAN_PERF_PASSWORD", "demo123"))

    status, _, payload, _ = request(base, "POST", "/api/v1/chats", cookie, {"title": "pool isolation"})
    created = json.loads(payload or b"{}")
    chat_id = (created.get("chat") or {}).get("id") or created.get("id")
    if status not in (200, 201) or not chat_id:
        raise SystemExit(f"creating a chat answered HTTP {status}")

    path = "/api/v1/messages/stream?" + urllib.parse.urlencode({
        "chatId": chat_id, "message": "Tell me a short story about a lighthouse.",
    })
    records = [{} for _ in range(args.streams)]
    threads = [threading.Thread(target=hold, args=(base, "GET", path, cookie, record, 30))
               for record in records]
    for thread in threads:
        thread.start()
    time.sleep(0.4)
    gateway_status, _, gateway_payload, _ = request(
        base, "POST", "/v1/chat/completions", cookie,
        {"model": "test-model", "messages": [{"role": "user", "content": "Hello"}]},
        timeout=10,
    )
    probe_record = {}
    probe(base, 20, probe_record)
    for thread in threads:
        thread.join()

    session_status, _, session_payload, _ = request(
        base, "POST", "/v1/audio/transcriptions/sessions", cookie, {"client_id": "pool", "language": "en"}
    )
    dictation = {"create_status": session_status}
    if session_status in (200, 201):
        session = json.loads(session_payload or b"{}")
        session_id = session.get("id") or (session.get("session") or {}).get("id")
        if session_id:
            drecords = [{} for _ in range(4)]
            dpath = f"/v1/audio/transcriptions/sessions/{session_id}/stream?timeout=8"
            dthreads = [threading.Thread(target=hold, args=(base, "GET", dpath, cookie, record, 20))
                        for record in drecords]
            for thread in dthreads:
                thread.start()
            time.sleep(0.4)
            dictation_probe = {}
            probe(base, 10, dictation_probe)
            for thread in dthreads:
                thread.join()
            dictation["busy_count"] = sum(1 for record in drecords if record.get("busy"))
            dictation["statuses"] = [record.get("status") for record in drecords]
            dictation["probe"] = dictation_probe

    tts_record = {}
    hold(base, "GET", "/api/v1/tts/stream?" + urllib.parse.urlencode({"text": "Hello", "language": "en"}),
         cookie, tts_record, 20)

    request(base, "DELETE", f"/api/v1/chats/{chat_id}", cookie)
    print(json.dumps({
        "streams": args.streams,
        "busy_count": sum(1 for record in records if record.get("busy")),
        "statuses": [record.get("status") for record in records],
        "busy_sample": next((record.get("body") for record in records if record.get("status") == 503), None),
        "gateway_while_full": {"status": gateway_status, "body": gateway_payload[:240].decode("utf-8", "replace")},
        "retry_after": [record.get("retry_after") for record in records if record.get("busy")][:3],
        "probe": probe_record,
        "dictation": dictation,
        "tts": {key: tts_record.get(key) for key in ("status", "busy", "seconds")},
    }))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
