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
| 2026-10-16 | [#2521](https://github.com/metadist/synaplan/pull/2521) (plan) | 2 | 🟡 Changes recommended: all six round-1 findings resolved; 1 new thread, 3 "previously missed" | All four accepted after checking them. (1) `downloadMedia()` uses native cURL and `file_get_contents()` (lines 1,243–1,264), which no HTTP double intercepts: S5.1 moves that transport verbatim behind an injectable `MediaDownloader` before the snapshot, and the snapshot covers external URLs, data URLs and a download failure. (2) The loop asked for red commits while every commit must be green: loop step 4 and §4 now run a regression test red on the parent, quote the failure in STATUS and commit it with its fix; S0.1 and S8.1 commit a green pin of today's behaviour that each fix tightens. (3) `vision` still differs after S3.5 (the classifier keeps an existing topic): it gets its own `fix:` (S3.6) before the collapse (S3.7). (4) `rg -L` follows symlinks: S11's exit check uses `--files-without-match`, verified to list today's six files. Same-class audit: `OpenAIProvider::generateImage()` calls native `curl_init()` (line 1,188), so S10.1 gives it the same kind of seam. Decision #7 now names the narrow exception for seams that only make a transport recordable (S5.1, S10.1). The S1 ledger command is spelled out. |
| 2026-10-16 | [#2521](https://github.com/metadist/synaplan/pull/2521) (plan) | 3 | 🔵 Needs a closer look: 0 open threads, round-2 thread resolved, 3 "previously missed" | All three accepted after checking them. (1) Loop §5 forbade recording snapshots outside `fix:` sprints, but `RoutingCharacterizationTest` (lines 83–84) fails every new case until it has a baseline, so S6.1 could not finish. Additive recording (new files, cases or fields, on untouched code, in the `test:` commit) is now allowed in any sprint; changing an existing expectation stays `fix:`-only. (2) A new `Message` has topic `UNKNOWN` (`Message.php` line 63), and the classifier's fallback keeps it for unmapped tags, while D2 says `general`. S3 pins empty / `unknown` / `UNKNOWN` and fixes the fallback in S3.7 before the collapse (S3.8). (3) `createVariations()` and `editImage()` read their image and mask URLs with `file_get_contents()` (lines 1,511, 1,550–1,551): S10 runs them on local fixtures. The same-class audit extends that to every input-file read in both providers (`$uploadDir` fixtures). The three-round cap (loop §8) is reached: a fourth round is the owner's call. |

## Merges

| Date | Pull request | Merge commit | Ledger after |
| ---- | ------------ | ------------ | ------------ |
