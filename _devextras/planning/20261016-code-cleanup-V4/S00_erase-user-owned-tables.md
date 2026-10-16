# S0 — Erase every user-owned table with the account

| | |
| - | - |
| **V3 §7** | #0 — security / privacy, therefore first |
| **Branch** | `fix/cleanup-v4-s0-erasure`, from `docs/cleanup-v4-plan` (or `origin/main` once the plan has merged) |
| **Pull request** | `fix(iam): erase every user-owned table with the account` |
| **Class** | backend-only |
| **Owner decision** | **D4** — a revision the user made on someone else's file (§2). Tick before step S0.6. |
| **Chore** | C0 |

## 1. Goal

When a person deletes their account, nothing they owned stays behind: no
stored secret, no connection, no conversation summary, no document
history, no paired computer. Today 12 tables survive the account. V3's
coverage test found them and froze them in a shrink-only list. S0 empties
that list and deletes it.

## 2. Measured (2026-10-16)

`UserDeletionService` runs 15 tagged erasers (`Service/Iam/Erasure/`,
priority 200 → 60) inside one transaction. Work outside the database is
queued with `UserErasure::afterCommit()` and runs after the commit.
`UserErasureCoverageTest::NOT_ERASED_YET` lists the gaps. **No foreign key
touches any of the 12 tables** (dev database, `information_schema`), so
no erase order is forced by a constraint. The order below follows the
logical links (jobs before devices, runs before workspaces).

| Domain → eraser | Entity (table, owner column) | What else the row holds | Today's delete path |
| --------------- | ---------------------------- | ----------------------- | ------------------- |
| **Connections** → new `ConnectionEraser` | `Credential` (`BCREDENTIALS.BOWNERID`) | encrypted secret (`AesCredentialVault`) | `CredentialVaultInterface::forget()` |
| | `Connection` (`BCONNECTIONS.BOWNERID`) | `BCREDENTIALID` → a credential | `ConnectionService::delete()` (forgets the credential, removes the row) |
| | `CustomTool` (`BTOOLS.BOWNERID`) | `BCREDENTIALID`, the tool spec | `CustomToolRepository::remove()` |
| **Compute** → new `ComputeEraser` | `ComputeRun` (`BCOMPUTERUNS.BUSERID`) | audit metadata only, artefact file ids (files go with `FileEraser`) | none |
| | `ComputeWorkspace` (`BCOMPUTEWORKSPACES.BUSERID`) | an opaque sidecar workspace id | `ComputeWorkspaceService::delete()` → `ComputeClient::deleteWorkspace()` |
| **Desktop** → new `DesktopEraser` | `DesktopJob` (`BDESKTOPJOBS.BOWNERID`) | job input / result, lease | none |
| | `DesktopDevice` (`BDESKTOPDEVICES.BOWNERID`) | `BAPIKEYID`; the key itself is erased by `AccessCredentialsEraser` (`ApiKey`) | `DesktopDeviceRepository::remove()` |
| **Conversations** → `ConversationEraser` (extended) | `ChatSummary` (`BCHATSUMMARIES.BUSERID`) | the summary text; a Redis hot copy (`ConversationSummaryService`) | `ChatSummaryRepository::deleteByChatId()` (chat deletion only) |
| **Files** → `FileEraser` (extended) | `DocumentRevision` (`BDOCUMENT_REVISIONS.BUSERID`) | version summary, binary hash | `DocumentRevisionRepository::deleteForFile()` (file deletion only) |
| **Approvals** → new `ApprovalEraser` | `Approval` (`BAPPROVALS.BOWNERID`) | tool arguments and preview | none |
| **URL watches** → new `UrlWatchEraser` | `UrlWatch` (`BURLWATCHES.BOWNERID`) | fetched page text, last diff | none |
| **Live voice** → new `VoiceSessionEraser` | `VoiceSession` (`BVOICESESSIONS.BUSERID`) | provider session id, usage link (the usage row goes with `UsageLogEraser`) | none |

