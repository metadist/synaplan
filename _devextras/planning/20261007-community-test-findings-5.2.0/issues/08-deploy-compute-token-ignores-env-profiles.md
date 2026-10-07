<!-- title: Deploy: File work sidecar crash-loops after following deploy/README — ensure_compute_token only sees COMPOSE_PROFILES from the shell, not from deploy/.env -->
<!-- type: Bug -->
<!-- labels: prio:1, area:setup -->
<!-- status: shipped -->
<!-- issue-type: Bug -->

> **Shipped** in [#2380](https://github.com/metadist/synaplan/pull/2380) (`c5b6f22b5`). The token is written to `data/compute.token`. Host `COMPOSE_PROFILES` wins; otherwise the value is read from `deploy/.env` and `${VAR}` / `${VAR:-default}` are expanded without executing the file. `docker compose config --profiles` is the wrong detector: it lists every declared profile, including `compute`, even when File work is off. Do not re-implement.

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
- Findings: F24 — community test round on 5.2.0 ("Open; worked around"). Shipped in #2380 on the deploy layout from #2370. The notes below are the 5.2.0 diagnosis.
- Verified in code: `ensure_compute_token()` (`deploy/scripts/lib.sh` ~line 807) returns early unless `,${COMPOSE_PROFILES:-},` contains `compute`. By design the env file is **handed to Compose, not sourced** (header comment in `lib.sh`: "Compose stays the single parser"), so a profile set only in `deploy/.env` is invisible to the lifecycle shell. `deploy/compose.yaml` passes `COMPUTE_AUTH_TOKEN: "${COMPUTE_TOKEN:-}"` to the sidecar, which then fails its length check.
- `deploy/selfhost.env.example` ships `COMPUTE_URL=` and `COMPUTE_TOKEN=` empty with the comment "prepare.sh then writes COMPUTE_TOKEN".

What shipped (#2380): Compose stays the only parser of `deploy/.env`. The lifecycle script does not source that file and does not write `COMPUTE_TOKEN` into `.env` or `secrets.env`. Host `COMPOSE_PROFILES` wins. Otherwise the value is read from the env file and `${VAR}`, `${VAR:-default}` and `${VAR-default}` are expanded without executing `$(...)` or backticks. `docker compose config --profiles` is the wrong detector: it lists every declared profile, including `compute`, even when File work is off. Calling `docker compose config` from `ensure_compute_token` also breaks the lifecycle command contract, which records every docker invocation. The token file `data/compute.token` is the source of truth. Re-running keeps the same token. The script prints "File work enabled: token written to data/compute.token" on create and "using the token in data/compute.token" on reuse, and never prints the token. Sidecar status sits next to the File work switch.

Journey (U10): set `COMPOSE_PROFILES=compute` in `deploy/.env` → run the deploy → `compute` is healthy → admin File work switch shows "Sidecar reachable" → a chat request produces a spreadsheet → turn the profile off → switch shows "Sidecar not running: File work is unavailable".

Verification:
1. Shell-exported and `.env`-only profiles both produce a token file and a healthy sidecar.
2. Re-running the deploy keeps the same token (no rotation).
3. `deploy/README.md` and `docs/COMPUTE.md` match the behaviour.

---

## Screenshots/Logs
`compute` container log: `COMPUTE_AUTH_TOKEN must be at least 32 bytes`.
