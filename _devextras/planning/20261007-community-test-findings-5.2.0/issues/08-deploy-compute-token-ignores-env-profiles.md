<!-- title: Deploy: File work sidecar crash-loops after following deploy/README — ensure_compute_token only sees COMPOSE_PROFILES from the shell, not from deploy/.env -->
<!-- type: Bug -->
<!-- labels: prio:1, area:setup -->
<!-- issue-type: Bug -->

## Problem
`deploy/README.md` says to enable File work with `COMPOSE_PROFILES=compute` in `deploy/.env`. Doing exactly that starts the compute container, which crash-loops with `COMPUTE_AUTH_TOKEN must be at least 32 bytes`: no token was generated, `.env` has no `COMPUTE_URL` / `COMPUTE_TOKEN` values, and `deploy/data/compute.token` does not exist.

---

## Expected
Setting the profile where the docs say to set it produces a running sidecar: the lifecycle scripts generate the token, the compute service receives it, and the File work switch in the admin UI shows the sidecar as healthy.

## Actual
1. `COMPOSE_PROFILES=compute` written to `deploy/.env`, deployment re-run.
2. `compute` container starts and exits: `COMPUTE_AUTH_TOKEN must be at least 32 bytes`.
3. Workaround that worked: write `COMPUTE_URL=http://compute:8080` and a generated 64-hex token into `.env` by hand.

---

## Steps to reproduce
1. Fresh `deploy/` install with `deploy/selfhost.env.example` → `.env`.
2. Add `COMPOSE_PROFILES=compute` to `deploy/.env` (do not export it in the shell).
3. Run the deployment scripts; `docker compose ps compute`.

---

## Notes
- Findings: F24 — community test round on 5.2.0 ("Open; worked around"). **Re-verify on 5.3.0 first**: #2370 (`feat(deploy): start Synaplan from one compose file…`) reshaped `deploy/`; the gate below is unchanged on `main` as of 2026-10-07.
- Verified in code: `ensure_compute_token()` (`deploy/scripts/lib.sh` ~line 807) returns early unless `,${COMPOSE_PROFILES:-},` contains `compute`. By design the env file is **handed to Compose, not sourced** (header comment in `lib.sh`: "Compose stays the single parser"), so a profile set only in `deploy/.env` is invisible to the lifecycle shell. `deploy/compose.yaml` passes `COMPUTE_AUTH_TOKEN: "${COMPUTE_TOKEN:-}"` to the sidecar, which then fails its length check.
- `deploy/selfhost.env.example` ships `COMPUTE_URL=` and `COMPUTE_TOKEN=` empty with the comment "prepare.sh then writes COMPUTE_TOKEN".

Fix direction (keep "Compose is the single parser"): resolve the effective profiles the way Compose will — e.g. `docker compose --env-file <resolved> config --profiles`, or read the single `COMPOSE_PROFILES=` line from the resolved env file with the same tokenizer Compose uses — and feed that into `ensure_compute_token`. Write the generated token and `COMPUTE_URL` back into the resolved env file (or `secrets.env`) so a Docker GUI user sees them. Add a `prepare.sh` message "File work enabled: token written to …". Surface sidecar health next to the File work switch in admin (U7/U8: a non-running sidecar must say so, not fail later in chat).

Journey (U10): set `COMPOSE_PROFILES=compute` in `deploy/.env` → run the deploy → `compute` is healthy → admin File work switch shows "Sidecar reachable" → a chat request produces a spreadsheet → turn the profile off → switch shows "Sidecar not running: File work is unavailable".

Verification:
1. Shell-exported and `.env`-only profiles both produce a token file and a healthy sidecar.
2. Re-running the deploy keeps the same token (no rotation).
3. `deploy/README.md` and `docs/COMPUTE.md` match the behaviour.

---

## Screenshots/Logs
`compute` container log: `COMPUTE_AUTH_TOKEN must be at least 32 bytes`.
