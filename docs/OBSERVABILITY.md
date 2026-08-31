# Observability — Request Correlation and the Event Ring

Two things that answer "what broke in production?" without ever handing anyone the raw logs.

Raw logs carry chat content, user emails, RAG/document text and secrets. They stay where they are — reachable over SSH by a human who is trusted with them. What this page describes is the *redacted* path: a bounded feed of operational events that an admin, and the in-chat AI, may look at.

| Consumer | Access | Sees |
|---|---|---|
| Human developer (deep debugging) | SSH to the node | Everything — their responsibility |
| Admin UI / API | `GET /api/v1/admin/logs` (`ROLE_ADMIN`) | Redacted events only |
| AI in chat | `recent_errors` MCP tool (admin key) | Redacted events only |

## Request correlation id

Every main request gets a correlation id, echoed back as the `X-Request-Id` response header and stamped onto every log record (`extra.request_id`). A user who reports "I got an error, the id was `4adc7d69…`" can be traced to the exact server-side events.

```bash
curl -sD - -o /dev/null https://your-domain.com/api/v1/config/runtime | grep -i x-request-id
```

An id supplied by the caller is reused only when it is short (≤ 128 chars) and matches `[A-Za-z0-9._-]+`; anything else is replaced with a fresh random one. That lets an upstream proxy propagate its own trace id while making it impossible to inject arbitrary content — or PII — into the logs.

Note that the id is accepted from *any* client, not just a trusted proxy (the deployment does not configure `trusted_proxies`). A client can therefore choose its own correlation id. That is harmless for a troubleshooting feed — the charset and length limits keep it opaque — but it means a correlation id is not evidence of anything.

## The event ring

`warning` and above from every channel is written into a bounded Redis sorted set, independent of the stderr handler's `LOG_LEVEL`. Even when production logs only errors to stderr, "a fallback fired 400 times" stays queryable.

| Property | Value |
|---|---|
| Storage | Redis sorted set, scored by timestamp |
| Capacity | 2000 events |
| Retention | 7 days TTL, refreshed on write |
| Level | `warning` and above |

Volatile by design: this is a troubleshooting feed, not an audit log. There is no migration and no new service. If weeks-long forensic retention is ever needed, a database table is the follow-up.

**On a multi-node deployment the ring lives wherever Redis lives.** With a shared Redis (the platform default) all nodes write into one feed and the `host` field tells you which one produced an event. With a per-node Redis you only ever see the node that served your request.

### What a redacted event contains

The event is assembled from a fixed **allow-list**, so "nothing from the user is in it" holds by construction rather than by hoping a filter caught everything:

`id`, `ts`, `level`, `channel`, `event`, `message`, `exception_class`, `exception_message`, `stack`, `request_id`, `host`, `route`, `method`, `status_code`, `user_id`, `provider`, `model`, `worker`, `duration_ms`

The Monolog context is never copied wholesale — contexts across the app also carry `email`, `to` and free-form payloads. Six keys are read by name (`provider`, `model`, `worker`, `user_id`, `status_code`, `duration_ms`), plus `error` as the reason text for the very common `$logger->error('X failed', ['error' => $e->getMessage()])` shape.

`user_id` is the only quasi-identifier kept. It is pseudonymous — resolvable to a person only through the access-controlled database — and exists so events can be correlated. Email and name are never stored.

Stack traces are reduced to at most 15 `file:line` frames with no arguments: enough to locate the failure, nothing that could carry a runtime value.

### Scrubbing

Only two fields are free text: the log message template and the exception message. Both are truncated to 2000 characters and then run through a scrubber that masks emails, `Bearer` tokens, provider API keys and `password=`/`token:`-style pairs. If a pattern cannot be evaluated, the field fails closed to `[redacted]` rather than emitting the raw value.

**The scrubber is a risk-reducer, not the guarantee** — the allow-list is. An exotic format can still slip through the two free-text fields, and both consumers are `ROLE_ADMIN`. If zero residual is ever required, the clean step is to drop `exception_message` from the AI path (keeping class and stack), not more regex.

### Behaviour when Redis is down

Recording an event must never break the request it describes, so failures degrade silently. Two guards matter:

- **Reentrancy:** a failed Redis command makes `RedisService` log "Redis command failed" as a warning, which would route straight back into the ring handler. The handler suppresses its own reentrant writes.
- **Circuit breaker:** after a refused write the handler stays quiet for 60 seconds. Predis reconnects per command, so without this every logged warning during a Redis outage would pay the connection timeout again and turn a Redis incident into an app-wide slowdown.

## Querying

### Admin API

`GET /api/v1/admin/logs` (requires `ROLE_ADMIN`).

| Parameter | Meaning |
|---|---|
| `mode` | `recent` (default) or `summary` |
| `level` | Exact level filter (recent mode) |
| `since_minutes` | Look-back window, capped at 7 days (the retention) |
| `q` | Case-insensitive substring across event/message/exception/route/provider/model |
| `request_id` | Filter by correlation id |
| `limit` | 1–500, default 50 |

`mode=summary` returns counts by level, event type and route for the window, plus the ten most recent errors — the cheap "what is going on" view.

### MCP tool

`recent_errors` is registered only for admins, so it does not appear in `tools/list` for anyone else, and the handler re-checks the role on every call. Both checks use `ROLE_ADMIN` (not the internal user level) so an admin whose role comes from the OIDC role mapping is treated the same way as a local one.

The `mcp` firewall authenticates *any* valid API key as `ROLE_USER`, which is exactly why the tool cannot rely on the firewall for authorization.

## Data protection

The design applies the standard techniques: data minimisation (allow-list), pseudonymisation (`user_id` only), scrubbing of free text, role-based access control, and a hard retention limit.

That is the engineering side. Calling the result "GDPR compliant" additionally requires an organisational legal basis and a data processing agreement with whichever AI provider the admin's chat runs against — not something code can assert.
