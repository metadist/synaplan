# Status — Cloud file sharing

Plan of record: [`00_master_plan.md`](./00_master_plan.md). Sprints and
spikes: [`06_sprints.md`](./06_sprints.md). A walk is a dated entry here
(date, account, what was clicked, what was found, PR if something was fixed),
not a chat message.

## Steps

| Step | Repo | State | Notes |
| ---- | ---- | ----- | ----- |
| CF-S1 OpenCloud web extension points | `synaplan-opencloud` | planned | |
| CF-S2 Nextcloud organisation login (`user_oidc`) | `synaplan-nextcloud` | planned | |
| CF-S3 OpenCloud unattended access | `synaplan-opencloud` | planned | |
| CF-S4 ownCloud 11 Files APIs | `synaplan-owncloud-online` | planned | Needs a test tenant on 11.0.10 from the hoster |
| CF-S5 Nextcloud Files APIs at scale | `synaplan-nextcloud` | planned | |
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
| CF2.2 SSO bind and attribution | `synaplan` | planned | |
| CF2.3 Background key, `cloud:watch` | `synaplan` | planned | |
| CF2.4 Lifecycle endpoint | `synaplan` | planned | |
| CF2.5 Apps cards for three clouds | `synaplan` | planned | |
| CF2.6 Nextcloud organisation login | `synaplan-nextcloud` | planned | After CF-S2 |
| CF2.7 OpenCloud registration, consent, bind, Connect mode | `synaplan-opencloud` | planned | Connect mode is a cut line |
| CF2.8 ownCloud.online lifecycle, admin copy | `synaplan-owncloud-online` | planned | |
| CF3.1 Copy protocol and ledger | `synaplan` | planned | Schema ask recorded |
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
| CF5.1 Watch model, ledger, `file_event` | `synaplan` | planned | Schema ask recorded |
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

Open questions for the product owner, besides §3:

1. Is ownCloud.online an active hoster target this quarter? It sets the
   priority of CF1.1 / CF1.2 and whether BW-Tech can give us a test tenant.
2. OpenCloud chat parity (CF1.5): before the three goals, or after CF5?
3. Which comes first if capacity is short: Nextcloud organisation login
   (CF2.6) or OpenCloud Connect mode (CF2.7)?
