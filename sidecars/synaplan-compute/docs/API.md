# API draft (protocol 1)

Authentication: `Authorization: Bearer <COMPUTE_AUTH_TOKEN>` on every path except `GET /v1/health`.

Errors: `Content-Type: application/problem+json`

```json
{ "error": { "code": "limits_exceed_caps", "message": "...", "details": {} } }
```

Unknown JSON fields are rejected (`json.Decoder.DisallowUnknownFields`). `image` is a key (`python` \| `node`), never a free reference. `entry.program` is taken from the image allow-list. The API does not carry a free-form shell string.

## `GET /v1/health` (unauthenticated)

See `tests/fixtures/compute-contract/health.json`. Fields: `protocol`, `tier`, `images`, `capacity`, `caps`, `features`.

## `POST /v1/runs` (multipart `request.json` + files)

202 `{ "runId": "<ulid>" }`. Fixtures: `run_request_python.json`, `run_request_node.json`.

## `GET /v1/runs/{id}`

Status enum: `queued` \| `running` \| `succeeded` \| `failed` \| `cancelled`.  
Reason enum: `timeout` \| `oom` \| `pids_limit` \| `output_limit` \| `program_error` \| `cancelled`.

## `GET /v1/runs/{id}/logs`

`text/event-stream` events `stdout`, `stderr`, `status`, `done`, `truncated`.

## `GET /v1/runs/{id}/artefacts` · `GET .../artefacts/{name}`

Regular files only. MIME allow-list. Symlinks omitted.

## `DELETE /v1/runs/{id}`

Kill + remove container; delete scratch; keep status for `COMPUTE_RUN_RETENTION_MIN`.

## Workspaces

`POST /v1/workspaces` `{ owner, quotaMb }` → 201 `{ workspaceId, quotaMb }` (opaque ULID, no path).  
`GET /v1/workspaces/{id}/usage` · `GET .../files` · `GET .../files/{path}` · `DELETE /v1/workspaces/{id}`.

A run with `workspace.kind=user` must carry the same `owner` (403 `workspace_not_owned`).
