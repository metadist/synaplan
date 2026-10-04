# 00 — Master plan: Meeting notes for Jitsi in openDesk

**Status:** Plan 2026-10-04, decisions D1–D12 proposed in
[`README.md`](./README.md) §4.
**Class:** new plugin repo (`plugins/**` = backend-only in this repo's
mobile-impact policy) + small core prerequisites (backend-only, one
ota-candidate label in Files) + sidecar change in `sidecars/` + openDesk
configuration (outside this repo).

---

## 1. Goal

Synaplan becomes the meeting-notes brain of an openDesk installation:

- **For people in a meeting:** one obvious button, a short dialog, and a
  transcript that shows up in their Synaplan Files without anyone having to
  keep a tab open or install anything.
- **For the openDesk admin:** one plugin page in Synaplan that says, in plain
  sentences, whether the Jitsi connection, the transcriber and the speech
  model work, plus the snippets to paste into openDesk.
- **For Synaplan:** every meeting becomes a searchable source. "What did we
  decide about the migration last Tuesday?" works in chat the day after.

## 2. Who and why

| Person | Today | With meeting notes |
|--------|-------|--------------------|
| Team lead in a public-administration openDesk | Writes minutes by hand during the call, or not at all. | Clicks **Meeting notes**, talks, finds the transcript in Files › Meetings, asks Synaplan for the action items. |
| Participant | Does not know whether anyone writes down what they say. | Sees "Meeting notes are on · started by Anna" for the whole meeting and in the chat. |
| openDesk admin | Has no sovereign transcription. Jitsi's own option needs a cloud speech API. | Points Jitsi at Synaplan. Audio stays on the organisation's servers. |

## 3. Scope per version

