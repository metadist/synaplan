# Status — IAM consolidation

Plan: [`00_master_plan.md`](./00_master_plan.md). A walk, a count and a CI
run are entries here, not chat messages.

## Decisions

| Id | State |
| -- | ----- |
| D1 | accepted — registration off closes every self-sign-up door; an existing account still signs in; a code does not bypass it |
| D2 | accepted — directory provisioning stays on `OIDC_ALLOW_USER_PROVISIONING` only |
| D3–D10 | open — owner |

## Steps

| Step | Pull request | State |
| ---- | ------------ | ----- |
| IC0 | | not started — private note |
| IC1 | [#3027](https://github.com/metadist/synaplan/pull/3027) | merged |
| PC1 | | not started (`synaplan-platform`) |
| PC2 | | waits for D4 |
| IC2+IC3 | | this branch — pull request URL lands here when it is open |
| IC4 | | not started — own pull request, the fixes the matrix finds |
| IC5+IC5b | | waits for D6 and D10 — one pull request, schema then the People row |
| IC6+IC8 | | waits for IC2 and IC5 — one pull request, the guide and the journeys |
| CH1 | | waits for IC6 (`synaplan-charts`) |
| IC7 | | waits for D7 — own pull request |
| PC3 | | waits for D5 |

Six pull requests in this repository, not one per row. IC0, PC1–PC3 and CH1 stay in their own repositories. D1 and D2 are accepted, so IC2 and IC3 share one pull request. IC5 still waits for D6 and D10 before a `BUSER_LEVEL_GRANTS` table is added.

## Audit 2026-10-25

Read-only. `origin/main` at `7f7134620`, open #2744 at `2e1d86aa0`.
Findings F1–F15 in the plan; the security details of IC0 and PC1–PC3 are
in `synaplan-platform/planning/20261025-iam-security-note/`.

## Reviews

| Date | Round | Result |
| ---- | ----- | ------ |
| 2026-10-25 | Copilot round 1 on [#2745](https://github.com/metadist/synaplan/pull/2745) | five findings, all accepted; plan revised (change log) |

## Walks

None yet.
