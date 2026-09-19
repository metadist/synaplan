# TODO for the coder — compute B4 leftovers needing manual work

The B4 code track (PRs #2015 → #2016 → #2017) is done and green. Everything
below needs a human: infrastructure, releases, other repos, or staging access.

## 1. Merge the stack (in order)

1. Merge #2015 (CS27–CS29) into `main`.
2. Rebase `feat/compute-b4-tier-gate` onto the new `main`, retarget #2016
   to `main`, force-push, wait for CI, merge.
3. Same for `feat/compute-b4-egress-and-load` / #2017.
4. Delete the temporary `ci-check/pr2-tip` + `ci-check/pr3-tip` branches
   (and close #2019/#2020 if the agent didn't get to it).

Stacked PRs don't trigger CI here (workflow only runs on `main`-based
PRs) — that is why the validation PRs exist.

## 2. Publish run images (blocks ALL runtime use)

`internal/images/map.go` still carries placeholder digests (`aaaa…`,
`bbbb…`). No compute run can execute anywhere until real images ship:

1. Build `images/python` + `images/node`, push to
   `ghcr.io/metadist/synaplan-compute-{python,node}` with A3 signing
   (the "compute release v1.x" step from the master plan).
2. Record the real digests in `map.go`, keep the `@sha256:` form.
3. Re-run `_devextras/testing/compute/xlsx-recalc.sh` against the release
   as the smoke proof.

## 3. T2 gVisor node + evidence (the hard gate for Cloud)

1. Provision the compute node per `synaplan-platform/compute/`
   (`install.sh`), with `runsc` installed and `COMPUTE_TIER=gvisor`.
2. Set the repo variable `COMPUTE_T2_NIGHTLY_ENABLED=true` once a
   self-hosted `gvisor` runner exists, so the T2 nightly leg activates.
3. Collect and file in `STATUS.md` (CS32 row 1): `/v1/health` showing
   `tier=gvisor`, hostile-corpus result, one load-mix summary.
4. Cloud enablement per instance: seed `COMPUTE.REQUIRE_TIER=gvisor` and
   verify `COMPUTE_URL` does not resolve to a web host (runbook check
   from `docs/COMPUTE.md`).

## 4. CS24/CS25 external docs (other repos)

- **CS24** (`synaplan-docs`, public): compute user/admin page. Covers the
  System-status card, the File work settings section, Workspace browser,
  and the Run card. Source material: `docs/COMPUTE.md` + the B4 sprint
  file §2.2 (journey J-CP-3).
- **CS25** (`synaplan-platform`, private): pointer to the docs page +
  Cloud runbook entries (separate-node rule, `REQUIRE_TIER=gvisor` seed,
  tier-gate check per deploy).

## 5. Platform ops gaps noticed during B4

- `synaplan-platform/compute/install.sh` creates `scratch/` + `workspaces/`
  as root without chown — the nonroot sidecar cannot write there and every
  run fails at staging. Fix ownership in the installer (or document the
  chown step) before first use.
- Schedule the two reaper commands (CS28/CS29) in the platform cron:
  `app:compute:reap-runs` (every ~5 min) and
  `app:compute:expire-workspaces` (daily). Cron lines are in the command
  docs.
- `COMPUTE_IMAGE_PYTHON/NODE` in `platform/compute/docker-compose.yml` is
  read by nothing — the sidecar pins images in code (#2 above). Remove or
  wire it when publishing images.

## 6. Staging rehearsal (CS33 §2.6 + J-CP-1)

Needs staging + published images (#2):

1. Ten-run rehearsal: flag on → ten runs → flag off; assert catalog loses
   `code_run`, gateways stop offering `code_execution`, routes 404,
   reapers idle-exit, history/artefacts stay readable, Workspace shows
   not-available (local rehearsal already covered the flag/profile halves).
2. Walk J-CP-1 end to end (CSV → card → previewable PNG + quota sentence).
3. Manual saved-task pause → approve → resume run for CS32 row 4.
