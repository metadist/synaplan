# E1 — Sound transcription, then three easy fixes

**Sprint:** E1 of [`00_master_plan.md`](./00_master_plan.md).
**Goal:** Next coding sprint. Transcription is the first commit series.
#2204, #2205, and #2206 follow in the same window as separate commits.

## User-flow

Journeys: **J-STT-1**, **J-STT-2**, **J-STT-3**, **J-EASY-1**,
**J-EASY-2**, **J-EASY-3** in
[`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md) spirit
and named in [`00_master_plan.md`](./00_master_plan.md) §1.
Copy for any new sentence ships in all five locales in the same PR.

## Goal (transcription)

A new user on a local install, guided only by the screen, turns speech
into text and finds that text again. The same default model answers
`POST /v1/audio/transcriptions`, so openDesk later points at a path a
human has already used.

Walk the existing recorder and the existing “drop an audio file” path
before adding UI. If J-STT-1 already passes, the code change is the
failure copy and the operator default, not a new screen.

**Local model:** whisper.cpp, `WHISPER_DEFAULT_MODEL=base`. The dev
compose currently sets `tiny` so boots stay light; say that in the
sprint notes and do not silently swap the compose default unless the
journey cannot be understood on `tiny`. Do not add LocalAI for speech.

**Do**

1. Bind SOUND2TEXT to the local Whisper row when no cloud key is set.
2. Record in chat **or** drop an audio file. The transcript is in the
   thread or on the file. The audio is listed in Files.
3. Missing binary, missing model file, or speech turned off ends in
   one sentence and a named next step. No HTTP status in the bubble.
4. Sovereign installs do not fall through to the browser’s cloud
   speech API (`WEB_SPEECH_ENABLED=false` already exists — confirm the
   button uses the server).
5. Document one curl against `/v1/audio/transcriptions` that hits the
   same default. That curl is the contract OD-A will use.

**Do not**

- Add a Transcriber page, a second engine, or the Jitsi sidecar.
- Enable cloud STT when the user asked for local.
- Change image generation in this sprint ([#2208](https://github.com/metadist/synaplan/pull/2208) owns that).

## Goal (easy, after the transcription journey is written)

Separate commits. Briefs:
[`../20260927_openai_compatible_image_generation.md`](../20260927_openai_compatible_image_generation.md).

| Issue | Do | Stop when |
| ----- | -- | --------- |
| [#2204](https://github.com/metadist/synaplan/issues/2204) | Quieter style only on the null “-- Select Model --” row in `AIModelsConfiguration.vue`. WCAG AA on the dropdown panel in light and dark. | 320 px still fits. No new i18n key. Do not recolor `.dropdown-item` or `.txt-secondary`. |
| [#2205](https://github.com/metadist/synaplan/issues/2205) | `${NAME:-default}` for every **published host port** (`5173`, `8000`, `3307`, `11435`, `9999`, `8082`, `1025`, `8025`, `8080`, `8443`, `6333`). Document only those names. | Today’s `docker compose up` does not move. Container DNS stays on container ports. Any URL the boot page shows uses the configured host port. |
| [#2206](https://github.com/metadist/synaplan/issues/2206) | README leads with copy `deploy/compose.yaml` + `deploy/selfhost.env.example`, set `SYNAPLAN_VERSION` to a release tag, `docker compose up -d`. Header comment says which URL to open. | No `make`, no git checkout, no `latest`. Do not shrink the root dev compose. |

## Exit criteria

1. J-STT-1, J-STT-2, and J-STT-3 walked in the browser (U10). J-EASY-1 walked in light, dark, and 320 px. J-EASY-2 and J-EASY-3 walked from a clean env file.
2. The transcript and the audio file are findable in ten seconds from chat or Files (U2).
3. Failure and disconnect copy is kind-specific, in all five locales, and says what did and did not happen (U3, U8).
4. Empty, error, and speech-off states are one sentence plus one action. Speech off means the control is absent or explains the one switch, never a dead spinner (U5, U11).
5. Dark theme and 320 px checked for the chat recorder and the model row (U9).

## Class

| Slice | Class |
| ----- | ----- |
| Chat / Files transcription copy or UI | `ota-candidate` |
| Whisper binding, `/v1/audio` behavior | `backend-only` |
| #2204 | `ota-candidate` |
| #2205, #2206 | deploy / docs. No new mobile path. |
