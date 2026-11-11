# Status — IAM consolidation

Plan: [`00_master_plan.md`](./00_master_plan.md). A walk, a count and a CI
run are entries here, not chat messages.

## Decisions

| Id | State |
| -- | ----- |
| D1 | accepted — registration off closes every self-sign-up door; an existing account still signs in; a code does not bypass it |
| D2 | accepted — directory provisioning stays on `OIDC_ALLOW_USER_PROVISIONING` only |
| D3–D5 | open — owner |
| D6 | accepted — grants live in `BUSER_LEVEL_GRANTS`, one row per account and source, deleted with the account |
| D7 | accepted — a read-only card on Authentication names the four doors and links each one to the switch that decides it. The card changes nothing |
| D8–D9 | open — owner |
| D10 | accepted — what an administrator sets is an `admin` grant; the level is the highest grant; billing only takes back its own |

## Steps

| Step | Pull request | State |
| ---- | ------------ | ----- |
| IC0 | | not started — private note |
| IC1 | [#3027](https://github.com/metadist/synaplan/pull/3027) | merged |
| PC1 | | not started (`synaplan-platform`) |
| PC2 | | waits for D4 |
| IC2+IC3 | [#3030](https://github.com/metadist/synaplan/pull/3030) | merged |
| IC4 | [#3074](https://github.com/metadist/synaplan/pull/3074) | merged |
| IC5+IC5b | [#3079](https://github.com/metadist/synaplan/pull/3079) | merged — the level is the highest grant, and People shows where it comes from |
| IC6 | [#3086](https://github.com/metadist/synaplan/pull/3086) | merged — one identity guide, and the variables that are not switches |
| IC8 | [#3077](https://github.com/metadist/synaplan/pull/3077) | merged — password reset and the SSO-only login page |
| CH1 | [#110](https://github.com/metadist/synaplan-charts/pull/110) | merged — sign-up, human check and trusted proxies in the chart |
| IC7 | | in review — who can create an account, on Authentication |
| PC3 | | waits for D5 |

Seven pull requests in this repository, not one per row. IC0, PC1–PC3 and CH1 stay in their own repositories. D1 and D2 are accepted, so IC2 and IC3 share one pull request. D6 and D10 are accepted, so IC5 and IC5b share one pull request: `BUSER_LEVEL_GRANTS`, and the People row names each grant. IC8 already landed on its own, so IC6 is the identity guide by itself. D7 is accepted, so IC7 is its own pull request: the card on Authentication. The public docs site links to the guide from its own repository.

## Audit 2026-10-25

Read-only. `origin/main` at `7f7134620`, open #2744 at `2e1d86aa0`.
Findings F1–F15 in the plan; the security details of IC0 and PC1–PC3 are
in `synaplan-platform/planning/20261025-iam-security-note/`.

## Reviews

| Date | Round | Result |
| ---- | ----- | ------ |
| 2026-10-25 | Copilot round 1 on [#2745](https://github.com/metadist/synaplan/pull/2745) | five findings, all accepted; plan revised (change log) |

## Walks

| Date | Where | What |
| ---- | ----- | ---- |
| 2026-11-10 | People → Users | Created an account, set Team, the row showed “Team · an administrator”, and removing that grant returned the account to New with no grant. The signed-in administrator cannot change their own level. Dark, 320 px. |
| 2026-11-11 | Admin → System configuration → Sign-in & registration | The card named the four doors. “Show the switch” opened “Who can use this instance” on registration. Pausing sign-up changed the email door to closed, and turning the pause off put it back to open. “Open Users” opened Users. Features still renders. Dark, design v2, 320 px, no horizontal overflow. |
