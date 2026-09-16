# Frozen Synaplan Desktop contract fixtures (`protocol: 1`)

These JSON files are the **frozen** wire shapes of the Synaplan Desktop job /
check-in contract (Sprint A3, DS18). They are the single source of truth the
future desktop client (`synaplan-desktop`, Phase B, step DC3) **vendors
byte-for-byte** to build its unit tests against — so the client can be developed
and tested without a live server.

**Do not edit these files as a client convenience.** They are asserted against
the live server contract by
`backend/tests/Unit/Service/Desktop/DesktopContractFixturesTest.php`
(`DesktopJobContract`, the entity enums, and the MCP tool schemas). Any change
that breaks the contract fails that test on purpose (invariant **C9**): editing
a fixture is a `protocol: 2` decision **with a migration plan**, never a quiet
tweak.

> **Two copies, kept identical by a test.** The dev backend container only
> mounts `backend/`, so a byte-identical mirror lives at
> `backend/tests/Fixtures/Desktop/`. The test reads the mirror and, wherever
> this canonical copy is also on disk (CI, host checkouts), asserts the two are
> byte-for-byte identical — so they can never drift. If you change one, change
> both (the test will remind you).

## The files

| File | Direction | Shape |
| ---- | --------- | ----- |
| `enqueue_request.json` | web → server (`POST /api/v1/desktop/jobs`) | Queue a `skill.run` job for a paired computer. |
| `checkin_request.json` | device → server (MCP `agent_checkin` args) | What a device sends each poll. |
| `checkin_response.json` | server → device (MCP `agent_checkin` result) | `protocol`, at most one job, `next_call_at`. |
| `job_skill_run.json` | server → device (one job, isolated) | The exact device-facing payload — **only** `{skill, prompt, fileIds}`. |
| `report_success.json` | device → server (MCP `agent_report_result` args) | A successful run. |
| `report_unknown_skill.json` | device → server (MCP `agent_report_result` args) | A refusal: a normal `failed` with an `errorCode`, not a transport error. |

## The two rules a client must never break

1. **`protocol: 1`.** Both the check-in request and response carry it. A device
   speaking an unknown protocol is answered with an empty job list and a far
   `next_call_at` — never a guess.
2. **A job's input is ONLY `{skill, prompt, fileIds}`.** Any other key
   (`command`, `script`, `argv`, …) is dropped by the server and MUST be ignored
   by the device. There is no field through which a shell string can reach the
   computer — that is why a future server bug cannot become remote code
   execution.

The `leaseExpires` / `next_call_at` values are illustrative UNIX timestamps; a
real response computes them from the server clock. Everything else — the keys,
the enums, the nesting — is the contract.

See [`../../../docs/DESKTOP.md`](../../../docs/DESKTOP.md) for the full picture.
