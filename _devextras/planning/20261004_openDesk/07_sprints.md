# 07 — Steps

Order is binding where an arrow says so. Each step lists files, tests and an
exit. UI steps carry the **User-flow** block and the five exit bullets of
the UX contract §6. Classes follow `.github/mobile-impact-policy.json` for
changes in the `synaplan` repo.

```text
MN-0 ─▶ MN-1 ─▶ MN-2 ─▶ MN-3 ─┬─▶ MN-5 ─▶ MN-6 ─▶ MN-7 ─▶ MN-9 ─▶ MN-12  (v1.0)
                              └─▶ MN-4 ───────┘
        MN-8 (engine) runs beside MN-3…MN-7; its exit gates MN-9.
        MN-10 (v1.1) and MN-11 (v1.2) after MN-9.
```

---

## MN-0 — Spike and decisions

**Goal:** close every unknown before code. The bridge part is done
(2026-10-04, [01 §3](./01_findings.md#3-spike-2026-10-04-bridge-transcription-on-the-dev-cluster)).

- [x] Bridge connects to a custom WebSocket with per-room `urlParams` and a
      header; protocol recorded; client start/stop via metadata; results
      reach the browser.
- [ ] Captions **shown**: find the `config.transcription` keys
      (`useAppLanguage`, `preferredLanguage`, `autoCaptionOnTranscribe`) and
      the subtitle toggle action that make a German result visible to a
      German viewer; screenshot.
- [ ] Jitsi's own "start transcription" control: what appears with
      `transcription.enabled`; how to hide it (config key or CSS).
- [ ] System chat line from a Prosody module (`json-message`
      `system_chat_message`) renders in web and in the Jitsi mobile app.
- [ ] Silent Keycloak sign-in from `meet.<domain>` with a static redirect
      page on the Jitsi origin: Chromium, Firefox, Safari; inside an
      Element-embedded meeting.
- [ ] Button placement against the toolbox/filmstrip/openDesk bar at
      1280 / 768 / 320 px; toolbox visibility signal to follow.
- [ ] The openDesk values hook that **appends** to `custom-config.js` (or the
      fallback order in [04 §7](./04_jitsi_and_opendesk.md#7-including-the-loader)).
- [ ] Prosody HTTP routes from a module loaded on the main VirtualHost
      answer on the in-cluster service address.
- [ ] `AiFacade::transcribe()` exact signature and options (`prompt`,
      `verbose_json`, owner for metering).
- [x] Engine benchmark round 0, GPU: stock vs German large-v3-turbo on
      the GPU host with `whisper-server` (2026-10-04, `STATUS.md`).
- [ ] Engine benchmark round 0, CPU: whisper.cpp `small` and
      `large-v3-turbo` on the dev cluster CPU, same set.
- [ ] README §4 decisions ticked by the product owner.

**Exit:** each box has a dated line in `STATUS.md` (finding + evidence).

## MN-1 — Core prerequisites (`synaplan`)

| Id | Change | Files | Tests | Class |
|----|--------|-------|-------|-------|
| `MN-1a` | Public plugin prefix: `- { path: ^/api/v1/plugins/[^/]+/public/, roles: PUBLIC_ACCESS }` with a comment that each controller authenticates itself. | `backend/config/packages/security.yaml` | Functional test: a route under the prefix is reachable without auth; a sibling `/api/v1/plugins/x/private` is not. | backend-only |
| `MN-1b` | File source `meeting`: add to `File::SOURCES`; label **From a meeting** where Files shows a source (five locales); filter facet if sources are faceted. | `Entity/File.php`, Files source label component, `i18n/locales/*/files.json` | Unit: source accepted; Vitest: label renders; parity test. | backend-only + ota-candidate |
| `MN-1c` | Publish `ghcr.io/metadist/synaplan-transcriber` (multi-arch) from CI on `main` and tags, same tag as Synaplan. | `.github/workflows/ci.yml` | CI job green; image pullable. | backend-only |
| `MN-1d` (nice) | `PluginView` context gains `locale` and `theme`. | `frontend/src/views/PluginView.vue`, `plugins/README.md` | Vitest. | ota-candidate |

`MN-1b` **User-flow:** J-MN-2 (file found in Files with its origin).
Exit bullets: (1) J-MN-2's "find in Files" part walked with a hand-made
`meeting` file; (2) the label is visible in the Files row and the file
detail; (3) label copy in five locales; (4) n/a empty state, flag-off n/a
(always available); (5) dark, V2, 320 px checked.

**Exit:** merged on `main`, released in a Synaplan tag the plugin's
`minSynaplanVersion` points at; full gate (`make ci-local && make test-e2e`).

## MN-2 — Plugin skeleton (`synaplan-meetingnotes`)

- Repo with the layout of [03 §1](./03_plugin_meeting_notes.md#1-repository-metadistsynaplan-meetingnotes),
  licence decided, CI (lint, PHPStan, PHPUnit in a pinned Synaplan checkout,
  vitest, luacheck, busted).
- `manifest.json`, `MeetingNotesSettings`, `AdminController` (`status`,
  `settings`, `connection`), `StatusChecks` with all four lines (Jitsi /
  transcriber may be "not configured" yet), `ConnectionSnippets`, `Copy`.
- `frontend/index.js`: admin page (status, settings, connect) and the
  personal page's empty state; i18n five locales.
- `PublicJitsiController`: `config` (honours `enabled`, `jitsi_hosts`) and
  `loader.js` (serves a placeholder bundle that only logs "Meeting notes
  loader ready").
- Dev overlay steps 2 and 8 ([06](./06_dev_environment.md)).

**User-flow:** J-MN-1 (admin turns it on), status part only.
Exit bullets: (1) J-MN-1 walked up to "status lines honest"; (2) admin finds
the page in Plugins › Meeting notes in ten seconds; (3) consequence copy for
On/Off, Rotate, Stop all in five locales; (4) empty personal page, every
status line's error state, plugin `enabled=0` ⇒ `config` says off; (5) dark,
V2, 320 px.

**Exit:** plugin installed on the dev cluster, admin page shows four honest
lines, PHPUnit + PHPStan green.

## MN-3 — Sessions in the plugin

- `SessionRepository`, `SegmentRepository` (prefix queries with
  `bindValue`), `SessionService` state machine ([02 §2](./02_architecture.md#2-session-state-machine)),
  `StartPolicy` (v1.0), `ProsodyClient` + `HmacSigner` (against a stub),
  `SessionWatchdog`, `JitsiController` (`me`, `sessions` POST/GET/stop),
  `UserSessionController`, `TranscriberController` (`sessions/{ref}` GET,
  `events`), `ProsodyCallbackController`.
- `WindowTranscriber` with `AiFacade::transcribe` as the owner, `SegmentFilter`
  (rules of [05 §5](./05_stt_quality.md#5-filters-after-recognition)).
- Audit events.

**Tests:** every transition (table-driven), policy, HMAC (valid, wrong,
expired, replay), audio window limits, filter rules with fixtures, "no
audio left on disk after a window" (temp dir empty), secrets never in JSON
responses except `POST admin/connection`. Duplicate `Idempotency-Key` on
audio → one segment and the stored body (`dropped: duplicate`); a
`processing` receipt older than 60 s is taken over and still yields one
segment. Two identical gap posts and a `finished` that repeats them → one
gap; finalize without `finished` marks the tail. Failed session aged 8 days
is purged, aged 6 days is not, a saved session aged 8 days is not.
`transcribe()` is called with the owner id and `provider` / `model` /
`model_id` from the chosen `BMODELS` row.

**Exit:** a test script on the dev cluster (stub Prosody) creates a session,
posts three audio windows through the transcriber endpoint and gets three
segments; watchdog fails a session that never connects.

## MN-4 — Prosody module (can run beside MN-3)

- `prosody/mod_synaplan_notes.lua` per [04 §4](./04_jitsi_and_opendesk.md#4-prosody-module-mod_synaplan_notes):
  HTTP `start/stop/state/rooms/health`, metadata, deny client starts, chat
  lines, roster/stop/destroyed callbacks, HMAC both ways.
- busted specs; luacheck.
- Dev overlay step 4.

**Exit:** on the dev cluster, `ProsodyClient` starts and stops notes in a
real room with a Playwright participant; the bridge connects to a logger
with the right `urlParams`; a client `isTranscribingEnabled=true` without
Synaplan is refused; a moderator stop is accepted and calls back. Busted:
one occupant presence with E2EE enabled mid-session → one
`transcription-stopped` `{by:"e2ee"}` and the ref cleared; the same presence
again → no second callback.

## MN-5 — Transcriber plugin mode (`synaplan/sidecars/synaplan-transcriber`)

- Mode switch: a connection with `notes=<ref>` is plugin mode; without it,
  today's behaviour stays (core `opendesk_stt` path, D10).
- Bind via `GET …/sessions/{ref}` (meeting id check, profile, captions,
  roster, excluded), refresh every 30 s.
- WASM Opus decode, resampling, VAD stages 1+2, segmenter with the profile
  table ([05 §4](./05_stt_quality.md#4-windowing)), original-packet Ogg mux.
- Queue per meeting with `maxConcurrency`, retries with idempotency keys,
  gap reporting.
- Captions with `participant.id = endpointId`, `name` from the roster,
  `language` = session language.
- Events `connected`, `speaker-start/stop`, `stt-failure`, `session-end`,
  `finished`.
- `/metrics` ([02 §10](./02_architecture.md#10-observability)).
- Env: `SYNAPLAN_URL`, `MEETING_NOTES_TOKEN`, `TRANSCRIBER_AUTH_TOKEN`
  (bridge header), optional `MN_PROFILE_*`.

**Tests:** characterization fixtures from the real `stable-11031` messages
([01 §3](./01_findings.md#3-spike-2026-10-04-bridge-transcription-on-the-dev-cluster));
segmenter unit tests with synthetic PCM (pause, monologue > hard max, DTX
gaps); "no audio on disk" test; reconnect test. `node --test` in the
existing CI job.

**Exit:** `MN-4` + `MN-5` + `MN-3` together: a 3-minute synthetic two-person
meeting on the dev cluster produces segments with correct names and times;
`session-end` reaches the plugin.

## MN-6 — The transcript file

- `TranscriptRenderer` ([03 §8](./03_plugin_meeting_notes.md#8-storage-the-transcript-file)),
  `TranscriptWriter` (`FileUploadService`, source `meeting`, folder),
  `FolderCatalog`, finalize paths for `saved` / `saved_with_gaps` /
  `nothing_to_save` / `failed` + **Save again**.
- Self-install of the plugin for the owner on first `saved`.

**Tests:** renderer snapshots (merge rules, gaps, guests, time zones);
writer options; retry.

**Exit:** the `MN-5` meeting ends with one file in the chosen folder,
vectorized; the personal page lists it.

## MN-7 — Jitsi button, dialog, banner

- `jitsi/` loader per [04 §1–3](./04_jitsi_and_opendesk.md#1-the-loader):
  boot, Keycloak silent sign-in + popup fallback, button states, dialog,
  banner, toast, moderator stop, E2EE/guest/breakout handling, five locales,
  Shadow DOM, a11y.
- `jitsi/static/synaplan-notes-silent.html`.
- Keycloak client (dev overlay step 7), Jicofo + web + config steps 5–6.
- Captions config from `MN-0`.

**User-flow:** J-MN-2, J-MN-3, J-MN-4, J-MN-5, J-MN-6, J-MN-7.
Exit bullets:
1. All six journeys walked in the browser on the dev cluster (click, type,
   find, undo), recorded in `STATUS.md`.
2. The starter finds the file in ten seconds from the toast and from Files;
   a second participant finds who started and how to stop on the banner.
3. Consequence copy for Start, Stop (starter), Stop (moderator), Stop all,
   Turn off in five locales.
4. Empty / error / flag-off: cannot-start reasons, every failure sentence of
   [02 §7](./02_architecture.md#7-failure-handling), plugin off ⇒ no button
   and one config request only.
5. Jitsi dark and light themes, 320 px, WCAG AA measured on the pill, dialog
   and banner; keyboard-only run.

**Exit:** the bullets above, plus vitest green and the Playwright suite
`tests/e2e` green against the dev cluster.

## MN-8 — Engine and quality (beside MN-3…MN-7, gates MN-9)

| Id | Work |
|----|------|
| `MN-8a` | Synaplan core: **server mode for `WhisperProvider`** (`WHISPER_SERVER_URL` → whisper.cpp `whisper-server`, verbose JSON with segment scores, prompt, language) plus CLI fixes (`--prompt`, `-oj`, cgroup threads) and whisper.cpp 1.9.x in `synaplan-base-php` ([05 §6.2](./05_stt_quality.md#62-decision-one-whisper-story-two-run-modes-revised-d7)). Tests with recorded server responses. backend-only. |
| `MN-8a2` | `synaplan-charts`: `stt` sub-deployment like `tts` (CUDA image, `nvidia.com/gpu`, GPU node selector/tolerations, model via `oras` init, Service, sets `WHISPER_SERVER_URL`); CPU variant for clusters without GPU. `make all` green. |
| `MN-8b` | Ops (private): the same server container on our GPU host (Docker, CUDA) with the stock and the German ggml models, encrypted link to the dev cluster. **Partly done 2026-10-04:** German server running for the dev cluster, test audio only; open: the encrypted link. Details in the private ops notes. |
| `MN-8c` | Benchmark harness `tests/quality/` and runs A–C ([05 §9](./05_stt_quality.md#9-benchmark-and-acceptance)); tune the `balanced` profile and filters. |

**Exit:** [05 §1](./05_stt_quality.md#1-targets-acceptance-in-mn-8) targets met with the chosen engine; numbers in `STATUS.md`.

## MN-9 — v1.0 release

- J-MN-8 (ask about yesterday's meeting) walked.
- Plugin `CHANGELOG`, tag `v1.0.0`, plugin image, INSTALL.md complete
  (Synaplan side, openDesk side, Keycloak, transcriber, engine, legal note).
- Docs page in `synaplan-docs`: "Meeting notes for openDesk".

**Exit:** a fresh dev namespace (or a wipe of the dev one) is set up from
INSTALL.md alone, and J-MN-1 + J-MN-2 pass.

## MN-10 — v1.1 who may start

- `access_mode = selected`, `allowed_groups` (Synaplan IAM groups including
  directory groups from the `groups` claim), `allowed_users`.
- **Leave my voice out**: banner action for any signed-in participant ⇒
  `POST jitsi/sessions/{id}/exclusions`; transcriber gets `excluded` on the
  next binding refresh (≤ 30 s, plus the audio endpoint refuses immediately);
  the file says "{name} asked not to be included from 10:20."
- Retention days for session records and segments (files follow Files rules).
- Admin glossary.

**User-flow:** J-MN-9. Exit bullets as in MN-7 (walk, findability, copy,
empty/error/flag-off, themes).

## MN-11 — v1.2 save to OpenCloud

- Dialog option "Synaplan Files and my OpenCloud" when the Synaplan account
  has an OpenCloud connection (existing `synaplan-opencloud` link /
  `WebDavDestinationProvider`).
- Writer writes both; partial success copy ("Saved in Files. OpenCloud did
  not accept the file; try again from Meeting notes.").

**User-flow:** J-MN-10. Exit bullets as in MN-7.

## MN-12 — Production packaging for openDesk

- `deploy/opendesk/values-jitsi-meeting-notes.yaml.gotmpl` for openDesk's
  `customization.release.jitsi` slot: web (loader include, silent page,
  `config.transcription`), Prosody (module, conf, env), Jicofo (custom conf,
  token env), all switched by one value, default off.
- `deploy/synaplan-charts/values-meeting-notes.yaml` (plugin mount for web,
  worker, scheduler; transcriber deployment).
- Merge request to the openDesk edition repo, reviewed by its owner,
  with an empty render diff for every cluster that does not enable it.

**Exit:** the dev cluster runs from these values (overlay removed) and the
journeys still pass.

## Later (named so nobody assumes them)

| Item | Note |
|------|------|
| Summary with decisions and action items | Saved Task on the transcript, approvable, local model. |
| Element room notice / widget | Post "Notes saved" into the meeting's Element room; widget to start/stop from Element. |
| Element voice messages, Element Call | Core `opendesk_stt` path; separate plan. |
| Word-by-word captions | Streaming engine. |
| Translation | Captions and file in a second language. |
| Breakout rooms | One session per breakout or follow the participant. |
| E2EE meetings | Would need browser capture (option B) with per-person consent. |
| Merge with core `opendesk_stt` | D10. |
