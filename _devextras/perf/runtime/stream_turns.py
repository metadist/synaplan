#!/usr/bin/env python3
"""Hold N in-app chat streams open at once, signed in as one user.

Three phases, so the caller can measure between them without a request of
this script in the way:

  prepare  signs in through POST /api/v1/auth/login, refuses unless the
           models the turns will use (chat, sorting, memory, and any model a
           topic prompt names) are served by the test provider, and opens
           one chat per stream; the session and
           every chat id are written to --state as soon as they exist
  stream   reads GET /api/v1/messages/stream for every chat at once until
           the turn's terminal event; prints one JSON object
  cleanup  deletes the chats and the state file

A turn counts as done only when its `complete` event arrived. The password is
read from SYNAPLAN_PERF_PASSWORD and never printed.
"""

from __future__ import annotations

import argparse
import http.client
import json
import os
import pathlib
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


def get_json(base: urllib.parse.ParseResult, cookie: str, path: str) -> dict:
    conn = connect(base, 30)
    conn.request("GET", path, headers={"Cookie": cookie, "Accept": "application/json"})
    resp = conn.getresponse()
    body = json.loads(resp.read() or b"{}")
    conn.close()
    if resp.status != 200:
        raise SystemExit(f"GET {path} answered HTTP {resp.status}")
    return body


def assert_test_models(base: urllib.parse.ParseResult, cookie: str, test_models: set[int]) -> None:
    """Refuse unless the app resolves every model a turn uses to a test-provider model."""
    defaults = get_json(base, cookie, "/api/v1/config/models/defaults").get("defaults") or {}
    for capability in ("CHAT", "SORT", "MEM"):
        model_id = defaults.get(capability)
        if model_id not in test_models:
            raise SystemExit(f"The {capability} model resolves to model {model_id}, not the test provider. "
                             "Refusing to call a paid provider.")
    # The topic prompt a turn is routed to answers with its own model before
    # the CHAT default when it names one (aiModel > 0).
    for prompt in get_json(base, cookie, "/api/v1/prompts").get("prompts") or []:
        model_id = (prompt.get("metadata") or {}).get("aiModel")
        if isinstance(model_id, int) and model_id > 0 and model_id not in test_models:
            raise SystemExit(f"The {prompt.get('topic')} prompt answers with model {model_id}, not the test "
                             "provider. Refusing to call a paid provider.")


def save_state(state: pathlib.Path, cookie: str, chats: list[int]) -> None:
    fd = os.open(state, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as handle:
        json.dump({"cookie": cookie, "chats": chats}, handle)


def prepare(base: urllib.parse.ParseResult, state: pathlib.Path, email: str, streams: int,
            test_models: set[int]) -> int:
    cookie = sign_in(base, email, os.environ.get("SYNAPLAN_PERF_PASSWORD", "demo123"))
    assert_test_models(base, cookie, test_models)
    chats: list[int] = []
    save_state(state, cookie, chats)
    for _ in range(streams):
        chats.append(new_chat(base, cookie))
        save_state(state, cookie, chats)
    return 0


def stream(base: urllib.parse.ParseResult, cookie: str, chats: list[int], message: str) -> int:
    gate = threading.Event()
    records = [{} for _ in chats]
    threads = [threading.Thread(target=run_turn, args=(base, cookie, chat_id, message, gate, rec))
               for chat_id, rec in zip(chats, records)]
    for t in threads:
        t.start()
    gate.set()
    for t in threads:
        t.join()

    done = [r for r in records if r.get("status") == 200 and r.get("complete") and not r.get("error")]
    durations = [r["duration"] for r in done]
    print(json.dumps({
        "streams": len(chats),
        "done": len(done),
        "failed": [{k: r.get(k) for k in ("status", "error")} for r in records if r not in done],
        "duration_p50_s": round(statistics.median(durations), 2) if durations else None,
        "duration_max_s": round(max(durations), 2) if durations else None,
    }))
    return 0 if len(done) == len(chats) else 1


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("phase", choices=("prepare", "stream", "cleanup"))
    parser.add_argument("--state", required=True, help="file the three phases share")
    parser.add_argument("--base", default="http://synaplan-backend:8090")
    parser.add_argument("--email", default="demo@synaplan.com")
    parser.add_argument("--streams", type=int, default=8)
    parser.add_argument("--message", default="Tell me a short story about a lighthouse.")
    parser.add_argument("--test-models", default="", help="comma-separated ids of the test provider's models")
    args = parser.parse_args()

    base = urllib.parse.urlparse(args.base)
    state = pathlib.Path(args.state)
    if args.phase == "prepare":
        test_models = {int(model_id) for model_id in args.test_models.split(",") if model_id.strip()}
        return prepare(base, state, args.email, args.streams, test_models)

    saved = json.loads(state.read_text())
    if args.phase == "stream":
        return stream(base, saved["cookie"], saved["chats"], args.message)

    for chat_id in saved["chats"]:
        delete_chat(base, saved["cookie"], chat_id)
    state.unlink()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