**D4 proposed:** revisions of the user's own files go with the files. A
revision the user made on a file someone else owns keeps the owner's
restore chain intact. Its author is anonymised, with the same "deleted
user" value the rest of the platform uses, and S0.1 records which one.
If no such value exists, the revision is deleted, and the pull request
says so.

Kept on purpose, recorded in the pull request: an approval the user
*decided* on a resource someone else owns (`BDECIDEDBY` is the owner's
audit record and holds only an id).

## 3. Steps

| Step | Commit | What |
| ---- | ------ | ---- |
| S0.1 | `test(iam): a deleted account leaves no row behind` | `UserErasureLeavesNoRowTest` (integration): a user with one row in every user-owned table, the 12 included, is deleted through `UserDeletionService`. Then every user-owned table (the coverage test's metadata scan) is queried for the id. **Red on the parent with exactly the 12 tables** (quote it in STATUS). Records D4's "deleted user" value. |
| S0.2 | `fix(iam): erase connections, credentials and custom tools with the account` | `ConnectionEraser` at priority 195, right after `AccessCredentialsEraser`: secrets go first. They are forgotten through the vault, never by a raw `DELETE` that bypasses it. |
| S0.3 | `fix(iam): erase compute runs and workspaces with the account` | Rows in the transaction. The sidecar workspace is deleted after the commit: best effort and logged. The workspace's own expiry (`BEXPIRESAT`) is the backstop. A busy workspace must not fail an account deletion. |
| S0.4 | `fix(iam): erase desktop jobs and devices with the account` | Jobs, then devices. A paired desktop app gets a 401 on its next check-in and deletes its local key (desktop contract). |
| S0.5 | `fix(iam): erase conversation summaries with the account` | In `ConversationEraser`; the Redis copies are invalidated after the commit. |
| S0.6 | `fix(iam): erase document revisions with the account` | In `FileEraser`, per D4. |
| S0.7 | `fix(iam): erase approvals, URL watches and live voice sessions with the account` | Three small erasers. A live session still open is closed after the commit, best effort. |
| S0.8 | `test(iam): no user-owned table may be left behind` | `NOT_ERASED_YET` is empty and deleted; the coverage test now fails on any uncovered entity, with no list to extend. `ORDER` gains the new erasers. |
| C0 | `chore(planning): close V4 S0 — sprint test, status, ledgers` | Loop §7 |

Each `fix(iam)` commit removes its entities from `NOT_ERASED_YET` and
adds a unit test per eraser: the rows it deletes, and the external call
it queues (vault, compute client, Redis, voice) with a test double that
asserts it runs **after** the commit.

## 4. Sprint test

- **Proof:** `UserErasureLeavesNoRowTest` red on the parent (12 tables),
  green at the end. Per eraser: a unit test for its rows and its
  after-commit call. Mutation check: remove one eraser's tag and see both
  the coverage test and the no-row test fail.
- **Regression:** the pinned `ORDER` changes only by the new erasers;
  every other test green unchanged; unfiltered gates. A deletion whose
  after-commit cleanup throws still completes (one test per eraser with a
  throwing double).
- **Journey:** `account-deletion.spec.ts` (`@ci`) in `make test-e2e`.
  Then in the browser: a user with a connection, a paired desktop device
  and a chat with a summary deletes the account from the profile page.
  The same email signs up again and sees none of it.

## 5. Reviewer focus and security impact

The pull request states:

- **What is erased:** the 12 tables, per domain.
- **In which order:** the tag priorities.
- **What happens after the commit:** vault, sidecar, Redis, voice. Each
  step is best effort and logged, and none can undo the deletion.
- **What is kept, and why:** `BDECIDEDBY` on other owners' approvals, and
  anonymised revisions per D4.

Review the vault path: no secret may survive in the database, in logs or
in an exception message.

## 6. Exit

- `NOT_ERASED_YET` deleted; the coverage test has no exception list.
- `UserErasureLeavesNoRowTest` on `main`.
- `docs/ARCHITECTURE.md` "Tagged lists" lists the 21 erasers (15 + 6 new)
  and what each one covers.

## 7. Not in S0

Data export (GDPR access requests). Organisation-owned rows; they belong
to the organisation, not the person. Backups (an operator concern,
`synaplan-platform`).
