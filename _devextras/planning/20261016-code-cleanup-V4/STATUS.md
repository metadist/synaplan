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
| 2026-10-16 | Plan | 57 | 69 | 147 | — | — | Plan written from the V3 §7 order, re-measured on `main`; twelve sprints S0–S11 with chores C0–C11, S1 and S4 as lettered pull requests; V3 archived; D1–D3 proposed, not ticked |

## Owner decisions

| # | For | State |
| - | --- | ----- |
| D1 | S2 — which buffered / streamed differences align | proposed |
| D2 | S3 — one topic / intent rule | proposed |
| D3 | S8 — one MIME-to-extension map | proposed |

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
| 2026-10-16 | [#2521](https://github.com/metadist/synaplan/pull/2521) (plan) | 1 | 🟡 Changes recommended, 6 findings | All six accepted, each checked against the code first. (1) A revision's `BUSERID` is the file owner (`DocumentRevisionService` line 66): D4 dropped, and revisions go with the owner's files. (2) `ComputeWorkspaceExpirer` finds its work through the row S0 erases: S0.3 now uses a durable `DeleteComputeWorkspace` message (retry strategy, `failed` transport) and a test of eventual deletion after a first failure. (3) `AesCredentialVault::forget()` is a database delete: it runs inside the transaction, with a rollback test, and is off the after-commit lists. (4) The classifier drops a message's existing topic for `chat` / `vectorize` models: S3.2 moves both tables unchanged, and the change is its own `fix:` (S3.5) with a routing diff. (5) `classify()` runs slash → pin → `/docs` → fast path → Again → attachments → embeddings → deferral → sorter, not the docblock's order: S6 follows the executed order, pins competing steps and corrects the docblock. (6) The OpenAI SDK bypasses the Symfony client: S10.1 adds an SDK factory seam with a Guzzle mock history and a no-network test. Same-class audit (loop §8): the S4 and S5 snapshots now name every output channel and assert that no request leaves the process. |

## Merges

| Date | Pull request | Merge commit | Ledger after |
| ---- | ------------ | ------------ | ------------ |
