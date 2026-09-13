# Protocol 1 contract review

Filled 2026-09-13 against the live sidecar in `sidecars/synaplan-compute`
and the vendored fixtures in `synaplan/backend/tests/Fixtures/compute-contract/`.
A later change that would loosen any row is `protocol: 2`.

| Check | Proof | Tick |
| ----- | ----- | ---- |
| Every request field has a type, a bound and a refusal code | `pkg/contract/run.go` + `error_*.json` | ☑ |
| No field can carry a shell string or an image reference | `entry.program` allow-list; `image` is a map key; `scripts/no-shell-guard.sh` | ☑ |
| `owner` is required on runs and workspaces | `ErrMissingOwner`; fixtures `run_request_*.json`, `workspace_create.json` | ☑ |
| All enums closed and documented | `docs/API.md` + `pkg/contract` constants | ☑ |
| Unknown fields rejected on every decoder | `DecodeJSON` + `DisallowUnknownFields`; `TestUnknownFieldRejected` | ☑ |
| Health shape sufficient for PHP's gate | `health.json` (`protocol`, `tier`, `caps`, `features`, `capacity`) | ☑ |
| Egress entry shape matches `SsrfGuard` (host, port, pinned IPs) | `run_request_egress.json`; A0–A2 refuse any non-empty allow-list | ☑ |
| Compute never receives a Synaplan credential, user email or file path | Request is `owner` + opaque workspace id + uploaded bytes; no Synaplan token in the sandbox | ☑ |

PHP-side reviewer: Wave 5 A3/B1 (`ComputeContractFixtureTest` + `ComputeClient`).
Go-side reviewer: sidecar A0–A2 (#1774) + this freeze.
