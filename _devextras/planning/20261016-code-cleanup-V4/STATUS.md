# Code cleanup V4 — status

Every step row carries the three numbers of the V2 size ratchet: files >
800 lines, constructors > 10 params, methods > 100 lines. From S1a on it
also carries the frontend ledger (files > 800 / functions > 100). Start
values, measured 2026-10-16 on `main` at `9ca56ccb1`: **57 / 69 / 147**;
the frontend is not gated yet (29 files > 800 lines).

A walk is a row here, not a chat message (planning README).

## Step log

| Date | Step | > 800 lines | ctor > 10 | method > 100 | Frontend | Class worked on | Note |
| ---- | ---- | ----------- | --------- | ------------ | -------- | --------------- | ---- |
| 2026-10-16 | Plan | 57 | 69 | 147 | — | — | Plan written from the V3 §7 order, re-measured on `main`; twelve sprints S0–S11 with chores C0–C11, S1 and S4 as lettered pull requests; V3 archived; D1–D4 proposed, not ticked |

## Owner decisions

| # | For | State |
| - | --- | ----- |
| D1 | S2 — which buffered / streamed differences align | proposed |
| D2 | S3 — one topic / intent rule | proposed |
| D3 | S8 — one MIME-to-extension map | proposed |
| D4 | S0 — a revision the deleted user made on someone else's file | proposed |

## Sprint tests

One row per pull request (loop §4): proof (and the failing line on the
parent), regression (snapshots, gate counts), journey (E2E counts, every
failure matched to the known-local list, walks).

| Date | Pull request | Proof | Regression | Journey |
| ---- | ------------ | ----- | ---------- | ------- |

## Review log

One row per Copilot round (loop §8).

| Date | Pull request | Round | Copilot overview | Findings → answer |
| ---- | ------------ | ----- | ---------------- | ----------------- |

## Merges

| Date | Pull request | Merge commit | Ledger after |
| ---- | ------------ | ------------ | ------------ |