| Version | In | Out |
|---------|----|-----|
| **v1.0 (MVP, "Jitsi only")** | Plugin active ⇒ button in every Jitsi meeting for every signed-in openDesk user. Dialog: language + folder in Synaplan Files. Bridge-based audio, per-speaker transcript with names and times, live captions (switchable), banner + chat notice, Stop from the button or from Jitsi, honest failure copy, admin page with status checks and setup snippets, personal "Meeting notes" page. | Access limits, OpenCloud, Element, translation, summaries, E2EE meetings, guests starting notes. |
| **v1.1** | Admin limits who may start (Synaplan groups incl. directory groups, single people). "Leave my voice out" for any participant. Retention days. | — |
| **v1.2** | Save to OpenCloud (the starter's space) in addition to Synaplan Files. | — |
| **later** | Summary with decisions and action items as an approvable Saved Task; Element room notice and widget; word-by-word captions with a streaming engine; translation; breakout rooms. | — |

## 4. Architecture decision

### 4.1 Options

| Criterion | **A. Jitsi bridge → transcriber** (chosen) | B. Browser capture (SDK in every participant's tab) | C. Bot participant (headless browser) | D. Jibri / Jigasi |
|---|---|---|---|---|
| Works without anyone keeping a tab open | **yes** (server-side) | no, each tab streams | yes | yes |
| Mobile-app and guest participants are heard | **yes** | no, only with a collector tab | yes | yes |
| Per-speaker audio (names) | **yes**, one stream per participant | yes | yes | mixed (Jibri) / yes (Jigasi) |
| End-to-end encrypted meetings | no (bridge sees ciphertext) → refuse with a sentence | yes | yes, as a visible member | no |
| Changes to openDesk | config: loader script, Jicofo URL, Prosody module | loader script | bot accounts + browser fleet | heavy, Jigasi transcription is deprecated |
| Operational cost | one small Node service | none server-side | high | high |
| Status on openDesk's Jitsi `stable-11031` | **verified 2026-10-04** ([01 §3](./01_findings.md#3-spike-2026-10-04-bridge-transcription-on-the-dev-cluster)) | not tested | not tested | deprecated |

**Decision (proposed D1): A.** It matches the Synaplan rule "no feature that
works only while the tab is open" (Perfect-UX bar point 6), it hears mobile
and guest participants, and openDesk already ships every server piece. B
stays the fallback idea for E2EE meetings (later). C and D are not pursued.

### 4.2 Overview

```text
 Jitsi page (meet.<domain>)                         Synaplan (synaplan.<domain>)
 ┌──────────────────────────────┐   Keycloak       ┌───────────────────────────────────────────┐
 │ openDesk Jitsi web           │   bearer         │ Plugin meeting_notes                      │
 │  + loader (from Synaplan)    │ ───────────────▶ │  • sessions, policy, settings, audit      │
 │  • button + dialog + banner  │  start / stop    │  • renders transcript → Synaplan Files    │
 └──────────────▲───────────────┘                  │  • /public/transcriber/*  (token)         │
                │ room metadata (on/off, who)      │  • /public/prosody/*      (HMAC)          │
 ┌──────────────┴───────────────┐  HMAC start/stop │                                           │
 │ Prosody + mod_synaplan_notes │ ◀─────────────── │ Synaplan speech-to-text (AiFacade)        │
 │  • sets transcription meta   │ ───────────────▶ │  model from plugin settings               │
 │  • chat notice, roster push  │  roster, stopped └──────────────────────▲────────────────────┘
 └──────────────┬───────────────┘                                         │ audio window (ogg)
                │ "transcribe this room, url params: notes=<ref>"         │ + speaker, times
 ┌──────────────▼───────────────┐   WebSocket (JSON, Opus per speaker)  ┌─┴────────────────────┐
 │ Jicofo → Jitsi Videobridge   │ ────────────────────────────────────▶ │ synaplan-transcriber │
 │  (bridge transcription)      │ ◀──── transcription-result (captions)  │ (plugin mode)        │
 └──────────────────────────────┘                                       └──────────────────────┘
                                                      ┌─────────────────────────────────────────┐
                                                      │ Speech engine: GPU Whisper (self-hosted) │
                                                      │ or local whisper.cpp (dev) via Synaplan  │
                                                      └─────────────────────────────────────────┘
```

### 4.3 One meeting, end to end

1. Anna is in Jitsi room `standup`. The loader asked Synaplan
   (`/public/jitsi/config`) on page load and showed the button because the
   plugin is on.
2. Anna clicks **Meeting notes**. The loader signs her in silently with the
   Keycloak client `synaplan-meeting-notes` and calls
   `GET /api/v1/plugins/meeting_notes/jitsi/me`: may she start, which
   folders and languages exist.
3. She picks *Deutsch* and *Meetings*, presses **Start meeting notes**.
   `POST …/jitsi/sessions` with room, meeting id, language, folder.
4. The plugin checks the policy (v1.0: signed in, not a guest), creates
   session `ms_…` with a 128-bit reference `ref`, and calls Prosody
   `POST /synaplan-notes/v1/start`.
5. Prosody checks that the meeting id is the live one and that Anna is in the
   room, sets the room metadata (transcription on, `urlParams.notes=<ref>`,
   `lang=de`, "started by Anna"), posts a chat line for everyone, and returns
   the roster.
6. Jicofo sees the metadata, tells the bridge to connect to
   `ws://synaplan-transcriber/transcribe?sessionId=<meeting>&notes=<ref>&lang=de`.
7. The transcriber asks the plugin for the session (`GET
   /public/transcriber/sessions/<ref>`), gets language, profile, roster,
   captions on/off, then receives Opus per speaker, cuts it at speech pauses,
   and posts each window to `POST /public/transcriber/sessions/<ref>/audio`.
8. The plugin transcribes the window as Anna (her SOUND2TEXT model or the
   plugin's model), stores the segment with speaker and times, answers with
   the text. The transcriber sends the caption back into Jitsi.
9. Anna presses **Stop**. Plugin → Prosody stop → bridge sends
   `session-end` → transcriber flushes the last windows and reports
   `session-end` → plugin renders the Markdown file into Anna's folder,
   marks the session **saved**, Prosody posts "Meeting notes stopped".
10. The loader shows "Saved to Files › Meetings ›
    2026-10-04 1002 standup.md" with **Open**. Anna finds it in Files in ten
    seconds; it carries "From Jitsi meeting *standup* on 4 Oct 2026, 10:02".

Failure paths (transcriber down, speech model down, Synaplan down, everyone
leaves, Jitsi stop) are in [02 §7](./02_architecture.md#7-failure-handling).

## 5. Components

| Component | Responsibility | Lives in | New / existing |
|-----------|----------------|----------|----------------|
| Plugin `meeting_notes` (PHP + plain JS frontend) | Settings, policy, sessions, start/stop, transcribe proxy, transcript file, admin and user pages, audit, Jitsi loader asset | `metadist/synaplan-meetingnotes` → deployed to `/plugins/meeting_notes` | new |
| Jitsi loader (TypeScript, built to one ES module) | Button, dialog, banner, Keycloak silent sign-in, calls to the plugin | same repo, `jitsi/`; served by the plugin | new |
| Prosody module `mod_synaplan_notes.lua` | Per-room on/off via room metadata, guard against client starts, chat notice, roster push, room-ended callback | same repo, `prosody/`; mounted into openDesk's Prosody | new |
| `synaplan-transcriber` sidecar | Bridge WebSocket, per-speaker windows, voice detection, captions, calls the plugin | `synaplan/sidecars/synaplan-transcriber` | existing (#2252), gets "plugin mode" |
| Synaplan core | Speech-to-text, files, auth, plugin host | `synaplan` | small prerequisites `MN-1` |
| Speech engine | Whisper on GPU behind an OpenAI-compatible API | ops (GPU host) + new Synaplan provider (`MN-8`) | new |
| openDesk configuration | Loader `<script>`, `config.js` transcription, Jicofo URL + header, Prosody module + secret, Keycloak client | openDesk Helm values (for the dev cluster: a private overlay, [06](./06_dev_environment.md)) | config |

## 6. Repositories and what changes where

| Repo | Changes | Mobile-impact class |
|------|---------|---------------------|
| `metadist/synaplan-meetingnotes` (new, like `synaplan-synaform`) | Everything plugin-specific: `meetingnotes-plugin/` (manifest, backend, frontend, i18n, migrations), `jitsi/` loader sources, `prosody/` module, `deploy/` snippets, tests, CI. | n/a (separate repo; in Synaplan it is `plugins/**` = backend-only) |
| `metadist/synaplan` | `MN-1`: public plugin route prefix in `security.yaml`; `File::SOURCES` + `meeting` source with a Files label (five locales); publish the transcriber image in CI. `MN-5`: transcriber plugin mode. `MN-8`: server mode for Synaplan's own Whisper provider (+ whisper.cpp bump in `synaplan-base-php`). | backend-only, except the Files label (ota-candidate) |
| `metadist/synaplan-charts` | Optional `plugins:` values (init container copies plugin images into `/plugins/<id>` for web, worker, scheduler); transcriber sub-deployment. Until then the generic `volumes` / `additionalInitContainers` values suffice. | n/a |
| openDesk edition / operator config | Loader include, Jitsi config, Jicofo, Prosody, Keycloak client. **Not** changed in the shared edition repo during development; see [06](./06_dev_environment.md). A merge request with default-off switches comes after the walk (`MN-12`). | n/a |

**Why a plugin and not a core FeatureModule:** the request is a plugin like
Synaform; it ships and deploys on its own cadence, keeps openDesk-specific
code out of every Synaplan install, and reuses the plugin host's per-user
pages, settings and assets. The core module `opendesk_stt` stays for Element
voice notes and the operator-key caption path (D10). When the plugin is
installed and configured, the plugin page names the core module's sidecar
health so there is one place to look.

## 7. UX bar mapped to this feature

| # | Bar (AGENTS.md) | How this plan meets it |
|---|-----------------|------------------------|
| 1 | First run without help | Admin: plugin page with four status lines, each "ok" or one sentence + fix; "Connect openDesk" emits copy-paste snippets. Person: the button explains itself; the dialog has two choices and one primary button. Plugin off ⇒ no button, no teaser (loader exits after one config call). |
| 2 | Ten-second findability | The transcript is a normal file in the chosen Files folder with a provenance line and the source label "From a meeting"; the stop toast links to it; the personal **Meeting notes** page (Plugins group) lists every session with its file. Named path: Files › *folder* › file, or Plugins › Meeting notes. |
| 3 | Five questions on the open surface | Banner in Jitsi and the session row answer: owner ("started by Anna"), who else (everyone in this meeting is transcribed; the file is only Anna's until she shares it), what it touches ("one file in Files › Meetings"), stop ("Stop"), where from ("Jitsi meeting standup, 4 Oct, 10:02"). |
| 4 | Honest outcome copy | Every session ends in **saved**, **saved with gaps**, **nothing to save** or **failed**, each with one sentence that says what was and was not written ([04 §3](./04_jitsi_and_opendesk.md#3-copy-en--de)). |
| 5 | Undo is a click | **Stop** in the banner; Jitsi's own stop for moderators; **Stop** on the session row in Synaplan; admin **Stop all**; deleting the file deletes the transcript. Consequence copy per action. |
| 6 | Stability is UX | Server-side audio path; watchdog finalizes sessions with no activity; no `setTimeout` race fixes; journeys walked in the browser with synthetic voices ([06 §5](./06_dev_environment.md#5-synthetic-meetings)). |
| 7 | Every theme, size, locale | Synaplan pages: light, dark, V2, 320 px, five locales. Jitsi dialog: Jitsi's dark surface plus a light variant when Jitsi runs light; 320 px; five locales in the loader bundle. WCAG AA measured. |

## 8. Journeys (walk or it is not done)

Named here before any UI exists (U1). The five sprint-file exit bullets
(UX contract §6) are listed per UI step in [07](./07_sprints.md).

| Id | Journey |
|----|---------|
| **J-MN-1 Admin turns it on** | Admin opens Plugins › Meeting notes. Four status lines: *Jitsi connection*, *Transcriber*, *Speech model*, *Sign-in for the button*. Each is green or says what is missing in one sentence with the fix. **Connect openDesk** shows the snippets with copy buttons. After pasting and reloading Jitsi, the status is green and a new meeting shows the button. |
| **J-MN-2 Start, talk, find** | demo1 joins a meeting, clicks **Meeting notes**, keeps *Deutsch* and *Meetings*, presses **Start meeting notes**. Within five seconds everyone sees the banner and a chat line. Two people talk for two minutes; captions appear with names. demo1 presses **Stop**. Within 30 s the toast says where the file is; **Open** shows it in Synaplan Files; the first line says where it came from; demo1 shares it with a group. |
| **J-MN-3 The other participant** | demo2 joins while notes are on: the banner says "Meeting notes are on · started by Demo One · since 10:02". demo2 has no Start (one session per meeting). demo2's lines appear as "Demo Two" in the file. A guest sees the banner and the chat line, never a Start. |
| **J-MN-4 Something is down** | (a) Transcriber not running: Start ends in "Meeting notes could not start. Nothing is being written down. Try again in a minute or ask your administrator." and nothing else changes. (b) Speech model fails mid-meeting: banner says notes are paused; the meeting continues; the file marks the gap with times. (c) Synaplan unreachable at Stop: Jitsi's own stop still works; the session is saved by the watchdog when Synaplan is back, with a line saying where it stopped. |
| **J-MN-5 Every way to stop** | Stop from the banner; stop by a moderator in Jitsi's menu; last person leaves; plugin switched off by the admin. Each ends with exactly one file (or "nothing to save") and the session row is never left "running". |
| **J-MN-6 Plugin off** | Admin switches the plugin off: running sessions stop with "Meeting notes were turned off by your administrator. The notes so far are saved." New page loads show no button; the loader makes one config call and nothing else. |
| **J-MN-7 Not possible here** | E2EE meeting: the button opens a one-sentence explanation, no Start. Guest: no Start. Breakout room: no Start in v1 ("Meeting notes work in the main room."). |
| **J-MN-8 Find it later and ask** | The next day demo1 asks in Synaplan chat "What did we decide in yesterday's standup?" with the Meetings folder as source; the answer cites the transcript. |
| J-MN-9 (v1.1) | Admin limits starting to group *Leads*; a non-member sees no button; a member does. A participant uses **Leave my voice out**; their later speech is not in the file and the file says so. |
| J-MN-10 (v1.2) | With OpenCloud on, the dialog offers "Synaplan Files and my OpenCloud"; the file appears in OpenCloud › Meetings within 30 s of Stop. |

## 9. Phases and exits

| Phase | Steps | Exit |
|-------|-------|------|
| P0 Research + spike | `MN-0` | Spike recorded (done for the bridge path); engine benchmark recorded; decisions ticked. |
| P1 Foundations | `MN-1`, `MN-2` | Core prerequisites merged; plugin skeleton installed on the dev cluster; admin page shows four honest status lines. |
| P2 The pipe | `MN-3`, `MN-4`, `MN-5`, `MN-6` | A scripted meeting on the dev cluster produces one correct Markdown file in Synaplan Files, started and stopped through the plugin API. |
| P3 The surface | `MN-7` | J-MN-1 … J-MN-7 walked in the browser on the dev cluster, light/dark/320 px/five locales. |
| P4 Quality | `MN-8` | German WER and latency targets met on the benchmark set with the chosen engine ([05 §9](./05_stt_quality.md#9-benchmark-and-acceptance)). |
| P5 Ship v1.0 | `MN-9`, `MN-12` | J-MN-8 walked; plugin release tag; openDesk values snippet published; STATUS entries. |
| P6 v1.1 / v1.2 | `MN-10`, `MN-11` | J-MN-9 / J-MN-10 walked. |

## 10. Risks

| Risk | Likelihood | Impact | Treatment |
|------|-----------|--------|-----------|
| Bridge transcription protocol changes between Jitsi releases | medium | high | Characterization fixtures from the real `stable-11031` messages ([01 §3](./01_findings.md#3-spike-2026-10-04-bridge-transcription-on-the-dev-cluster)); contract test on every openDesk bump. |
| German quality too low on CPU | high on CPU | high | GPU engine (D7); CPU only for development; benchmark gate `MN-8`. |
| Loader injection breaks with an openDesk update of `body.html` | medium | medium | Loader is one `<script>` appended at the end; it feature-detects `APP.conference` and exits silently if missing. |
| Silent sign-in blocked (third-party cookie rules) | low (same site) | medium | Popup fallback with one sentence; verified in `MN-0`. |
| Legal: transcription of employees | medium | high | Visible banner + chat notice + stop; v1.1 per-person opt-out and access limits; operator guidance on works council / DPIA in the admin docs (not legal advice). |
| GPU shared with the chat models | medium | medium | Separate GPU quota or card; plugin concurrency caps; gaps are marked, never silent. |
| A second "Meeting notes" surface from the core module confuses admins | medium | low | Plugin page links the core module status; merge decision D10 tracked. |
