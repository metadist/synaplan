# Status — Cloud file sharing

Plan of record: [`00_master_plan.md`](./00_master_plan.md). Sprints and
spikes: [`06_sprints.md`](./06_sprints.md). A walk is a dated entry here
(date, account, what was clicked, what was found, PR if something was fixed),
not a chat message.

## Steps

| Step | Repo | State | Notes |
| ---- | ---- | ----- | ----- |
| E-1 Cleanup and baseline | workstation | **in progress** | §2.1 done 2026-10-13; §2.2 volumes and signing keys done 2026-10-14. Still open: scheduler container exited (127) |
| E-2 Nextcloud stack v2 (MariaDB, cron, `user_oidc`, test users) | `synaplan-nextcloud` | planned | after E-3 |
| E-3 Keycloak clients and users for the clouds | `synaplan` | planned | additive block in `_docker/keycloak/setup.sh` |
| E-4 OpenCloud refresh to 8.1.0 | `synaplan-opencloud` | planned | config volume removed 2026-10-14; fresh `init` is part of this step |
| E-5 ownCloud.online Docker stack | `synaplan-owncloud-online` | planned | port 8083; may finish during CF1.2 |
| E-6 `env-check.sh`, fixtures, cloud helpers | `synaplan` | planned | `_devextras/testing/cloud-apps/` |
| E-7 Developer docs | all | planned | |
| E-8 Refresh policy | STATUS | recurring | monthly or on `max-version` change |
| CF-S1 OpenCloud web extension points | `synaplan-opencloud` | planned | timebox 2 days |
| CF-S2 Nextcloud organisation login (`user_oidc`) | `synaplan-nextcloud` | planned | |
| CF-S3 OpenCloud unattended access | `synaplan-opencloud` | planned | Includes the watch index at the cap (row 41) |
| CF-S4 ownCloud 11 Files APIs | `synaplan-owncloud-online` | planned | Needs a test tenant on 11.0.10 from the hoster; includes the watch index at the cap (row 41) |
| CF-S5 Nextcloud Files APIs at scale | `synaplan-nextcloud` | planned | Includes the watch index at the cap (row 41) |
| CF0.0 Local stacks ready (E-1…E-6) | workstation, repos | planned | gate for CF0.7 |
| CF0.1 Scope map for the paths the apps call (D1) | `synaplan` | planned | |
| CF0.2 `owncloud` source (D2) | `synaplan` | planned | Data fix per §3 row 26 |
| CF0.3 `intake=direct` (D3) | `synaplan` | planned | |
| CF0.4 `check-stale` returns `vector_state` | `synaplan` | planned | |
| CF0.5 Docs truth (D10, D11) | `synaplan-docs`, `synaplan-nextcloud` | planned | |
| CF0.6 STATUS hygiene (D14) | `synaplan` | planned | After §3 is ticked |
| CF0.7 First walks | all | planned | J-NC-1…3 are also on the roadmap §3.1 list |
| CF1.1 ownCloud.online link mode, consent, MIME, key encryption | `synaplan-owncloud-online` | planned | |
| CF1.2 ownCloud.online tests, CI, AGENTS.md, local stack | `synaplan-owncloud-online` | planned | |
| CF1.3 Nextcloud CI, AGENTS.md, key encryption, E2E | `synaplan-nextcloud` | planned | |
| CF1.4 Five locales, core words | all three apps | planned | |
| CF1.5 OpenCloud chat, media, binary save | `synaplan-opencloud` | planned | Cut line |
| CF2.1 Instance columns, capabilities, OpenCloud instances | `synaplan` | planned | Schema ask recorded |
| CF2.2 Canonical identity rows, SSO bind and attribution | `synaplan` | planned | D15; adoption of provisioned rows (row 39) |
| CF2.3 Background key, `cloud:watch` | `synaplan` | planned | |
| CF2.4 Lifecycle endpoint | `synaplan` | planned | D16; never deletes an account still in use (row 38, C18) |
| CF2.5 Apps cards for three clouds | `synaplan` | planned | |
| CF2.6 Nextcloud organisation login, identity rows, lifecycle | `synaplan-nextcloud` | planned | Organisation login after CF-S2; adoption and lifecycle ship either way |
| CF2.7 OpenCloud registration, consent, bind, Connect mode | `synaplan-opencloud` | planned | Connect mode is a cut line |
| CF2.8 ownCloud.online lifecycle, identity rows, admin copy | `synaplan-owncloud-online` | planned | |
| CF2.9 Files remember their cloud | `synaplan` | planned | D17; schema ask recorded (`BFILES.BSOURCEINSTANCE`, row 40); after CF2.2 |
| CF3.1 Copy protocol and ledger | `synaplan` | planned | Schema ask recorded; signed notifications (row 37, C20) |
| CF3.2 Copy-to-cloud menu, toast, Sent to | `synaplan` | planned | |
| CF3.3 Nextcloud inbound, From Synaplan | `synaplan-nextcloud` | planned | |
| CF3.4 ownCloud.online inbound, From Synaplan | `synaplan-owncloud-online` | planned | |
| CF3.5 OpenCloud inbound, binary save, From Synaplan | `synaplan-opencloud` | planned | |
| CF3.6 Pickup protocol, `folder_fetch`, read-scope mirror, WebDAV list/get | `synaplan` | planned | Schema ask recorded (`BCLOUDPICKUPS`, `BREADSCOPE`) |
| CF3.7 "Read N files" run card, refusal sentence, read scope on Apps card | `synaplan` | planned | |
| CF3.8 Nextcloud pickup handler, read setting | `synaplan-nextcloud` | planned | After CF-S5 |
| CF3.9 ownCloud.online pickup handler, read setting | `synaplan-owncloud-online` | planned | After CF-S4 |
| CF3.10 OpenCloud pickup handler, read setting | `synaplan-opencloud` | planned | After CF-S1, CF-S3 |
| CF4.1 Status, file detail, source events | `synaplan` | planned | |
| CF4.2 Glyph kit | `synaplan` + design | planned | |
| CF4.3 Nextcloud bird, sidebar | `synaplan-nextcloud` | planned | |
| CF4.4 ownCloud.online bird, sidebar | `synaplan-owncloud-online` | planned | |
| CF4.5 OpenCloud sidebar, upstream request | `synaplan-opencloud` | planned | |
| CF5.1 Watch model, ledger, `file_event` | `synaplan` | planned | Schema ask recorded; folder and file targets; paginated watch index and caps (row 41) |
| CF5.2 Presets | `synaplan` | planned | |
| CF5.3 Tasks → Watched folders | `synaplan` | planned | |
| CF5.4 Nextcloud watches | `synaplan-nextcloud` | planned | |
| CF5.5 ownCloud.online watches | `synaplan-owncloud-online` | planned | |
| CF5.6 OpenCloud watches | `synaplan-opencloud` | planned | |
| CF5.7 Files attached to runs, file sets, run limits | `synaplan` | planned | |
| CF5.8 Try it on a file, then automate; unattended file-work consent | `synaplan` | planned | |
| CF5.9 Dialog file sets and trial in the three apps | all three apps | planned | |
| CF6.x Beyond the apps | various | optional | |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-10-13 | Track opened from the product owner's request: copy generated files into the cloud, one login story and user handling, synchronized file handling (bird, bird with eyes, automations). Master-plan §3 rows 1–26 proposed; **none ticked**. |
| 2026-10-13 | Product-owner clarification: "A pure path in a specific prompt has no meaning … pick up files or file lists from the file sharing app … fetch files and process those on Synaplan automatically, even in the code sandbox with specific tools." Added [`07_files_into_runs.md`](./07_files_into_runs.md) and §3 rows 27–32 (files not paths, what each run gets, `folder_fetch` + pickup, read scope, file work, try then automate). The `{file.path}` placeholders are withdrawn. Rows proposed, **not ticked**. |
| 2026-10-13 | Second review ("prepare for vibe coding"): added [`08_agent_briefs.md`](./08_agent_briefs.md) (rules R1–R14, done D1–D10, ask A1–A7, one brief per spike and step), [`09_functional_tests.md`](./09_functional_tests.md) (three layers per sprint, fixtures, accounts, regression set) and [`10_local_environments.md`](./10_local_environments.md) (pins, inventory, cleanup, E-1…E-8). The three open questions of the first review became §3 rows 33–35; dev-mode test hooks are row 36. Rows proposed, **not ticked**. Local cleanup of `10` §2.1 executed; `10` §2.2 waits for the owner. |
| 2026-10-13 | Copilot review of [#2467](https://github.com/metadist/synaplan/pull/2467) (six findings, review log below): §3 rows 37–41 added (notification proof, deleting a person in one cloud, provisioned identities, files remember their cloud, watch index); row 16 now says a watch may look at one file; row 11 notifications are signed. Defects D15–D17, invariants C18–C20, step CF2.9 and journey J-CF-15 added. Rows proposed, **not ticked**. |
| 2026-10-14 | Product owner: the proposed defaults of §3 rows 1–36 stand. Rows 37–41 stay proposed — they were not in that confirmation. `10` §2.2 done: Nextcloud dev volume removed (container stays down until E-2), OpenCloud 6.0.0 config volume removed, signing-key tarballs moved to `synaplan-nextcloud/.local/` with a local README. |

## Review log

**2026-10-13 (plan drafted).** Written from code reading of `synaplan`
`5487e2678`, `synaplan-nextcloud` `400ec02`, `synaplan-owncloud-online`
`3b916f6`, `synaplan-opencloud` `5cc1351`. Fourteen defects recorded in
[`01`](./01_baseline_and_gaps.md) §4. Three block the parity floor today:

- **D1** — platform keys are not mapped onto the paths the Nextcloud and
  ownCloud.online apps call (`/api/v1/summary/generate`,
  `/v1/chat/completions`, `/api/v1/media/generate`,
  `/api/v1/config/memory-service/check`); by code reading the provision and
  link modes get `403 insufficient_scope` on summarize, translate, chat and
  media.
- **D2** — `owncloud` is missing from `File::SOURCES`; ownCloud.online's
  knowledge status can never match.
- **D3** — integration uploads always land in Incoming, also when a
  knowledge folder was named.

No stack was walked for this plan; CF0.7 reproduces D1–D3 in a browser
before the fixes merge.

**2026-10-13 (files, not paths).** Re-checked against the core: a task run
is a normal `Message` (`SavedTaskRunner::run()`), `Message::addFile()` exists
and `MessagePreProcessor` extracts every attachment; `CodeRunRunner` mounts
`inputFileIds` or the message's attachments into the sandbox and stores `/out/`
results as files; unattended file work waits for approval unless the task's
`BALLOWUNATTENDED` is set; `save_to_folder` takes earlier steps' attachments
and a folder channel. The design therefore attaches every cloud file to the
run message instead of inventing a second input path. Watch runs stay pushed
by the app (they work when Synaplan cannot reach the cloud); fetching more
files during a run uses a ticket-bound pickup, which needs Synaplan to reach
the cloud until the CF6.2 pull mode.

Open questions for the product owner, besides §3 — turned into tickable
rows with a proposed default in the second review:

1. Is ownCloud.online an active hoster target this quarter? → `00` §3 row 33.
2. OpenCloud chat parity (CF1.5): before the three goals, or after CF5? →
   row 34.
3. Which comes first if capacity is short: Nextcloud organisation login
   (CF2.6) or OpenCloud Connect mode (CF2.7)? → row 35.

**2026-10-13 (second review — ready for coding agents).** Read every file
for fuzzy or unclear statements and for sections too thin to hand to an
agent. Findings and what changed:

- **Steps without work orders.** `06` named steps but not files, tests or
  acceptance. Every spike and step now has a brief in `08` (repo, branch,
  depends-on, reads, touches, build list, tests, acceptance, don'ts) plus the
  rules an agent follows before, during and after (`08` §0), a PR template and
  the dependency / parallelism map (`08` §1).
- **"Walk the journeys" was the whole test plan.** `09` now fixes three
  layers per sprint with named scripts and their assertions, click-by-click
  checklists with the expected sentence and where it is found, fixtures and
  accounts (Ada, Bob, admins), stability, performance (status call p95 <
  1.5 s on 500 ids), accessibility, locale and flag-off checks, and the
  regression set run after every sprint.
- **Environments were a sentence** ("Nextcloud `make dev-up` on `:8081`, …").
  `10` pins every stack, records what was found on the maintainer
  workstation (a running Nextcloud 31 on SQLite without cron or `user_oidc`;
  dead OpenCloud 6.0.0 containers against a compose pinned to 8.1.0; no
  ownCloud.online stack at all, only a host Apache install on `:8088`; the
  scheduler container exited; a 7 GB bare Nextcloud 34 dev checkout and two
  tarballs with the App Store signing key in the web root), what was removed,
  what needs the owner's word, and eight environment briefs E-1…E-8 with
  acceptance. `06` gained step CF0.0 (stacks ready) as the gate for CF0.7.
- **Spikes had no end.** Timebox of two working days, a `spike/…` branch
  that is never merged, yes / no / partly per question, overrun = answer
  (decision 34).
- **Tests needed back doors the plan never named.** Decision 36 and `08`
  §0.8 define the only three dev switches per app and two `APP_ENV`-gated
  core values; `09` uses nothing else.
- **Endpoint drift.** `02` §8 lacked the turn-off and disconnect routes the
  Apps card uses; added. CF2.2 now adds all four `BEXTERNALIDENTITIES`
  columns at once so later briefs touch that table without a migration.
- **Vague wording fixed in place:** CF1.2's "local ownCloud stack (Docker,
  fixed port, joins the Synaplan network)" → the E-5 stack with its image,
  tag source, database, cron sidecar and port; rollout step 3 → the pinned
  stacks and the regression set.

Not changed on purpose: the protocol numbers (30 s copy budget, 25 s inline
pickup, 60 s quiet period, 10 min window, 5 / 10 min tickets, 7-day grace,
50 files / 200 MB per run) — they were already explicit and `09` tests them
as written. No product code was touched.

**2026-10-13 (Copilot review on #2467).** Six findings, each re-checked
against the code at the commits of `01` §1 before changing the plan. All six
hold; the identity one is wider than reported.

- **Deleting a cloud user could delete an account another cloud still uses
  (high).** Both apps call the generic admin delete
  (`DELETE /api/v1/admin/users/{id}`, `AdminController::deleteUser()`) from
  `deleteRemoteAccount()`, unconditionally. Recorded as D16. `02` §7 now
  revokes only that identity and its keys, and deletes the account only while
  that cloud alone manages it (provisioned for it, no other active identity,
  no password, not an admin); for an account the rule keeps, the
  admin delete answers `409 account_in_use` to API keys. Row 38, C18, CF2.4,
  CF2.6, CF2.8, `09` §3.
- **Unauthenticated notifications turn forged requests into outbound calls
  (high).** The core already signs federation messages with Ed25519
  (`FederationSigner`) and keeps that key pair encrypted in `BCONFIG`
  (`FederationIdentityStore`). Notifications now use the same scheme with
  their own key; the app checks signature, instance, time and seen ids before
  it calls back, and rejects everything else with `401`, rate-limited and
  without a log line each. The instance secret stays hash-only. Row 37, C20,
  `03` §2 / §5 / §8, `07` §4, CF3.1, CF3.3–CF3.5, `cf3-inbound.sh`.
- **File lookups collide across instances (high).** `findForOverwrite()` and
  `findByUserSourceIds()` match `(user, source, source_id)` only. Recorded as
  D17. New step CF2.9: `BFILES.BSOURCEINSTANCE`, taken from the credential;
  rows without an instance are matched only for owners with one identity of
  that client. Row 40, C19, `04` §A.3 / §A.5 / §E.2, `cf2-file-instance.sh`.
- **Provisioning does not write the canonical identity tuple (medium).**
  Confirmed, and not only ownCloud.online: both apps provision with
  `external_id = '<cloud instanceid>:<uid>'`, the core stores
  `BINSTANCEID = ''`, and the key minted afterwards is never bound to the row
  (`AdminUserProvisioningService::upsertIdentity()`). Recorded as D15. `02`
  §3.1: provisioning with the instance credential writes the canonical row;
  existing rows are adopted per person with the instance credential and that
  person's key. Row 39, CF2.1 (`BSERVERID`), CF2.2, CF2.6, CF2.8.
- **Caching every active watch of an instance is unbounded (medium).** `04`
  §B.8 now defines a watch index filled from a paginated, incremental call
  (500 per page, `since` cursor with tombstones, `ETag`), kept in an app
  table, and bounded by caps of 50 active watches per person and 5 000 per
  instance (B.7). CF-S3, CF-S4 and CF-S5 measure it at the cap. Row 41, C14,
  CF5.1, CF5.4, `cf5-index.sh`. CF5.4's "every 60 s" now matches B.8's
  10 minutes.
- **No journey covers a single-file watch (medium).** File watches stay in
  scope — the request asked for "watch files and directories". New journey
  J-CF-15 (a translate watch on one file: new version, rename, pause, delete
  and restore, stop), `04` §B.1 / §B.2 / §B.3 for what a file watch reacts
  to, the dialog's file variant (W6, two copy rows in five locales), a file
  row in W7, CF5 user-flow and exits, `09` §6 L2 file block and L3 row 14.

No product code was touched.
