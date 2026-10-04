# openDesk meeting notes plugin (Jitsi first) — start here

**Status:** Plan, 2026-10-04. Research and one feasibility spike are done
(see [`STATUS.md`](./STATUS.md)). No product code yet. Review this folder,
tick the decisions in §4, then start with step `MN-1` in
[`07_sprints.md`](./07_sprints.md).
**Owner:** product owner.
**Binding UX:** [`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md)
(U1–U12) and the Perfect-UX bar in `AGENTS.md`.
**Roadmap:** this is the concrete plan for row 2 of
[`../20260925_roadmap.md`](../20260925_roadmap.md) ("openDesk meeting notes
(Jitsi) — minimum"). It narrows row 2 to a **Synaplan plugin** and reuses the
sidecar from [#2252](https://github.com/metadist/synaplan/pull/2252).

---

## 1. The product in four sentences

1. When the plugin **Meeting notes** is active in a Synaplan that belongs to an
   openDesk installation, every Jitsi meeting shows a small **Meeting notes**
   button.
2. A signed-in person clicks it, picks the **meeting language** and the
   **folder in Synaplan Files** where the notes go, and starts.
3. Everyone in the meeting sees that notes are on. Jitsi's own bridge streams
   each speaker's voice to Synaplan speech-to-text. Nobody's browser has to
   stay open, and audio is never kept.
4. When the meeting or the notes stop, one Markdown transcript with speaker
   names and times is in the chosen folder. It can be found in ten seconds,
   shared like any file, and asked about in chat.

The admin decides **whether** the button exists, which languages, which
folder by default, and which speech model. In v1.0 everyone with an account
can start. Restricting that to selected people or groups is v1.1. Saving into
OpenCloud is v1.2.

## 2. Reading order

| # | File | What you learn |
|---|------|----------------|
| 00 | [`00_master_plan.md`](./00_master_plan.md) | Goal, scope per version, architecture overview, journeys, UX bar, repos, release classes. |
| 01 | [`01_findings.md`](./01_findings.md) | Verified facts (2026-10-04): what Jitsi on openDesk can do today, what Synaplan has, the spike results, the gaps. |
| 02 | [`02_architecture.md`](./02_architecture.md) | Components, session state machine, every API between them, data model, security and failure handling. |
| 03 | [`03_plugin_meeting_notes.md`](./03_plugin_meeting_notes.md) | The plugin itself: repo layout, `manifest.json`, settings, routes, classes, admin and user pages, i18n. |
| 04 | [`04_jitsi_and_opendesk.md`](./04_jitsi_and_opendesk.md) | The Jitsi button and dialog, the Prosody module, Jicofo and `config.js`, Keycloak client, openDesk packaging. |
| 05 | [`05_stt_quality.md`](./05_stt_quality.md) | How the audio becomes good text: windowing, voice detection, prompts, filters, engines, benchmark, where each setting lives. |
| 06 | [`06_dev_environment.md`](./06_dev_environment.md) | How to develop against an openDesk dev cluster without touching the openDesk edition repo. |
| 07 | [`07_sprints.md`](./07_sprints.md) | The steps `MN-0` … `MN-12`, each with files, tests and an exit. |
| — | [`STATUS.md`](./STATUS.md) | Step log. The spike entry of 2026-10-04 is the first row. |

Private cluster specifics (IP addresses, hostnames, ports, firewall rules,
namespaces, credentials, the spike scripts) are **not** in this public
repository — write "the dev cluster" or "our GPU host" here. They live in
the private `vultr-cluster` repository (page "Meeting notes plugin: the
Vultr dev cluster").

## 3. Words

| Term (en) | Meaning | Not in primary copy |
|-----------|---------|---------------------|
| **Meeting notes** | The feature as people see it: a written transcript of a Jitsi meeting. de *Mitschrift*, es *Notas de la reunión*, fr *Notes de réunion*, tr *Toplantı notları*. | "recording" (we do not record audio or video), "transcriber", "STT", "sidecar" |
| **Start / Stop meeting notes** | The two actions. | "Start recording" |
| **Folder** | A folder in Synaplan Files (v1). OpenCloud folder in v1.2. | bucket, path, group key |
| **Transcriber** | The small service that receives the audio from Jitsi. Docs only. | — |
| **Bridge** | Jitsi Videobridge. Docs only. | JVB, Jicofo, Prosody, MUC |

Banned in primary copy (from the roadmap §9, still binding): MatrixRTC,
LiveKit, JVB, Jigasi, MSC, SFU, whisper.cpp, `base_url`, ACL, tenant, node,
sandbox, container, provisioning.

## 4. Decisions to tick before `MN-1`

Each line is explained where the link points. "Proposed" is the plan's
recommendation.

| # | Decision | Proposed | Where | Agree? |
|---|----------|----------|-------|--------|
| D1 | How audio leaves the meeting | **Jitsi's own bridge transcription** (bridge → WebSocket → transcriber). Verified working on openDesk's Jitsi `stable-11031`. Not browser capture, not a bot participant, not Jibri, not Jigasi. | [00 §4](./00_master_plan.md#4-architecture-decision), [01 §2](./01_findings.md#2-jitsi-on-opendesk-verified) | |
| D2 | Who may start and stop | **Synaplan decides**; a Prosody module turns transcription on per room. A Jitsi moderator can always stop from Jitsi, even if Synaplan is down. Clients cannot start without Synaplan. | [02 §3](./02_architecture.md#3-start-and-stop-authority) | |
| D3 | How the Jitsi button knows who you are | **Keycloak public client** `synaplan-meeting-notes` with silent sign-in (PKCE), token audience `synaplan`. Not the Synaplan cookie, not an iframe, not the Jitsi token. | [02 §6](./02_architecture.md#6-identity-in-the-jitsi-page) | |
| D4 | Packaging | **Plugin `meeting_notes`** in its own repo `metadist/synaplan-meetingnotes` (Synaform layout). The transcriber stays in `synaplan/sidecars/synaplan-transcriber` and gets a "plugin mode". | [00 §6](./00_master_plan.md#6-repositories-and-what-changes-where) | |
| D5 | Where audio goes for speech-to-text | Transcriber → **plugin endpoint** → Synaplan's normal speech-to-text (`AiFacade::transcribe`) as the **person who started**. No API key in the transcriber; every audio window is checked against a live session. | [02 §5](./02_architecture.md#5-apis) | |
| D6 | Where the transcript lands (v1.0) | **One Markdown file in a Synaplan Files folder** chosen in the dialog (default from the admin, e.g. "Meetings"), owned by the person who started, extracted and vectorized like an upload. | [03 §8](./03_plugin_meeting_notes.md#8-storage-the-transcript-file) | |
| D7 | Speech engine for quality | **Synaplan's own Whisper, with a server mode**: a whisper.cpp `whisper-server` (model loaded once) on GPU — on our GPU host now, as a GPU pod from `synaplan-charts` on openDesk clusters with GPU nodes. Stock and German (`primeline`) large-v3-turbo benchmarked; faster-whisper as the throughput alternative. Today's CLI path stays for small installs. Cloud only if the admin switches it on. | [05 §6](./05_stt_quality.md#6-engines) | |
| D8 | Consent model in v1.0 | **Transparency + one-click stop**: banner for everyone who has the loader, Jitsi's own "transcribing" indicator for everyone, one chat line on start and stop. Per-person "leave my voice out" is v1.1. | [00 §7](./00_master_plan.md#7-ux-bar-mapped-to-this-feature) | |
| D9 | Live captions | **On by default, admin can switch off.** Final captions per spoken segment (about 1–3 s after a pause on GPU). Word-by-word captions are later. | [05 §8](./05_stt_quality.md#8-live-captions) | |
| D10 | Relation to the core `opendesk_stt` module | The core module stays for Element voice notes and the operator-key caption path. The plugin owns the Jitsi product. Merge later (open item). | [00 §6](./00_master_plan.md#6-repositories-and-what-changes-where) | |
| D11 | v1.1 access limit | Admin picks **Synaplan groups** (including directory groups from openDesk) and single people. Others see no button. | [07 MN-10](./07_sprints.md#mn-10--v11-who-may-start) | |
| D12 | v1.2 OpenCloud | Write the same file into the starter's OpenCloud space through the existing WebDAV destination, keep the Synaplan copy as the AI source. | [07 MN-11](./07_sprints.md#mn-11--v12-save-to-opencloud) | |

## 5. What is out of v1

Element (chat, voice messages, Element Call), translation, summaries,
speaker separation beyond "one voice per Jitsi participant", audio or video
recording, phone dial-in, end-to-end encrypted meetings, breakout rooms.
Each has a line in [`07_sprints.md`](./07_sprints.md) §later so nobody
assumes it silently.
