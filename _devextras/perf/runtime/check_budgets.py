#!/usr/bin/env python3
"""Assemble a runtime result and compare it to budgets.json.

  check_budgets.py assemble <workdir> <out.json> <chunk_ms>
  check_budgets.py check <result.json> <budgets.json>
  check_budgets.py expect-breach <result.json> <budgets.json> <metric=max>

expect-breach exits 0 when the overridden budget would fail the checker.
A row with enforce=false is printed and never fails the process.
"""

from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def nested(data: dict, metric: str):
    current = data
    for part in metric.split("."):
        if not isinstance(current, dict) or part not in current:
            return None
        current = current[part]
    return current


def seconds(summary: dict, key: str):
    value = (summary or {}).get(key)
    if isinstance(value, (int, float)):
        return round(float(value), 3)
    return None


def ms_from_seconds(summary: dict) -> float | None:
    value = (summary or {}).get("p95")
    if isinstance(value, (int, float)):
        return round(float(value) * 1000, 3)
    return None


def assemble(work: Path, out: Path, chunk_ms: int) -> None:
    health1 = load(work / "health1.json")
    health8 = load(work / "health8.json")
    runtime = load(work / "runtime.json")
    gateway = load(work / "gateway.json")
    saturate = load(work / "saturate.json")
    pipeline_path = work / "pipeline.json"
    pipeline = load(pipeline_path) if pipeline_path.exists() else {}
    phases_path = work / "phases.json"
    phases = load(phases_path) if phases_path.exists() else {"skipped": True, "reason": "not run"}
    overhead_path = work / "overhead.txt"
    result = {
        "generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "chunk_ms": chunk_ms,
        "health": {"clients1": health1, "clients8": health8},
        "runtime_config": runtime,
        "pipeline": pipeline,
        "gateway": gateway,
        "saturation": {
            "streams": saturate.get("streams"),
            "streams_ok": saturate.get("streams_ok"),
            "streams_failed": saturate.get("streams_failed"),
            "wall_seconds": saturate.get("wall_seconds"),
            "probe_p95_ms": ms_from_seconds(saturate.get("probe_during_s") or {}),
            "probe_baseline_p95_ms": ms_from_seconds(saturate.get("probe_baseline_s") or {}),
            "stream_duration_p50_s": seconds(saturate.get("stream_duration_s") or {}, "p50"),
        },
        "phases": phases,
    }
    if overhead_path.exists():
        result["providers"] = {"output": overhead_path.read_text(encoding="utf-8", errors="replace")[-4000:]}
    out.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")


def apply_override(rows: list[dict], override: str | None) -> None:
    if not override:
        return
    metric, raw = override.split("=", 1)
    found = False
    for row in rows:
        if row.get("metric") == metric:
            row["max"] = float(raw)
            row["enforce"] = True
            found = True
    if not found:
        raise SystemExit(f"unknown budget metric {metric}")


def format_value(value) -> str:
    if value is None:
        return "n/a"
    if isinstance(value, float):
        if 0 < abs(value) < 0.1:
            return f"{value:.3f}"
        return f"{value:.1f}"
    return str(value)


def evaluate(result: dict, budgets: dict, override: str | None) -> tuple[list[str], list[str]]:
    rows = budgets.get("rows") or []
    apply_override(rows, override)
    lines: list[str] = []
    breaches: list[str] = []

    runtime = result.get("runtime_config") or {}
    lines.append(
        f"runtime-config    rps {format_value(runtime.get('rps'))}    "
        f"p95 {format_value(runtime.get('p95_ms'))} ms    recorded"
    )
    saturation = result.get("saturation") or {}
    lines.append(
        f"saturation        streams {saturation.get('streams_ok')}/{saturation.get('streams')}    "
        f"duration p50 {format_value(saturation.get('stream_duration_p50_s'))} s    "
        f"probe p95 {format_value(saturation.get('probe_p95_ms'))} ms"
    )
    gateway = result.get("gateway") or {}
    lines.append(
        f"gateway           canned {gateway.get('canned')}    "
        f"full stream {format_value(gateway.get('full_duration_s'))} s    "
        f"ttft p50 {format_value(gateway.get('ttft_p50_ms'))} ms"
    )
    phases = result.get("phases") or {}
    if phases.get("skipped"):
        lines.append(f"chat-stream       skipped ({phases.get('reason', 'not run')})")
    elif phases.get("ok") is False:
        lines.append("chat-stream       failed (see the result json)")
    else:
        lines.append("chat-stream       phases recorded")

    if saturation.get("streams_ok") != saturation.get("streams"):
        breaches.append("saturation streams did not all succeed")
    if gateway.get("failed"):
        breaches.append("gateway time-to-first-token had failed runs")

    lines.append("")
    lines.append(f"{'budget':<56} {'value':>10} {'limit':>12}  status")
    for row in rows:
        value = nested(result, str(row.get("metric", "")))
        limit = "recorded"
        if row.get("max") is not None:
            limit = f"<= {format_value(float(row['max']))}"
        elif row.get("min") is not None:
            limit = f">= {format_value(float(row['min']))}"
        status = "recorded"
        if row.get("enforce"):
            status = "ok"
            if not isinstance(value, (int, float)):
                status = "BREACH"
                breaches.append(f"{row.get('id')} missing")
            elif row.get("max") is not None and float(value) > float(row["max"]):
                status = "BREACH"
                breaches.append(f"{row.get('id')} {value} > {row['max']}")
            elif row.get("min") is not None and float(value) < float(row["min"]):
                status = "BREACH"
                breaches.append(f"{row.get('id')} {value} < {row['min']}")
        lines.append(f"{str(row.get('label', row.get('id'))):<56} {format_value(value):>10} {limit:>12}  {status}")
    return lines, breaches


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__.strip(), file=sys.stderr)
        return 2
    command = sys.argv[1]
    if command == "assemble":
        assemble(Path(sys.argv[2]), Path(sys.argv[3]), int(sys.argv[4]))
        return 0
    result = load(Path(sys.argv[2]))
    budgets = load(Path(sys.argv[3]))
    override = sys.argv[4] if command == "expect-breach" else None
    lines, breaches = evaluate(result, budgets, override)
    if command == "check":
        print("\n".join(lines))
        if breaches:
            print("\n".join(breaches), file=sys.stderr)
            return 1
        return 0
    if command == "expect-breach":
        if not breaches:
            print("forced budget did not breach", file=sys.stderr)
            return 1
        print(f"forced budget {override} breaches; checker exits non-zero")
        return 0
    print(f"unknown command {command}", file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
