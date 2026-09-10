# API (protocol 1)

Authentication: `Authorization: Bearer <COMPUTE_AUTH_TOKEN>` on every path except `GET /v1/health`. The token is compared as a SHA-256 digest in constant time.

Errors: `Content-Type: application/problem+json`

```json
{ "error": { "code": "limits_exceed_caps", "message": "...", "details": { "field": "memoryMb", "requested": 4096, "cap": 2048 } } }
```

Unknown JSON fields are rejected (`json.Decoder.DisallowUnknownFields`). `image` is a key (`python` \| `node`), never a free reference. `entry.program` is taken from the image allow-list. The API does not carry a free-form shell string.

## Error codes

| Code | HTTP | When |
| ---- | ---- | ---- |
| `unauthorized` | 401 | Missing or wrong bearer token |
| `invalid_json` | 400 | Body is not valid JSON, has the wrong types, or the multipart form is malformed / lacks `request.json` |
| `unknown_field` | 400 | A JSON field the contract does not define |
| `invalid_protocol` | 400 | `protocol` is not `1` |
| `missing_owner` | 400 | `owner` is empty |
| `unknown_image` · `program_not_allowed` | 400 | Image key or program not in the allow-list |
| `limits_exceed_caps` | 400 | A limit is above the instance cap or not positive; `details: {field, requested, cap}` names the first violation |
| `bad_file_name` | 400 | Unsafe file name, duplicate part, or a multipart part that is not declared in `files` |
| `too_many_files` | 400 | More than `COMPUTE_MAX_FILES` declared files or file parts |
| `payload_too_large` | 413 | Body larger than `COMPUTE_MAX_REQUEST_BYTES` (`Content-Length` or streamed) |
| `invalid_workspace` | 400 | `workspace.kind` is not `run` / `user` |
| `workspace_not_found` · `workspace_not_owned` · `workspace_quota_exceeded` | 404 · 403 · 409 | User workspace checks |
| `egress_not_allowed` | 400 | **Any** non-empty `egress.allow`. Egress is not implemented in A0–A2; every run is `NetworkMode=none`. |
| `capacity_exceeded` | 429 | `running + queued >= COMPUTE_MAX_CONCURRENT + COMPUTE_QUEUE_MAX`; `Retry-After: 5` |
| `run_not_found` · `artefact_not_found` | 404 | Unknown run, unknown / symlinked artefact, or a run whose scratch was deleted or pruned |
| `mime_not_allowed` | 403 | Download of an artefact or workspace file whose MIME is not on `COMPUTE_ARTEFACT_MIME_ALLOW` |
| `output_limit` | 409 (artefacts) · 403 (single artefact beyond the cap) | Run exceeded `outputMb`; artefacts of that run are not served |
| `internal_error` | 500 | Service-side I/O failure (scratch, workspace metadata) |

## `GET /v1/health` (unauthenticated)

See `tests/fixtures/compute-contract/health.json`. Fields: `protocol`, `tier`, `images`, `capacity`, `caps`, `features`. `features.egress` is always `false` in A0–A2.

## `POST /v1/runs`

Either `application/json` (no input files) or `multipart/form-data` with a `request.json` part plus one part per entry in `files[]`, named exactly as declared. Parts that are not declared are refused (`bad_file_name`); the body is bounded by `COMPUTE_MAX_REQUEST_BYTES` and the number of parts by `COMPUTE_MAX_FILES`.

202 `{ "runId": "<ulid>" }`. Fixtures: `run_request_python.json`, `run_request_node.json`.

The run is queued until a concurrency slot (`COMPUTE_MAX_CONCURRENT`) is free; at most `COMPUTE_QUEUE_MAX` runs wait.

## `GET /v1/runs/{id}`

Status enum: `queued` \| `running` \| `succeeded` \| `failed` \| `cancelled`.  
Reason enum: `timeout` \| `oom` \| `pids_limit` \| `output_limit` \| `program_error` \| `cancelled`; service-side failures use `docker_unavailable` or `internal_error`.

How reasons are derived after the container exits: `State.OOMKilled` → `oom`; total bytes in `/work` + `/out` above `outputMb` → `output_limit`; exit 137 after the deadline → `timeout`; any other non-zero exit → `program_error`. `pids_limit` is not detected (a fork bomb sees `EAGAIN` and exits non-zero → `program_error`).

`usage`: `wallMs` (container wall time), `bytesIn` (sum of uploaded parts), `bytesOut` (regular files under `/out`); `cpuSec` and `maxMemoryMb` are `0` until cgroup accounting lands. `truncated.stdout` / `truncated.stderr` report server-side log caps (`COMPUTE_LOG_CAP_BYTES` per stream).

## `GET /v1/runs/{id}/logs`

`text/event-stream` snapshot of the captured output (stdout and stderr are attached with `ContainerLogs` and demultiplexed). Events, each with an incrementing `id:`:

| Event | Data |
| ----- | ---- |
| `stdout` / `stderr` | `{ "seq": n, "text": "..." }` (omitted when empty) |
| `status` | `{ "status": "<status>" }` (always present) |
| `truncated` | `{ "stdout": bool, "stderr": bool }` (only when a cap was hit) |
| `done` | `{ "status": "<status>", "exitCode": n, "reason": "..." }` (only once the run finished; `exitCode` absent when the container never reported one) |

Fixture: `logs.sse`. The fixture and the handler are locked together by `TestLogsFixtureMatchesServerShape` and `TestLogsCapturedAndStreamedAsSSE`.

## `GET /v1/runs/{id}/artefacts` · `GET .../artefacts/{name}`

Regular files directly under `/out`. Every path component is `Lstat`-checked and the file is opened with `O_NOFOLLOW`; symlinks are omitted from the list and 404 on download. Each row carries `mime`, `sha256`, and optionally `rejected` (`mime_not_allowed` or `output_limit`). A rejected row cannot be downloaded (403 with the same code). Downloads set `Content-Type` from the allow-listed MIME, `X-Content-Type-Options: nosniff`, and `Content-Disposition: attachment`.

If the run itself ended with `output_limit`, both endpoints answer 409 `output_limit`.

## `DELETE /v1/runs/{id}`

Cancels the run: a queued run never creates a container, a running container is killed and removed, and the status becomes `cancelled` and stays so. Scratch is removed once the run goroutine has exited (immediately for a finished run). The status record is kept for `COMPUTE_RUN_RETENTION_MIN`; a janitor then drops the record and any remaining scratch.

## Workspaces

`POST /v1/workspaces` `{ owner, quotaMb }` → 201 `{ workspaceId, quotaMb }` (opaque ULID, no path).  
`GET /v1/workspaces/{id}/usage` · `GET .../files?path=` · `GET .../files/{path}` · `DELETE /v1/workspaces/{id}`.

Metadata (`owner`, `quotaMb`) is stored outside the directory that is mounted into the sandbox (`<root>/<id>.json` next to `<root>/<id>/data`), so a script cannot rewrite it. Listing and download never follow symlinks; downloads apply the same MIME allow-list and headers as artefacts.

A run with `workspace.kind=user` must carry the same `owner` (403 `workspace_not_owned`).
