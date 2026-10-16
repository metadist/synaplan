# S0 — Erase every user-owned table with the account

| | |
| - | - |
| **V3 §7** | #0 — security / privacy, therefore first |
| **Branch** | `fix/cleanup-v4-s0-erasure`, from `docs/cleanup-v4-plan` (or `origin/main` once the plan has merged) |
| **Pull request** | `fix(iam): erase every user-owned table with the account` |
| **Class** | backend-only |
| **Owner decision** | — |
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

A revision's `BUSERID` is always the **file owner's** id
(`DocumentRevisionService`, line 66), so every revision with the user's id
belongs to one of the user's own files. Deleting them never touches
another person's restore chain.

Kept on purpose, recorded in the pull request: an approval the user
*decided* on a resource someone else owns (`BDECIDEDBY` is the owner's
audit record and holds only an id).

## 3. Steps

| Step | Commit | What |
| ---- | ------ | ---- |
| S0.1 | `test(iam): a deleted account leaves no row behind` | `UserErasureLeavesNoRowTest` (integration): a user with one row in every user-owned table, the 12 included, is deleted through `UserDeletionService`. Then every user-owned table (the coverage test's metadata scan) is queried for the id. On the parent exactly the 12 tables still hold rows; quote the run in STATUS. The test is **committed green**: it asserts that the tables still holding rows are exactly `NOT_ERASED_YET`. Each fix commit shrinks that list, so the test tightens with it, and once S0.8 deletes the list it asserts that no table holds a row. |
| S0.2 | `fix(iam): erase connections, credentials and custom tools with the account` | `ConnectionEraser` at priority 195, right after `AccessCredentialsEraser`: secrets go first. They are forgotten through the vault **inside the transaction**: `AesCredentialVault::forget()` removes the row in the database, so a failure there rolls the whole deletion back. It is never queued after the commit, and never replaced by a raw `DELETE` that bypasses the vault. |
| S0.3 | `fix(iam): erase compute runs and workspaces with the account` | Rows in the transaction. The sidecar workspace needs a cleanup that does not depend on those rows. `ComputeWorkspaceExpirer` finds its work through the very row the eraser deletes, so it cannot be the backstop. After the commit the eraser dispatches a `DeleteComputeWorkspace` message (workspace id only) to the async Messenger transport. Its retry strategy retries a busy or unreachable sidecar; after the last attempt the message waits in the `failed` transport, visible to operators and replayable. `workspace_not_found` counts as done. A busy workspace never fails the account deletion. If the dispatch itself fails, the deletion still completes and the error log names the workspace id. That one residual gap is stated in the pull request. |
| S0.4 | `fix(iam): erase desktop jobs and devices with the account` | Jobs, then devices. A paired desktop app gets a 401 on its next check-in and deletes its local key (desktop contract). |
| S0.5 | `fix(iam): erase conversation summaries with the account` | In `ConversationEraser`. The Redis copies are invalidated after the commit; their TTL is the backstop, and it does not depend on any row. |
| S0.6 | `fix(iam): erase document revisions with the account` | In `FileEraser`, next to the files they version. |
| S0.7 | `fix(iam): erase approvals, URL watches and live voice sessions with the account` | Three small erasers. A live session still open is closed after the commit, best effort. |
| S0.8 | `test(iam): no user-owned table may be left behind` | `NOT_ERASED_YET` is empty and deleted; the coverage test now fails on any uncovered entity, with no list to extend. `ORDER` gains the new erasers. |
| C0 | `chore(planning): close V4 S0 — sprint test, status, ledgers` | Loop §7 |

Each `fix(iam)` commit removes its entities from `NOT_ERASED_YET` and
adds a unit test per eraser: the rows it deletes inside the transaction,
and, where there is one, the external call it queues (the compute cleanup
message, Redis, voice), with a test double that asserts it runs **after**
the commit.

## 4. Sprint test

- **Proof:** `UserErasureLeavesNoRowTest`: on the parent, 12 tables hold
  rows (quoted in STATUS); at the end, none does, with no exception list. Per eraser: a unit test for its rows and its
  after-commit call. Mutation check: remove one eraser's tag and see both
  the coverage test and the no-row test fail.
- **Regression:** the pinned `ORDER` changes only by the new erasers;
  every other test green unchanged; unfiltered gates. A deletion whose
  after-commit cleanup throws still completes (one test per eraser with a
  throwing double). A vault failure rolls the whole deletion back: the
  user and every row are still there.
- **Durable compute cleanup:** the `DeleteComputeWorkspace` handler fails
  once with `workspace_busy`, the retry delivers it again, and the second
  attempt deletes the workspace. `workspace_not_found` is acknowledged
  without a retry.
- **Journey:** `account-deletion.spec.ts` (`@ci`) in `make test-e2e`.
  Then in the browser: a user with a connection, a paired desktop device
  and a chat with a summary deletes the account from the profile page.
  The same email signs up again and sees none of it.

## 5. Reviewer focus and security impact

The pull request states:

- **What is erased:** the 12 tables, per domain.
- **In which order:** the tag priorities.
- **What happens after the commit:** the compute cleanup message (durable,
  retried, parked in `failed` after the last attempt), the Redis summary
  copies (TTL backstop), an open live-voice session (the provider's own
  timeout is the backstop). None of them can undo the deletion. Credentials
  are not on this list: they are deleted inside the transaction.
- **What is kept, and why:** `BDECIDEDBY` on other owners' approvals.

Review the vault path: no secret may survive in the database, in logs or
in an exception message. Review the Messenger routing of the new message:
it must reach a transport with a retry strategy and a failure transport,
never the synchronous bus.

## 6. Exit

- `NOT_ERASED_YET` deleted; the coverage test has no exception list.
- `UserErasureLeavesNoRowTest` on `main`.
- `docs/ARCHITECTURE.md` "Tagged lists" lists the 21 erasers (15 + 6 new)
  and what each one covers.

## 7. Not in S0

Data export (GDPR access requests). Organisation-owned rows; they belong
to the organisation, not the person. Backups (an operator concern,
`synaplan-platform`).

## 8. As delivered (2026-10-16)

Seven new erasers instead of six; the rest follows the plan.

- **Credentials are swept last.** `ConnectionEraser` (195) forgets each
  connection's secret through the vault, as planned. Forgetting *every*
  credential at 195 would have broken the Telegram webhook drop:
  `IdentityEraser` (60) reads the bot token from the vault before it can
  drop the webhook after the commit. A seventh eraser, `CredentialEraser`
  (50, the last), forgets whatever secret the account still owns, an
  orphaned credential included. Both run inside the transaction. A
  dangling credential reference on a connection counts as already
  forgotten instead of blocking the deletion.
- **One guard for every after-commit cleanup.** A cleanup that threw after
  the commit made `UserDeletionService` roll back a committed transaction
  and report the deletion as failed (regression test red on the parent,
  quoted in STATUS). Each cleanup now runs under its own guard and is
  logged, so the per-eraser "throwing double" tests are one test in
  `UserDeletionServiceTest`, covering the older erasers' cleanups too.
- **The no-row test seeds from the mapping.** `UserErasureLeavesNoRowTest`
  writes one row into every user-owned table straight from its Doctrine
  mapping (foreign keys off while seeding), so a table added later is
  covered without editing the test. `UserOwnedTables` is the scan both
  tests share.
- **Bulk deletes for row-only tables.** Approvals, URL watches, desktop
  jobs and devices, compute runs, custom tools, revisions, summaries and
  voice sessions go with one `DELETE` each; rows are loaded only where the
  eraser needs a value from them (connection secrets, workspace ids, an
  open voice session's provider).
- **Priorities** put each eraser next to its domain: Desktop 185, Approval
  145, UrlWatch 135, VoiceSession 125, Compute 115, Credential 50. No
  foreign key forces an order among the twelve tables.
- **Compute cleanup** rides `async_index` (five retries, then `failed`).
  A run can hold a workspace for up to `MAX_TIMEOUT_SEC` (300 s by
  default), longer than the retry window, so a long run parks the message
  in `failed` for an operator to replay. A new transport would need a
  worker change (AGENTS.md, ask first).
- **Journey.** `account-deletion.spec.ts` only covers the public info
  page; the walk on the dev stack is recorded in STATUS.
- **Review round 1 (Copilot, two findings).** `FileEraser` deleted the
  stored bytes inside the transaction, so a late failure (the new vault
  sweep, or any later eraser) rolled the rows back over missing files: the
  bytes now go after the commit, and the same audit moved
  `KnowledgeEraser`'s vector-store purge there too (its rows stay inside).
  A summary refresh that outlived the account could write the summary back:
  `ChatSummaryRepository::upsert()` now inserts only while the chat exists
  and belongs to the user, in the same statement, and the Redis copy is
  written only when the row was. Carried: the other post-turn workers
  (memory extraction, digests, the Smart Search index) can still write a
  row for an account deleted while they run.
