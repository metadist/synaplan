# Status — Cloud file sharing

Plan of record: [`00_master_plan.md`](./00_master_plan.md). Sprints and
spikes: [`06_sprints.md`](./06_sprints.md). A walk is a dated entry here
(date, account, what was clicked, what was found, PR if something was fixed),
not a chat message.

## Steps

| Step | Repo | Phase | State | Notes |
| ---- | ---- | ----- | ----- | ----- |
| E-1 Cleanup and baseline | workstation | P0 | **done** 2026-10-19 | §2.1 done 2026-10-13; §2.2 done 2026-10-14; scheduler running and healthy since 2026-10-17 (cause of the exit 127 not recorded — `10` §1.4) |
| E-2 Nextcloud stack v2 (MariaDB, cron, `user_oidc`, test users `ada` / `bob` / `carol`) | `synaplan-nextcloud` | P0 | **done** 2026-10-19 · merged [synaplan-nextcloud#45](https://github.com/metadist/synaplan-nextcloud/pull/45) | 35.0.2 running, 33.0.10 checked; organisation login works on both |
| E-3 Keycloak clients and users for the clouds | `synaplan` | P0 | **done** 2026-10-19 · merged [#2566](https://github.com/metadist/synaplan/pull/2566) | `_docker/keycloak/setup.sh` "Cloud apps" block, `docs/DEVELOPMENT.md`; local `backend/.env` OIDC values |
| E-4 OpenCloud refresh to 8.1.0 | `synaplan-opencloud` | P0 | **done** 2026-10-19 | fresh `init`, client regenerated, extension rebuilt; no file changed; E2E 10/10 with one worker |
| E-5 ownCloud.online Docker stack | `synaplan-owncloud-online` | P3 | planned | port 8083, tag `v11.0.10` (`OCO_TAG`); first brief of P3 |
| E-6 `env-check.sh`, fixtures, cloud helpers, `use-test-provider.sh` | `synaplan` | P0 | **in progress** | `env-check.sh` merged ([#2566](https://github.com/metadist/synaplan/pull/2566)); fixtures, `lib.sh`, cloud helpers and `use-test-provider.sh` open. The Nextcloud e2e (CF1.3b part 3) runs on the plain test stack plus Redis meanwhile |
| E-7 Developer docs | all | any | planned | after the stack it documents is green |
| E-8 Refresh policy | STATUS | — | recurring | monthly or on `max-version` change |
| E-9 Reference client `cloud-fixture` | `synaplan` | P1 (before CF2.1) | **in review** · [#2674](https://github.com/metadist/synaplan/pull/2674) · issue [#2669](https://github.com/metadist/synaplan/issues/2669) | `_devextras/testing/cloud-apps/fixture-app/`; profiles `keyed` and `sso` (row 45); plays copies (CF3.1), pickups (CF3.6) and linking; extended by every core brief |
| CF-S1 OpenCloud web extension points | `synaplan-opencloud` | P1 (before CF3.1) | planned | timebox 2 days |
| CF-S2 Nextcloud organisation login (`user_oidc`) | `synaplan-nextcloud` | P1 (start) | planned | decides CF2.6; records the `azp` Nextcloud would send |
| CF-S3 OpenCloud unattended access | `synaplan-opencloud` | P1 (before CF3.1) | planned | Includes the watch index at the cap (row 41) |
| CF-S4 ownCloud 11 Files APIs | `synaplan-owncloud-online` | P1 (before CF3.1) | planned | Host install on `:8088`; asks the hoster for its version and a test tenant; includes the watch index at the cap (row 41) |
| CF-S5 Nextcloud Files APIs at scale | `synaplan-nextcloud` | P1 (start) | planned | Includes the watch index at the cap (row 41) |
| CF0.0 Local stacks ready (E-1, E-2, E-3, E-6; E-4) | workstation, repos | P1 | planned | gate for CF0.7; `carol` on the `:8088` host install |
| CF0.1 Scope map for the paths the apps call (D1) | `synaplan` | P1 | **merged** 2026-10-19 [#2571](https://github.com/metadist/synaplan/pull/2571) | starts the contract folder `backend/tests/Fixtures/cloud-apps-contract/` |
| CF0.2 `owncloud` source (D2) | `synaplan` | P1 | **merged** 2026-10-19 [#2574](https://github.com/metadist/synaplan/pull/2574) | Data fix per §3 row 26 (`BUSERID`, `BSOURCE`) |
| CF0.3 Cloud uploads that name a knowledge folder skip Incoming (D3) | `synaplan` | P1 | **merged** 2026-10-19 [#2572](https://github.com/metadist/synaplan/pull/2572) | automatic rule, no new request field (decision 2026-10-14) |
| CF0.4 `check-stale` returns `vector_state` | `synaplan` | P1 | **merged** 2026-10-19 [#2573](https://github.com/metadist/synaplan/pull/2573) |  |
| CF0.5 Docs truth (D10, D11) | `synaplan-docs`, `synaplan-nextcloud` | P1 | **in review** · Nextcloud hosting guide [synaplan-nextcloud#63](https://github.com/metadist/synaplan-nextcloud/pull/63), OpenCloud docs [synaplan-docs#32](https://github.com/metadist/synaplan-docs/pull/32) | the guide describes 1.7.0, so it merges after #59, #60 and #65 |
| CF0.6 STATUS hygiene (D14) | `synaplan` | P1 | **done** 2026-10-24 | More-Nextcloud archived; S2 recorded as merged (#34); S3 absorbed here; pairing-code fallback (NC18, NC19) named and not scheduled |
| CF0.7 First walks + compatibility check | all (walk) | P1 | planned | Nextcloud J-NC-1…3; current OpenCloud and ownCloud.online releases |
| CF0.8 Translation mode for documents (D25) | `synaplan` | P1 | **merged** 2026-10-19 [#2582](https://github.com/metadist/synaplan/pull/2582) | `summaryType: translation`; a translation the model cut off is refused |
| CF0.9 The connect page names the signed-in account (D28) | `synaplan` | P1 | **merged** 2026-10-19 [#2581](https://github.com/metadist/synaplan/pull/2581) | follow-up: the card follows the theme ([#2584](https://github.com/metadist/synaplan/pull/2584)) |
| CF1.1 ownCloud.online link mode, consent, MIME, key encryption | `synaplan-owncloud-online` | P3 | planned |  |
| CF1.2 ownCloud.online tests, CI, AGENTS.md, local stack | `synaplan-owncloud-online` | P3 | planned | after CF-S4 |
| CF1.3a Nextcloud 33–35, attributes, `ControllerAccessTest` | `synaplan-nextcloud` | P1 (M1 fast lane) | **merged** 2026-10-19 [synaplan-nextcloud#46](https://github.com/metadist/synaplan-nextcloud/pull/46) | D21; 1.6.1 was never released — it ships in 1.7.0 |
| CF1.3b Engineering baseline (CI gate, pinned Synaplan, contract test, PHPStan, vue-tsc, Vitest, Playwright, Renovate, `AGENTS.md`) | `synaplan-nextcloud` | P1 (M1) | **in review** · CI gate [#47](https://github.com/metadist/synaplan-nextcloud/pull/47), contract test [#48](https://github.com/metadist/synaplan-nextcloud/pull/48) and size and style [#50](https://github.com/metadist/synaplan-nextcloud/pull/50) merged; e2e journeys in review [#61](https://github.com/metadist/synaplan-nextcloud/pull/61) | `11` §3.1; e2e green in CI on Nextcloud 33 and 35; after #61 the owner makes **All Checks Passed** required |
| CF1.3c Keys encrypted at rest (per-user, admin, shared) | `synaplan-nextcloud` | P1 (M1) | **in review** [synaplan-nextcloud#59](https://github.com/metadist/synaplan-nextcloud/pull/59) | D4; Nextcloud's sensitive config; going back to 1.6 means entering the admin key again |
| CF1.3d Release from CI | `synaplan-nextcloud` | P1 (M1) | **in review** [synaplan-nextcloud#62](https://github.com/metadist/synaplan-nextcloud/pull/62) | owner stores two repository secrets (A6) before the first tag; the dry run is green |
| CF1.3e Honest app states (D23, D24) | `synaplan-nextcloud` | P1 (M1) | **in review** [synaplan-nextcloud#58](https://github.com/metadist/synaplan-nextcloud/pull/58) (on #57) | app release 1.7.0; CF1.4-nc translates its sentences |
| CF1.3f The AI actions do what they say (D25 app side, D26, D27) | `synaplan-nextcloud` | P1 (M1) | **in review** · Chat about this file and the deep link merged ([#49](https://github.com/metadist/synaplan-nextcloud/pull/49)), Markdown answers ([#53](https://github.com/metadist/synaplan-nextcloud/pull/53)); Translate in review ([#57](https://github.com/metadist/synaplan-nextcloud/pull/57)) | Translate needs CF0.8; an older core keeps the summary path (C1) |
| CF1.4-nc Five locales, core words — Nextcloud | `synaplan-nextcloud` | P1 | **in review** [synaplan-nextcloud#60](https://github.com/metadist/synaplan-nextcloud/pull/60) (on #58) | `de`, `de_DE`, `es`, `fr`, `tr`; a Vitest check for parity, placeholders, core and banned words |
| CF1.4-oc Five locales, core words — OpenCloud | `synaplan-opencloud` | P2 | planned |  |
| CF1.4-oco Five locales, core words — ownCloud.online | `synaplan-owncloud-online` | P3 | planned |  |
| CF1.5 OpenCloud chat, media, binary save | `synaplan-opencloud` | P2 | planned | needs CF2.2 (D18); may run during P1 (row 34) |
| CF2.1 Instance columns, capabilities both ways, delivery origin | `synaplan` | P1 | **merged** 2026-10-24 [#2694](https://github.com/metadist/synaplan/pull/2694) | Walk 2026-10-24 below |
| CF2.2 Canonical identity rows, SSO bind, attribution, bearer on chat | `synaplan` | P1 | **in review** | D8, D15, D18; adoption of provisioned rows (row 39). Controller tests cover bind, adoption and bearer-on-chat; the Keycloak block of `cf2-identity.sh` waits on the live exchange |
| CF2.3 Background key, `cloud:watch`, scope map | `synaplan` | P1 | **in review** · [#2810](https://github.com/metadist/synaplan/pull/2810) · issue [#2809](https://github.com/metadist/synaplan/issues/2809) | `files rag cloud:watch`, plaintext once; `from-message` refused for every key |
| CF2.4 Lifecycle endpoint | `synaplan` | P1 | **in review** · [#2812](https://github.com/metadist/synaplan/pull/2812) · issue [#2811](https://github.com/metadist/synaplan/issues/2811) | Stacked on #2810. API-key delete answers 409 account_in_use; a session admin is unchanged |
| CF2.5 Apps cards: status, turn off, disconnect that keeps the row | `synaplan` | P1 | planned | D9, D19; no OpenCloud card (CF2.11) |
| CF2.6 Nextcloud organisation login | `synaplan-nextcloud` | P1 | planned | only if CF-S2 says yes |
| CF2.7 OpenCloud registration, consent, bind, Connect mode | `synaplan-opencloud` | P2 | planned | Connect mode is a cut line |
| CF2.8 ownCloud.online lifecycle, identity rows, admin copy | `synaplan-owncloud-online` | P3 | planned |  |
| CF2.9 Files remember their cloud | `synaplan` | P1 | **in review** · [#2817](https://github.com/metadist/synaplan/pull/2817) · issue [#2816](https://github.com/metadist/synaplan/issues/2816) | D17; `BFILES.BSOURCEINSTANCE`; stacked on main |
| CF2.10 Nextcloud identity basics | `synaplan-nextcloud` | P1 | planned | whatever CF-S2 answers: adoption job, lifecycle calls, four-mode admin screen |
| CF2.11 OpenCloud Apps card | `synaplan` | P2 | planned |  |
| CF3.1 Copy protocol, ledger, notify key, `identity_revoked` | `synaplan` | P1 | **merged** 2026-10-20 [#2609](https://github.com/metadist/synaplan/pull/2609) | released in 5.5.3; delivery origin narrowed to the registered host or a dev host until CF2.1 |
| CF3.2 Copy-to-cloud menu, toast, Sent to | `synaplan` | P1 | **merged** 2026-10-20 [#2613](https://github.com/metadist/synaplan/pull/2613) | released in 5.5.3; lists only clouds whose app reports `deliveries` (C21) |
| CF3.3 Nextcloud inbound, From Synaplan | `synaplan-nextcloud` | P1 | **merged** 2026-10-24 [synaplan-nextcloud#67](https://github.com/metadist/synaplan-nextcloud/pull/67) |  |
| CF3.4 ownCloud.online inbound, From Synaplan | `synaplan-owncloud-online` | P3 | planned |  |
| CF3.5 OpenCloud inbound, binary save, From Synaplan | `synaplan-opencloud` | P2 | planned |  |
| CF3.6 Pickup protocol, `folder_fetch`, read-scope mirror, WebDAV list/get | `synaplan` | P1 | **merged** 2026-10-23 [#2650](https://github.com/metadist/synaplan/pull/2650) | released in 5.5.4; `BREADSCOPE` added here, since CF2.2 has not landed |
| CF3.7 "Read N files" run card, refusal sentence, read scope on Apps card | `synaplan` | P1 | **in review** · [#2666](https://github.com/metadist/synaplan/pull/2666) · issue [#2662](https://github.com/metadist/synaplan/issues/2662) | the WebDAV path walked with a real planner; the linked-cloud path waits for the reference client (E-9) |
| CF3.8 Nextcloud pickup handler, read setting | `synaplan-nextcloud` | P1 | **in review** · [synaplan-nextcloud#75](https://github.com/metadist/synaplan-nextcloud/pull/75) | Walk 2026-10-24 below |
| CF3.9 ownCloud.online pickup handler, read setting | `synaplan-owncloud-online` | P3 | planned | After CF-S4 |
| CF3.10 OpenCloud pickup handler, read setting | `synaplan-opencloud` | P2 | planned | After CF-S1, CF-S3 |
| CF4.1 Status, file detail, source events, re-upload | `synaplan` | P1 | **in review** · [#2820](https://github.com/metadist/synaplan/pull/2820) | Stacked on main after #2817 merged. `cloud_files` default off; `BSOURCEDELETEDAT`, `BKEEPSYNCED`; status, detail, source events, 7-day reaper |
| CF4.2 Glyph kit | `synaplan` | P1 | **in review** · stacked on #2820 | Bird, bird with eyes, five badges. Library pill uses them. `cloud_files.glyphs` override when the module is on. Product owner approves the shapes in the PR |
| CF4.3 Nextcloud bird, sidebar | `synaplan-nextcloud` | P1 | planned |  |
| CF4.4 ownCloud.online bird, sidebar | `synaplan-owncloud-online` | P3 | planned |  |
| CF4.5 OpenCloud sidebar, upstream request | `synaplan-opencloud` | P2 | planned |  |
| CF5.1 Watch model, ledger, `file_event` | `synaplan` | P1 | planned | Schema ask recorded; folder and file targets; paginated watch index and caps of 20 / 1 000 (row 41); `BEXPIRESAT` (D20) |
| CF5.2 Presets | `synaplan` | P1 | planned |  |
| CF5.3 Tasks → Watched folders | `synaplan` | P1 | planned |  |
| CF5.4 Nextcloud watches | `synaplan-nextcloud` | P1 | planned |  |
| CF5.5 ownCloud.online watches | `synaplan-owncloud-online` | P3 | planned |  |
| CF5.6 OpenCloud watches | `synaplan-opencloud` | P2 | planned |  |
| CF5.7 Files attached to runs, file sets, run limits | `synaplan` | P1 | planned |  |
| CF5.8 Try it on a file, then automate; unattended file-work consent | `synaplan` | P1 | planned |  |
| CF5.9-nc Dialog file sets and trial — Nextcloud | `synaplan-nextcloud` | P1 | planned |  |
| CF5.9-oc Dialog file sets and trial — OpenCloud | `synaplan-opencloud` | P2 | planned |  |
| CF5.9-oco Dialog file sets and trial — ownCloud.online | `synaplan-owncloud-online` | P3 | planned |  |
| CF5.10 Contract v1 freeze | `synaplan`, `synaplan-nextcloud` | P1 (last) | planned | row 44; P1 exit |
| M1 release: Synaplan + Nextcloud 1.7.0 | `synaplan`, `synaplan-nextcloud` | P1 | **in progress** · the app PRs are green; the version PR [synaplan-nextcloud#64](https://github.com/metadist/synaplan-nextcloud/pull/64) merges last | `11` §2, §6. Gates before the tag: (1) a Synaplan release with CF0.1–CF0.4, CF0.8, CF0.9 and the D29 fix ([#2600](https://github.com/metadist/synaplan/pull/2600)), deployed — v5.5.0 has none of them; (2) CF0.5 merged and CF0.6; (3) the CF0.7 walks green; (4) the two release secrets (A6). Then `v1.7.0-rc.1` (a draft GitHub release to test), then `v1.7.0` (decision 2026-10-20). The App Store offers 1.5.0 today |
| M2 release: Synaplan + Nextcloud 1.8.0 | `synaplan`, `synaplan-nextcloud` | P1 | planned |  |
| M3 release: Synaplan + Nextcloud 1.9.0, chart `features.cloudFiles` | `synaplan`, `synaplan-nextcloud`, `synaplan-charts` | P1 | planned |  |
| M4 release: Synaplan + Nextcloud 2.0.0, contract v1 — the Nextcloud release | `synaplan`, `synaplan-nextcloud` | P1 exit | planned | then row 46 flags |
| Break | — | after P1 | planned | row 48; ends with a dated "P2 go" |
| CF6.x Beyond the apps | various | after P3 | optional |  |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-10-13 | Track opened from the product owner's request: copy generated files into the cloud, one login story and user handling, synchronized file handling (bird, bird with eyes, automations). Master-plan §3 rows 1–26 proposed; **none ticked**. |
| 2026-10-13 | Product-owner clarification: "A pure path in a specific prompt has no meaning … pick up files or file lists from the file sharing app … fetch files and process those on Synaplan automatically, even in the code sandbox with specific tools." Added [`07_files_into_runs.md`](./07_files_into_runs.md) and §3 rows 27–32 (files not paths, what each run gets, `folder_fetch` + pickup, read scope, file work, try then automate). The `{file.path}` placeholders are withdrawn. Rows proposed, **not ticked**. |
| 2026-10-13 | Second review ("prepare for vibe coding"): added [`08_agent_briefs.md`](./08_agent_briefs.md) (rules R1–R14, done D1–D10, ask A1–A7, one brief per spike and step), [`09_functional_tests.md`](./09_functional_tests.md) (three layers per sprint, fixtures, accounts, regression set) and [`10_local_environments.md`](./10_local_environments.md) (pins, inventory, cleanup, E-1…E-8). The three open questions of the first review became §3 rows 33–35; dev-mode test hooks are row 36. Rows proposed, **not ticked**. Local cleanup of `10` §2.1 executed; `10` §2.2 waits for the owner. |
| 2026-10-13 | Copilot review of [#2467](https://github.com/metadist/synaplan/pull/2467) (six findings, review log below): §3 rows 37–41 added (notification proof, deleting a person in one cloud, provisioned identities, files remember their cloud, watch index); row 16 now says a watch may look at one file; row 11 notifications are signed. Defects D15–D17, invariants C18–C20, step CF2.9 and journey J-CF-15 added. Rows proposed, **not ticked**. |
| 2026-10-14 | Product owner: the proposed defaults of §3 rows 1–36 stand. Rows 37–41 stay proposed — they were not in that confirmation. `10` §2.2 done: Nextcloud dev volume removed (container stays down until E-2), OpenCloud 6.0.0 config volume removed, signing-key tarballs moved to `synaplan-nextcloud/.local/` with a local README. |
| 2026-10-14 | Product owner: **Nextcloud first, then OpenCloud, then ownCloud.online** — "one complete Nextcloud run first with tests and logic, so we know the Synaplan side is ready with a clean API". Recorded as §3 row 42 (agreed); rows 33–35 amended in place to the phases. The conditions that make the order safe are proposed as rows 43 (Synaplan offers a cloud only for features its app reports), 44 (contract v1 frozen at the P1 exit), 45 (reference client with `keyed` and `sso` profiles) and 46 (switch `cloud_files` on after P1) — **not ticked**. |
| 2026-10-14 | CF0.3 changed from an opt-in upload option (`intake=direct`) to an automatic rule: a `nextcloud` / `opencloud` / `owncloud` upload that names a knowledge folder other than `DEFAULT` skips Incoming. Reason: no released app sends the option, so the CF0.7 walk could not pass; the apps already send the folder the person picked. C10 unchanged in meaning; Outlook unchanged. |
| 2026-10-14 | Product owner, in a click-through of the open rows: rows 37 (signed notifications), 38 (deleting a person in one cloud), 39 (provisioned identities), 40 (files remember their cloud), 43 (apps report their features), 44 (contract v1 at the end of P1), 45 (reference client) and 46 (switch `cloud_files` on after P1) agreed as proposed; row 41 agreed with **lower caps — 20 active watches per person, 1 000 per instance** (instead of 50 / 5 000); the adjustments of rows 33–35 to the Nextcloud-first order confirmed. All 46 rows are agreed. Row 46 replaces row 22's timing; row 11's signing clause is in force. Caps updated in `04` §B.7 / §B.8, CF5.1, the spikes and `cf5-index.sh`. |
| 2026-10-19 | Product owner: reach Nextcloud releasability first and pause there; follow the code standard of `synaplan-opencloud` and the deployment approach of `synaplan-charts`. Recorded as rows 48 (break after P1) and 49 (standard), both agreed. P1 finalized in [`11`](./11_nextcloud_release.md): four milestone releases (M1–M4), the Nextcloud release at M4, then the break. CF1.3 split into CF1.3a–d; the disabled / re-enabled listener moved to CF2.10. Row 47 agreed in the same session: the P1 releases support Nextcloud 33–35; installations on 30–32 keep 1.6.0. |
| 2026-10-20 | Product owner, on the open M1 decisions: D29 is fixed in the core now ([#2600](https://github.com/metadist/synaplan/pull/2600)) and ships in the Synaplan release Nextcloud 1.7.0 waits for; D30 is fixed in the app for 1.7.0 ([synaplan-nextcloud#65](https://github.com/metadist/synaplan-nextcloud/pull/65)); `synaplan-nextcloud` `main` is protected like `synaplan-desktop` (rulesets BaseRules and RequiredWorkflows: pull requests only, no force-push, **All Checks Passed** on an up-to-date branch, owner and organisation admins may bypass); 1.7.0 goes out as a release candidate first (`v1.7.0-rc.1`); CF0.5's docs half now ([synaplan-docs#32](https://github.com/metadist/synaplan-docs/pull/32)). The owner merges the PRs and cuts the Synaplan release. |
| 2026-10-23 | CF3.7, three choices the plan did not make. (1) A folder with more than 50 matching files, or more than 200 MB, is read up to the limit and the card names what was left out ("past the limit for one run"), instead of refusing the whole read as `07` §10 "Too many files" says: the listing protocol already answers `over_limit` per file, and a partial read with its sentence is honest (U8). (2) **Open in …** on a folder card is built by Synaplan from the instance's registered host for Nextcloud and ownCloud (`/index.php/apps/files/?dir=`), because a refused read never reaches the app; OpenCloud and WebDAV folders get no link until their apps name one. (3) A pickup the run stopped waiting for is failed at once and its ticket deleted, and the existing `app:cloud-deliveries:sweep` also fails pickups still open after 20 minutes (R6). Both endings, and the app's result, only close a pickup that is still open, so whichever lands first is what the run and the app both see. |

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

**2026-10-14 (third review — Nextcloud first).** The product owner asked to
build everything for the core and Nextcloud first, then OpenCloud, then
ownCloud.online, and to look for weak definitions once more. Two read-only
audits ran in parallel — one as the coding agent of the core-and-Nextcloud
phase, checking every path against the four repositories, one for
consistency across the files — and their main claims were re-checked in the
code before anything changed.

Verdict on the order: it is right, under four conditions now in the plan.
(1) The core must also be proven for the paths only OpenCloud and
ownCloud.online use — organisation-login bind, the background key, bearer
tokens on chat, long CS3-style ids, quoted etags — so a reference client
plays both kinds of app from P1 on (row 45, E-9). (2) All five spikes answer
before the core API is frozen. (3) The API is frozen as contract v1 at the P1
exit, vendored into each app with a checksum test (row 44, CF5.10, C22).
(4) Synaplan offers a cloud only for features its app reports, or Copy to
cloud would list clouds that cannot receive yet (row 43, C21).

New defects found in the code: **D18** OIDC bearer tokens are refused on
`/v1/*` (only API keys pass the `openai_compat` firewall), so chat over
organisation login could not work → CF2.2. **D19** disconnect deletes the
identity row, so nothing could be resumed → CF2.5. **D20** the ephemeral
reaper deletes hidden files after 24 hours, the plan keeps working copies 7
days → `BFILES.BEXPIRESAT`, CF5.1.

Weak or unclear definitions fixed:

- **Dev networking could not work as written.** A delivery URL "on the
  instance's registered host" is unreachable inside Docker (`localhost:8081`
  is the backend's own loopback; `http://nextcloud` failed the host rule).
  `03` §2 now defines two origins per instance, a delivery-origin rule with
  one shared dev host list, an unsigned reachability probe, approval by
  hostname instead of resolved address, and paths instead of absolute URLs
  for everything Synaplan hands an app. `10` §0 lists the exact dev URLs.
- **Wire formats.** The notification (`id` string, `ts` Unix seconds, `kid`
  fingerprint, key encoding, signed bytes, shared test vectors for PHP and
  Go, answer codes per type), copy claim / result, send response, status
  call, source events with re-upload, watch upload, reconcile, watch index,
  pickup claim / listing / files / result — all with fields, types and error
  codes.
- **Follow the source** could not be built: Synaplan had no new bytes and no
  list of who keeps a file in sync. Now `BFILES.BKEEPSYNCED`, the upload
  field `keep_synced`, and a `reupload` answer to source events.
- **Scope map gaps:** trials (`cloud:watch`), `from-message` (session only),
  `identities/adopt` (any platform scope), the memory check (`chat` or
  `memories`, because provisioned keys never carry `memories`).
- **States and reasons** unified on `04` §B.2, with a terminal `stopped`
  state; Stop keeps the row and disables or unbinds the task instead of
  deleting it.
- **Who is `azp`:** the client that appears in the tokens the app sends — for
  OpenCloud its token-exchange client — recorded by the L2 script before it
  asserts.
- **Test accounts:** Ada (linked) and Ada (organisation) are different
  Synaplan accounts; provisioning tests use Carol, because an organisation
  login is refused for an email another account already has.
- **Core acceptance no longer depends on later app briefs:** core briefs are
  accepted against the reference client; journeys are walked in the app
  brief that follows. App work starts on merge; app releases wait for a
  Synaplan release.
- **Smaller:** CF0.2 SQL used the wrong columns (`BOWNERID`, `BCLIENT` →
  `BUSERID`, `BSOURCE`); a private address in an example (`02` §6.2) replaced
  by `192.168.10.20`; definition-of-done labels renamed `DoD-n` (they
  collided with defects); "token exchange" and "cron" removed from primary
  copy; the read setting "Only folders I watch" → "Only what I watch" (watched
  files count too); Nextcloud's matrix floor is 30, not 31; eight dev folders,
  not six; the ownCloud.online tag pinned to `v11.0.10` (tags up to `v11.0.13`
  exist — CF-S4 asks the hoster); the OpenCloud health check is a `401` from
  `/api/synaplan/me`; vague UI paths resolved (`PlatformInstancesTab.vue`,
  `LinkedPlatformsConfiguration.vue`, `multitask/*Card.vue`); the Nextcloud
  brief CF2.6 split into identity basics (CF2.10, always) and organisation
  login (CF2.6, only after CF-S2); the OpenCloud Apps card moved to P2
  (CF2.11).

A second read-only audit of the revised files found 19 problems the revision
itself had introduced, all fixed before the commit. The main ones: the
reference client could not register before CF2.1 added its host to the dev
list, so E-9 now ships a server skeleton and registration is CF2.1's
acceptance; Carol had one email in two clouds, which provisioning refuses
(ownCloud.online's Carol is `carol.oco@example.org`); no brief added the core
capabilities `source_events`, `file_sets` and `trials`; rejecting unknown
`app_capabilities` with `422` would break a newer app on an older core (they
are ignored now); CF5.10's acceptance included its own merge; P3 needs
Keycloak for the `sso` regression; the reference client needed the three
test switches; `paused_count` and the resume answer had no definition.

Still open for the product owner: rows 37–41 and 43–46. CF0.1–CF0.4 and
E-1…E-6 need none of them; E-9 needs row 45; CF2.1 needs rows 6 and 43, CF2.2
row 39, CF2.4 row 38, CF2.9 and CF4.1 row 40, CF3.1 row 37, CF5.1 row 41,
CF5.10 row 44. No product code was touched.

**2026-10-14 (Copilot review re-checked).** The only Copilot review on
[#2467](https://github.com/metadist/synaplan/pull/2467) covers the first
commit; its six threads had no reply, so the PR still listed them as open.
Each finding was re-checked against the current plan and the code it relies
on:

| Finding | Verdict | Where the plan answers it | Decision |
| ------- | ------- | ------------------------- | -------- |
| Account deletion removes identities other clouds still use (high) | Valid; fixed | `02` §7 "Deleting a person in one cloud", C18, CF2.4; `409 account_in_use` for the generic admin delete called with an API key (today both apps call it — `UserDeletedListener` → `deleteRemoteAccount()`) | 38, proposed |
| Unauthenticated notifications amplify outbound calls (high) | Valid; fixed | `03` §2 signed notifications with checks before any call back, per-address limit, no key refresh on unknown `kid`, shared test vectors; failed-claim limit (`429`); C20 | 37, proposed |
| File lookups collide across instances (high) | Valid; fixed, and extended now | `BFILES.BSOURCEINSTANCE` from the credential, C19, CF2.9. Gap found in this re-check: pickup reuse and whole-folder snapshots (added after the review) reused "held" files without naming the cloud — now matched by (instance, remote id, version) in `07` §2 / §4, CF3.6, CF5.7, C19 and the reuse checks in `cf3-pickup.sh` / `cf5-fileset.sh` | 40, proposed |
| Provisioning omits the canonical identity tuple (medium) | Valid — for both apps, not only ownCloud.online (both send `external_id = '<instanceId>:<uid>'`); fixed | `02` §3.1, D15, CF2.2, CF2.10, CF2.8 | 39, proposed |
| Unbounded active-watch cache (medium) | Valid; fixed | `04` §B.7–§B.8 paginated, incremental watch index with caps; spikes CF-S3/S4/S5 measure it; `cf5-index.sh` | 41, proposed |
| No single-file watch journey (medium) | Valid; fixed | J-CF-15 gates CF5 (`05` §1, `06` CF5, `09` §6) | row 16, agreed |

Each thread got a reply that names the fix. The fixes for the first five
findings stand in the plan but take effect only when the product owner
agrees rows 37–41.

**2026-10-19 (fourth review — ready for vibe coding?).** The product owner
asked whether the plan is professional and ready for coding agents, and to
finalize it for the first Nextcloud release with a break there, local
Nextcloud and OpenCloud stacks for the tests, and the standard of
`synaplan-opencloud` and `synaplan-charts`.

Verdict. The research is professional: every claim names its code, the
decisions and invariants are explicit, and every step has tests. It was not
ready for vibe coding, for six reasons:

1. **Too much to hold.** 13 files, about 5 000 lines; a P1 brief needed four
   to six files, each mixing P2 and P3 content the agent had to filter out.
2. **No release before CF5.** P1 had about 35 PRs and its only named release
   came after the watches — months of unreleased work.
3. **The repo P1 lands in had none of the assumed standard.**
   `synaplan-nextcloud` has no CI, no `AGENTS.md`, no static analysis, no
   type check, no JS tests, no E2E, de/en only, plaintext per-user keys; and
   CF1.3 bundled that baseline with a product change and a listener nothing
   used yet.
4. **No local test stack.** No Keycloak, Nextcloud or OpenCloud container
   exists (`10` §1.4).
5. **Pins drifted.** Nextcloud 35 shipped on 2026-09-15 and the app stops at
   34 (D21); the matrix floor, 30, gets no maintenance releases.
6. **No end.** P1 rolled into P2 without a stop.

What changed: [`11`](./11_nextcloud_release.md) — one entry point for P1:
four milestone releases with fast lanes for D1 and D21, the engineering
standard mapped rule by rule from `synaplan-opencloud` and `synaplan-charts`,
the test stacks with today's state, seven regression guards, a release
checklist and the break. Rows 47–49; D21; CF1.3 split into CF1.3a–d; the
Nextcloud matrix moved to 33 and 35 in `06`, `08`, `09` and `10`; E-1 closed;
`10` §1.4.

Verified on 2026-10-19: D1 (`ApiKeyScope::requiredScopesForPath()` has no
branch for `/api/v1/summary`, `/api/v1/media`, `/api/v1/config/memory-service`;
`/v1/*` needs `desktop:messages`), D2 (`File::SOURCES` has no `owncloud`), D3
(`FileUploadService` line 403), D18 (`openai_compat` runs only
`ApiKeyAuthenticator`), D19 (`PlatformLinkExchangeService::disconnect()`
removes the identity), D20 (`DEFAULT_TTL_HOURS = 24`); the Nextcloud app
still calls the four denied paths; Nextcloud, OpenCloud and `user_oidc`
versions upstream; the CI, Renovate and client-generation set-up of
`synaplan-opencloud`; the feature-pin map and the k3d E2E of
`synaplan-charts`; the Synaplan test stack (`docker-compose.test.yml`,
`--profile oidc`) the Nextcloud CI reuses.

Not changed: decisions 1–46, the protocols, the P2 and P3 content. When P2
starts it gets a file like `11`.

**2026-10-19 (environments — E-1 to E-4, E-6 first part).** Built and checked
on the maintainer workstation.

```text
env-check.sh --cloud nextcloud,opencloud --oidc → 45 passed, 1 warning, 0 failed, 1 skipped
Synaplan   v5.5.0-2-g297d0cc67
Keycloak   quay.io/keycloak/keycloak:26.8
Nextcloud  35.0.2, app 1.6.0 (also checked: 33.0.10, 40 passed, no warning)
user_oidc  8.11.0
OpenCloud  8.1.0, app v1.0.0-9-g5cc1351
```

- The warning is Nextcloud 35 above the app's `max-version` 34; the dev stack
  enables the app with `--force` until CF1.3a.
- Organisation login, Nextcloud: an auth-code round trip with Keycloak's
  `ada` ends signed in as the local `ada` on 35 and on 33.
- Organisation login, OpenCloud: its Playwright suite passes 10 of 10
  against the local Synaplan (sign-in, connection test, summarize and translate
  for text and PDF, add to knowledge) with one worker.
- **Verified `azp`** (`02` §3): Ada's token exchanged through
  `synaplan-opencloud` for audience `synaplan-app` carries
  `azp = synaplan-opencloud`, and Synaplan accepts it (`/api/v1/auth/me` → 200,
  `ada@example.org`). The OpenCloud instance registers that value in CF2.7.

Findings, fixed in the stacks and written into `10`:

1. The plan's draft named the Nextcloud database `db` on the shared Synaplan
   network, where `db` is Synaplan's own database. It is `nextcloud-db` on a
   private network; Synaplan's `db` still resolves to `synaplan-db`.
2. `user_oidc` refuses plain HTTP; the dev stack sets `allow_insecure_http`.
   Folders and shares need HTTP, so they moved from the install hook to
   `docker/dev-seed.sh`, which `make dev-up` runs on every start.
3. Synaplan accepted no Keycloak token: `backend/.env` had no OIDC values. It
   now carries the values of the test stack (`10` §3.1); before, the three
   OIDC lines were empty and nothing else differed.
4. This workstation's `docker` calls Docker Desktop's Windows `docker.exe`:
   environment variables reach Compose only through `WSLENV`, so
   `NC_IMAGE=…` was silently ignored until the Makefile exported it there.
5. OpenCloud 8.1.0 crashes under parallel Playwright workers (`fatal error:
   concurrent map read and map write`), and its compose service has no restart
   policy. For `synaplan-opencloud`, not changed here: `restart:
   unless-stopped` on `opencloud`, and an upstream report.
6. The `keycloak_setup` script as a whole cannot run twice (it creates the
   realm unconditionally); the new "Cloud apps" block can, and was checked
   twice.

**2026-10-19 (survey cross-check).** Three read-only surveys of
`synaplan-opencloud`, `synaplan-charts` and `synaplan-nextcloud` were checked
against `11` and re-verified in the code. Changed: `11` §3.2 — CI deploys only
the `standalone` environment into k3d, and the openDesk Keycloak piece is a
fragment operators add to openDesk's environment (not rendered by `make
check`); new defect **D22** (chart installs run `doctrine:schema:update --force`
before the migrations, so a fresh chart install marks every migration as
applied without running it) with its consequence for P1 in `11` §3.2 item 4;
D4 widened to Nextcloud's admin and shared keys, and CF1.3c now uses
Nextcloud's sensitive `IUserConfig` / `IAppConfig` values, which Nextcloud 33
and 35 encrypt, instead of an own `enc:` scheme. Confirmed unchanged: the gate
job, the Synaplan pins and Renovate managers, the client generation from the
pinned image, the three HTTP helpers the contract test covers, the controller
attributes. `ci-local` is the house convention, not `synaplan-opencloud`'s
(it relies on CI alone); `11` §3.1 says so.

**2026-10-19 (M1 implementation).** CF0.1–CF0.4 and CF1.3a are built and in
review, each on its own branch with its own PR; CF0.1–CF0.4 were gated together
on one integration branch (`make ci-local`, `make test-e2e` — only the
documented local-only failures). Evidence per step is in the PR descriptions:
CF0.1 through the real Nextcloud 35 app (Connect, Summarize, Disconnect);
CF0.3 by real uploads; CF0.2's data fix by seeded rows, up and down; CF0.2 walked
in the browser in light and dark.

Findings, written into the plan:

1. Nextcloud 33+ refuses a password login whose `Origin` host is not trusted,
   and the first-run wizard covers the page for every new user. The dev stack
   trusts `localhost:8081` and disables the wizard (`10` §3.2, #45).
2. Two defects of the released app 1.6.0, seen in the CF0.1 walk: **D23** (the
   chat launcher on the Nextcloud login page) and **D24** (Summarize without a
   connection answers HTTP 500 with the raw Guzzle error). New brief
   **CF1.3e** in M1, after CF1.3b, before CF1.4-nc (`11` §8).
3. The core's size ledgers froze the files this plan touches most. CF0.4
   moved the check-stale classification into `SourceStaleCheck`, CF0.2 the
   source enum into `services/fileSources.ts`; the rule is in `11` §3.3.
4. After a WSL restart, bind mounts came up empty and `/tmp` was gone; the
   backup of the old `backend/.env` named in the environments entry above was
   lost with it (its OIDC lines were empty). `10` §6.

**2026-10-19 (CF1.3b, parts 1 and 2).** The Nextcloud app has the CI gate of
`synaplan-opencloud` (#47): PHP against the Nextcloud 33 and 35 APIs, frontend,
package, **All Checks Passed**. Adding the checks found a visible bug, fixed
in the same PR: seventeen `NcButton type="primary"` rendered every primary
action as secondary under `@nextcloud/vue` 9 (`variant` sets the look now).
PHPStan runs at level 5 without a baseline. The contract test (#48) checks every
call the app makes against the pinned Synaplan's spec and route table; it
proved it catches a renamed field, a renamed route and an unknown enum value.
Its enum message also shows D2 from the app's side: the pinned spec has no
`owncloud` source until #2574 ships. CF0.3 gained the overwrite case from
Copilot's review (#2572): an update into a knowledge folder leaves Incoming too.

**2026-10-19 (AI walk with real models).** The Nextcloud app (link mode as
`ada`, connected to `demo@synaplan.com`; provision mode as `carol`) against
Synaplan `main` with CF0.2–CF0.4, Nextcloud 33. Models: Groq `gpt-oss-120b`
for summaries (the demo account's own choice; the system default is
Anthropic), Cerebras `qwen-3.8-27b` and `gpt-oss-120b` in the streaming chat,
local Ollama `bge-m3` for embeddings, Cohere for reranking — each confirmed in
`BUSELOG`. Images were not generated (OpenAI only).

Works end to end: register the instance; Connect; Summarize; the streaming
chat with a chosen model; knowledge add into a named folder (not Incoming,
CF0.3), RAG answer from that folder, status, remove; memories in the chat;
provisioning on consent with a `chat files rag` key and Summarize through it
(CF0.1); Disconnect revokes the key. Fixed during the walk: a vectorized file
reported `vector_state: none` until the Library ran its fix-on-read, so
`check-stale` said "not searchable" right after an upload; #2573 now sets the
state when the vectors are stored (`File::markVectorized()`). Recorded: D25
(Translate returns an essay, not a translation), D26 (Chat about this file
answers with a summary), D27 (deep link to the internal API host), D28 (the
connect page does not name the signed-in Synaplan account); D24 widened to the
streaming chat. Two dev-loop constraints went into `10` §6.

**2026-10-19 (M1, the Nextcloud PRs).** With CF0.1–CF0.4, CF0.8 and CF0.9 on
`main`, the remaining M1 steps of the Nextcloud app are built, each on its own
branch with its own PR, CI green and every Copilot finding answered:

- CF1.3f Translate ([synaplan-nextcloud#57](https://github.com/metadist/synaplan-nextcloud/pull/57))
- CF1.3e honest app states ([#58](https://github.com/metadist/synaplan-nextcloud/pull/58))
- CF1.3c encrypted keys ([#59](https://github.com/metadist/synaplan-nextcloud/pull/59))
- CF1.4-nc five locales ([#60](https://github.com/metadist/synaplan-nextcloud/pull/60))
- CF1.3b part 3, the e2e journeys ([#61](https://github.com/metadist/synaplan-nextcloud/pull/61))
- CF1.3d release from CI ([#62](https://github.com/metadist/synaplan-nextcloud/pull/62))
- CF0.5, the hosting guide ([#63](https://github.com/metadist/synaplan-nextcloud/pull/63))
- the version PR for 1.7.0 ([#64](https://github.com/metadist/synaplan-nextcloud/pull/64))

Merge order: #57, #58, #60, #61; #59 and #62 in any order; #63 after #59
and #60, because it describes them; #64 last. Merging is not releasing: the
tag waits for the M1 gates in the steps table, first of all a Synaplan
release with CF0.1 (v5.5.0 predates it).

The e2e journeys run in CI against the pinned Synaplan image on Nextcloud 33
and 35. The CI job reads the image's source commit and checks out that
commit's test stack files, so the image digest stays the only pin. The ten
journeys take about a minute:

- the login page loads no app code
- a new person connects from the first file action
- Summarize, Translate, Chat about this file
- Make searchable by AI and back
- an image saved to `Synaplan/Images`
- a disconnect on either side
- accounts created by the administrator

Findings:

1. **The Synaplan test stack has no Redis**, so Connect cannot work there
   (`10` §6). The e2e adds Redis with an extra compose file.
2. **D29**: a disconnect in Nextcloud leaves the link in Synaplan's linked
   platforms. The app can only revoke the key. The journey checks the key,
   and J-NC-3 waits for the core.
3. **D30**: Deactivate in the Nextcloud admin panel leaves a provisioned
   person's key valid in Synaplan.
4. **The App Store offers 1.5.0** (Nextcloud 30–34). 1.6.0 and 1.6.1 were
   never tagged or uploaded. The decision of 2026-10-19 and row 47 say
   installations on Nextcloud 30–32 "keep 1.6.0": they keep 1.5.0. 1.7.0 is
   the first App Store release for Nextcloud 35.
5. **`info.xml` did not validate** against the App Store schema
   (`<discussion>` in the wrong place). 1.5.0 got through anyway. Fixed in
   #62 and #64, and the release workflow now checks the schema.
6. **Found by the journeys and the reviews, fixed in #58:**
   - the failure note linked to a settings page that does not exist
   - a 404 or a connection error whose message contained "401" deleted a
     linked person's key
   - the chat completions error shape lost Synaplan's code and sentence
   - a quick `/pic` was refused before the chat knew its capabilities

   Each fix has a test that fails without it.
7. **Release secrets (A6)**: before the first tag the owner stores
   `APP_STORE_SIGNING_KEY` and `APP_STORE_TOKEN`. The local signing key
   matches the certificate the App Store holds for the app.
8. **The integrated release candidate** (all eight branches merged locally in
   the merge order above; no conflict):
   - `make ci-local` passes: 200 PHPUnit, 7 contract, 326 Vitest tests.
   - The ten journeys pass on Nextcloud 33 after an in-place `occ upgrade`
     from 1.6.1.
9. **Upgrade from the App Store version** (`11` §5 item 6, adapted): 1.5.0
   has no link mode and stops at Nextcloud 34. So the check ran on Nextcloud
   33:
   - 1.5.0 from its release tarball, accounts created by the administrator,
     carol activated, Summarize.
   - The 1.7.0 package and `occ upgrade` ("Updated <synaplan_integration> to
     1.7.0").
   - Carol's next Summarize worked without activating again.
   - Her plain-text key was stored encrypted after that first use, and the
     admin key after the admin settings first read it.
   - Caveat: right after files are swapped under a running server, PHP's
     opcache may serve the old code for up to a minute. Keys stay readable
     meanwhile and are encrypted on the next use.

   A link made with 1.6.x surviving the upgrade was checked on the dev stack
   when CF1.3c was built.

**2026-10-23 (E-9, the reference client).** `cloud-fixture` started next to
the dev stack. `env-check.sh` group fixture: PASS (an unsigned notification
from `backend` gets `401`), and the five notify vectors pass. Registered as
an `owncloud` instance (pending, then approved by `admin@synaplan.com`),
announced `http://cloud-fixture:8099/inbound` with `deliveries` and
`pickups`, and read the notify key. In the browser as `demo@synaplan.com`:
"Connect ownCloud at localhost:8099 as ada?", then **Connect ownCloud**,
then "ada is linked to demo@synaplan.com." ada's read scope was set to
`Invoices` and three invoices were put there.

- Copy to cloud from Library → Generated: "Copied to
  /Synaplan/invoice_summary.csv on Nextcloud. The file is still here." The
  fixture wrote the file and Synaplan recorded it as delivered. A second copy,
  after the fixture learned the kind folders, landed in
  `Synaplan/Documents/`.
- Folder read, prompt "Please take the invoice files in my ownCloud folder
  Invoices, …": the pickup was claimed and listed, but nothing was uploaded
  ("No files in owncloud › Invoices matched. Nothing was read."). Cause: the
  core answered the listing with numeric file ids as JSON numbers
  (`array_keys()` turns `"1027191588"` into an integer). Fixed in the same PR.
  After the fix: "Read 3 files from owncloud › Invoices.", all three sent.
  The next step could not read their text (#2665, fixed in #2668).
- On `main` a refused or empty folder read still falls back to a chat answer
  that invents a table (fixed in #2666; see also #2667).
- Found, not fixed here: the copy menu and toast call an ownCloud link
  "Nextcloud", and the Sent to line says "owncloud"
  ([#2671](https://github.com/metadist/synaplan/issues/2671)).

**2026-10-23 (#2665, files read from a folder reach the next step).** Account
`demo@synaplan.com` with the WebDAV connection "nextcloud folder (ada)" to the
dev Nextcloud, folder `Invoices` with three `.txt` invoices. Prompt: "Please
take the invoice files in my Nextcloud folder Invoices, read every one of
them, and then give me one table with the vendor, the invoice date and the
total amount of each invoice, sorted by date. …" Planner Claude Sonnet 5
(switched from `gpt-oss-120b` for the walk and back), answers Groq
`gpt-oss-120b`, each confirmed in `BUSELOG`.

- Before (chat 2919): the folder was read, `extract_text` failed with "no
  extractable text found in attachments", the document step was skipped
  ("dependency 'n2' did not complete"), and the answer only named the files.
- Chat 3039: the plan wired only `$n1.file` into file analysis, so the
  table had one row and no word about the other two. A step after a folder
  read now gets every file of it.
- Chat 3042: the document step got all three files but not their text and
  wrote a CSV with only the header row. Document generation now attaches
  them with their text, like uploads.
- Chat 3043: folder read, then the document. `invoices.xlsx` holds exactly
  the three rows, sorted by date (Nordwind GmbH 2026-10-02 1,240.00 EUR;
  Ostsee Logistik 2026-10-09 386.50 EUR; Nordwind GmbH 2026-10-17
  2,015.75 EUR), and the run looks the same after a reload.
- Chat 3035: folder read, then file work. File work cannot run on this
  machine: the local Compute sidecar answers `docker_unavailable`. The run
  did receive the three files (927 bytes in: the script and three 76-byte
  files). Its answer said only "Read 3 files …"; it now adds "I could not
  finish the answer. File work could not finish. Nothing new was saved."
  (unit test on the same plan, not re-walked).

Found, not fixed here: in five of nine runs the planner sent a single chat
step and the chat model claimed to read the folder, twice with an invented
table "exactly as they appear in the invoice files" —
[#2667](https://github.com/metadist/synaplan/issues/2667).

**2026-10-24 (CF3.8, Nextcloud pickup handler and read setting).** Built in
[synaplan-nextcloud#75](https://github.com/metadist/synaplan-nextcloud/pull/75)
on the merged CF3.6 / CF3.7. Ada (Nextcloud, linked to `demo@synaplan.com`)
chose *Also folders I allow* in her personal settings and named `Invoices`;
the setting and Synaplan's copy of it both read
`{"mode":"allowed","folders":[{"id":"","path":"Invoices"}]}`. An admin save
announced `["deliveries","pickups","read_scope"]` for the instance. Planner
and answers Claude Sonnet 5 for the walk (the chat model is back to
`gpt-oss-120b` after).

- "Please take the invoice files from the linked cloud nextcloud at
  localhost:8081, folder Invoices, … Do not use a WebDAV folder for this." —
  the card says "Read 3 files from Nextcloud › Invoices" with **Open in
  Nextcloud**; Synaplan's pickup 6 is `done`, 3 listed, 3 sent. The follow-up
  file-work step failed on this machine (`docker_unavailable`, as in the
  2026-10-23 walk), which is the local Compute sidecar, not the read.
- Folder HR, same wording: "Synaplan may not read HR in your Nextcloud.
  Nothing was read." plus "Open the folder in Nextcloud and choose **Let
  Synaplan read this folder**." No pickup was opened; the refusal used the
  reported setting.
- That menu entry exists on folders: choosing it on HR added
  `{"id":"110","path":"HR"}` on both sides; Ada removed it again in her
  settings, so her setting ends as `Invoices` only.

Found and fixed in the same PR: the settings folder picker had no Choose
button and could never finish, and the preferences save read the request
body only when `copy_folder` was missing — the framework hands that one
over as a parameter, so the read setting silently never saved.

**2026-10-24 (CF2.1, instance columns, capabilities both ways, delivery
origin).** In review. `BPLATFORMINSTANCES` gains `BREACHABLE`,
`BOIDCCLIENTID`, `BAPPVERSION`, `BAPPROVEDHOSTS` and `BSERVERID` (raw
`ADD COLUMN IF NOT EXISTS`); `PATCH instances/self` also takes
`oidc_client_id`, `app_version` and `server_id`, filters reported
capabilities to the names of `02` §8.1, and probes the saved delivery
address (one unsigned ping, any HTTP answer counts) into `BREACHABLE`.
`GET instances/self` returns the stored `app_capabilities` and
`reachable`. The delivery-origin rule gains the approved-hostnames rule
(private addresses allowed, loopback and link-local never). The admin
Platform instances tab shows the app version and "Synaplan can reach this
cloud", and approves a delivery hostname from the row.

Walk (dev stacks, 2026-10-24): a save in the Nextcloud admin settings
re-announced `http://nextcloud/…/api/v1/inbound`; the probe stored
`BREACHABLE = 1` for the live Nextcloud instance. As
`admin@synaplan.com` under Admin → Users → Platform instances, that row
reads "Synaplan can reach this cloud."; **Approve host** asked
"nextcloud is on a private network (172.19.0.19). Synaplan will call it
to deliver copies and requests. Approve only clouds you run.", and
confirming stored `BAPPROVEDHOSTS = ["nextcloud"]` and showed the chip.
Removing it from the chip stored `NULL` again.

Local gate: lint, PHPStan, deptrac, frontend lint, `vue-tsc` and the
frontend tests green. The backend suite keeps five failures that also
occur without this change (three `LongRouteListTest` entrypoint checks,
one search, one research-budget test — none of their files differ from
`main`); the migration was applied to the local test database first, as
CI does on a fresh one.
