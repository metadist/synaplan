# 01 — Findings (verified 2026-10-04)

Facts only. Each was read from code on `origin/main` (`b0390620c`), from
the running openDesk dev cluster, or measured in the spike in §3. Private
hostnames and credentials are left out on purpose; they are in the private
`vultr-cluster` notes.

---

## 1. Method

- Read `synaplan` `origin/main`, `synaplan-charts` `origin/main`,
  `synaplan-synaform` (plugin reference) and the openDesk edition used on
  the dev cluster.
- Inspected the running dev cluster read-only: images, config files inside
  the Jitsi containers, Prosody modules, Jicofo and bridge jars, Keycloak
  realm, Synaplan pod.
- Ran one reversible spike (§3) and reverted it.

## 2. Jitsi on openDesk (verified)

| Fact | Evidence |
|------|----------|
| openDesk's Jitsi images are `stable-11031` (web, prosody, jicofo, jvb) from Nordeck's openDesk mirror. | Pod images on the dev cluster. |
| Prosody has `mod_room_metadata_component`. `asyncTranscription`, `transcription`, `transcriberType`, `moderators`, … are **blocked for clients**; only server code may set them. Moderators may set any other key (e.g. `recording`). A hook `jitsi-metadata-allow-moderation` lets a module allow, deny or rewrite a client update per key. | `/prosody-plugins/mod_room_metadata_component.lua`, lines 102–118 and 280–316. |
| Jicofo `jicofo-selector-1.0-1183` has `jicofo.transcription.url-template`, `http-headers`, `ping`. It also reads **per-room** `transcription.httpHeaders` and `transcription.urlParams` from room metadata (`RoomMetadata$Metadata$Transcription`). Transcription runs when `recording.isTranscribingEnabled` **and** `asyncTranscription` are true. | `reference.conf` and class names in the deployed jars; Jicofo `ChatRoomImpl.kt` on `master`. |
| Jicofo's config template ends with `include "custom-jicofo.conf"` from `/config`. | `/defaults/jicofo.conf` line 341. |
| The bridge has `jicoco-mediajson` and `org.jitsi.videobridge.export.Exporter` (start/stop/ping/session-end/transcription-result). Prometheus metrics `jitsi_jvb_exporter_*` exist. | Deployed `lib/` and `/metrics`. |
| Prosody loads modules from `/prosody-plugins-custom` (first in `plugin_paths`). `mod_admin_shell` is on, so modules can be loaded at runtime with `prosodyctl --config /config/prosody.cfg.lua shell module load <m> <host>`. | `/config/conf.d/jitsi-meet.cfg.lua` line 15; `/config/prosody.cfg.lua` line 47. |
| Auth is `hybrid_matrix_token`: the Keycloak adapter issues an HS256 Jitsi token after Keycloak login. Its `context.user` has `id` (= Keycloak user id), `name`, `email`, `lobby_bypass`. Guests use an anonymous domain. Rooms need an authenticated creator (`restrict_room_creation`). | Prosody config; token observed in the spike. |
| A signed-in openDesk user who joins is **moderator** (spike: `role: moderator`). | Spike. |
| openDesk injects HTML into Jitsi through ConfigMap `jitsi-meet-files`: `body.html` (inline scripts that already use `APP.conference`), `plugin.head.html` (CSS), `custom-config.js` (`disableThirdPartyRequests: true`, branding). nginx has SSI on. No CSP header on the Jitsi page. | Mounted files in the web pod. |
| The openDesk Jitsi release has a `customization.release.jitsi` slot; the upstream chart exposes `extraVolumes`, `extraVolumeMounts`, `extraEnvs`, `extraConfig` for web, Prosody and Jicofo. | openDesk `helmfile/apps/jitsi/values.yaml.gotmpl`. |
| Client APIs present in the deployed bundle: `getMeetingUniqueId`, `getMetadataHandler`, `METADATA_UPDATED`, `isTranscribingEnabled`, `asyncTranscription`, `features/e2ee`, `TRANSCRIPTION_STATUS_CHANGED`, `customToolbarButtons`. | String counts in `app.bundle.min.js` / `lib-jitsi-meet.min.js`. |
| `customToolbarButtons` only reports clicks to an embedding page (iframe API). A script inside the page cannot rely on it. | jitsi-meet source. |

## 3. Spike 2026-10-04: bridge transcription on the dev cluster

**Setup (all reverted the same day):**

1. A WebSocket logger in the Jitsi namespace (Node, logs the connection and
   the first message of each event type, replies to `ping`, sends a test
   `transcription-result` every 150 packets).
2. `custom-jicofo.conf` with
   `url-template = "ws://<logger>:8095/transcribe?sessionId={{MEETING_ID}}&sendBack=true"`
   and `http-headers { "Authorization" = "Bearer <test>" }`; Jicofo container
   restarted.
3. A test Prosody module loaded at runtime on the MUC component: rooms named
   `mnspike*` get `asyncTranscription=true` and
   `transcription.urlParams = { notes = "spike-<room>", lang = "de" }`; rooms
   `mnspikeauto*` also get `recording.isTranscribingEnabled=true`.
4. Playwright Chromium with a fake microphone (synthetic tone + noise WAV),
   signed in as `demo1` through Keycloak, joined the rooms.

**Results:**

| Question | Answer |
|----------|--------|
| Does the bridge connect to our URL when the server sets the metadata? | **Yes**, within ~1.5 s of the join. Jicofo appended the per-room params: `?sessionId=<meeting uuid>&sendBack=true&notes=spike-mnspikeauto1&lang=de`. The `Authorization` header from `http-headers` arrived. User agent `Jetty/12.0.35`. |
| What arrives per speaker? | `{"event":"start","sequenceNumber":"1","start":{"tag":"25236799-3238766925","mediaFormat":{"encoding":"opus","sampleRate":48000,"channels":2,"parameters":{"useinbandfec":"1","minptime":"10"}},"customParameters":{"endpointId":"25236799"}}}` — tag = `<endpointId>-<ssrc>`. **No display name.** |
| Media? | `{"event":"media","sequenceNumber":"2","media":{"tag":"…","chunk":"2749","timestamp":"40092","payload":"<base64 Opus>"}}` ~38 packets/s (DTX gaps), ~78 bytes per packet (~30 kbit/s). `timestamp` advances in 48 kHz RTP units. |
| Keepalive? | `{"event":"ping","id":1}` every 10 s; we answer `{"event":"pong","id":1}`. |
| Can a moderator's client start/stop? | **Yes.** With `asyncTranscription` + `urlParams` set by the server, `getMetadataHandler().setMetadata('recording', {isTranscribingEnabled:true})` from demo1 started it; `false` stopped it: the bridge sent `{"event":"session-end"}` and closed with code 1001 within ~50 ms. |
| End of meeting? | Last participant left ⇒ `session-end` + close 1001. |
| Do results reach the browser? | **Yes.** The bridge counted 20 results, 0 parse failures, and broadcast them; the page received `conference.non_participant_message_received` from `transcriber` with our payload (`type`, `message_id`, `participant {id,name}`, `transcript[0].text`, `language`). |
| Are captions shown? | **Not yet.** Jitsi shows a result only when the viewer requested subtitles **and** the result `language` matches the viewer's subtitle language. The client set its transcription language to `en-US` (config default), our results were `de`. Fix is configuration: `config.transcription.preferredLanguage` / `useAppLanguage` and matching result languages ([04 §6](./04_jitsi_and_opendesk.md#6-jitsi-configjs)). |
| Client state | `features/transcribing.isTranscribing = true` while on ⇒ Jitsi's own "transcribing" indicator works for everyone, including mobile apps. |

**Consequences for the plan:** the bridge path works on openDesk as shipped;
names must come from Prosody (it knows each occupant's endpoint id, display
name and token identity); the room reference travels in `urlParams`; a
moderator stop never needs Synaplan.

## 4. Synaplan facts (verified on `origin/main`)

### 4.1 Plugin host

| Fact | Where |
|------|-------|
| The kernel discovers `plugins/*/manifest.json`, autoloads `backend/` under the manifest `namespace`, autoconfigures its services, and imports attribute routes from `backend/Controller`. Restart needed after adding a plugin. | `backend/src/Kernel.php` |
| Plugins are **installed per user**: `PluginManager::installPlugin()` symlinks `backend/` and `frontend/` into the user's upload dir and runs `migrations/*.sql` with `:userId` and `:group` (`P_<plugin>`). CLI: `app:plugin:install`, `app:plugin:install-verified-users`. New users get `DEFAULT_USER_PLUGINS`. | `Service/Plugin/*`, `Command/*Plugin*` |
| Installed plugins appear in the nav group **Plugins** and open at `/plugins/:pluginName`, where `PluginView.vue` imports `…/plugins/<name>/assets/index.js` and calls `mount(el, {userId, apiBaseUrl, pluginBaseUrl, config})`. No locale or theme is passed; Synaform reads `localStorage.language` and fetches its own `i18n/<lang>.json`. | `frontend/src/views/PluginView.vue`, `useNavItems.ts`, Synaform `frontend/index.js` |
| Plugin assets `^/api/v1/user/[^/]+/plugins/[^/]+/assets/` are `PUBLIC_ACCESS`. **No other plugin path is public**; a plugin cannot add one. | `config/packages/security.yaml` |
| Plugin data: `plugin_data` (`user_id`, `plugin_name`, `data_type`, `data_key`, JSON `data`) via `PluginDataService`; settings in `BCONFIG` group `P_<plugin>`. | `Entity/PluginData.php`, Synaform |
| Plugin routes check `#[CurrentUser]` against `{userId}` and the per-user `enabled` flag themselves. | Synaform `canAccessPlugin()` |
| A plugin may autowire core services: `AiFacade`, `FileUploadService`, `ConfigRepository`, `EncryptionService`, `PluginManager`, `RateLimitService`, `ModelRepository`. | Synaform constructor; `PlugKeyStore` uses `EncryptionService` |

### 4.2 Authentication from another openDesk page

| Fact | Consequence |
|------|-------------|
| The `api` firewall chains `CookieTokenAuthenticator`, `OidcBearerAuthenticator`, `QueryTokenAuthenticator`, `ApiKeyAuthenticator`. | A Keycloak access token works on every `/api` route. |
| `OidcBearerAuthenticator` validates the JWT (JWKS, issuer, audience = `OIDC_BEARER_AUDIENCE` or `OIDC_CLIENT_ID`) and **creates the user** from claims if missing. | A person who never opened Synaplan gets an account on first Start. |
| The Synaplan auth cookie is `SameSite=Strict`; CORS is `allow_origin: *` without credentials. | The Jitsi page cannot use the Synaplan session cookie cross-origin. |
| Every page carries `X-Frame-Options: SAMEORIGIN` (Caddy). | An iframe dialog served by Synaplan would be blocked on `meet.<domain>`. |
| Nubus realms have no `basic` client scope; clients need an explicit `sub` mapper. | Applies to the new Keycloak client. |

### 4.3 Speech-to-text

| Fact | Where |
|------|-------|
| `POST /v1/audio/transcriptions` takes `file`, `model`, `language`, `prompt`, `client_id`, `response_format` (`json`/`text`/`verbose_json`); sessions API exists too. | `docs/OPENAI_COMPATIBLE_API.md` |
| Providers with speech-to-text: Whisper (local whisper.cpp CLI), OpenAI, Groq, Mistral, xAI, Google. **`OpenAICompatibleProvider` has no speech-to-text**, so a self-hosted Whisper server cannot be used today. | `SpeechToTextProviderInterface` implementers |
| `WhisperService` starts the whisper.cpp CLI per call (model load each time). | `Service/WhisperService.php` |
| A retry exists when the detected language is unexpected (#2323). | `TranscriptionLanguageDecider` |

### 4.4 Files

| Fact | Where |
|------|-------|
| Folders in Files are group keys (`BGROUPKEY`); `GET /api/v1/files/groups` lists them. | `FileController` |
| `FileUploadService::uploadBatch($files, $user, $groupKey, 'vectorize', UploadOptions)` stores, extracts and vectorizes; `UploadOptions` has `source`, `sourceId`, `originalName`. | `Service/File/*` |
| `File::SOURCES` is a fixed list (`web_upload`, …, `generated`, `compute`). No `meeting` source. | `Entity/File.php` |

### 4.5 What #2252 already shipped

| Piece | Behaviour | Gap for this plan |
|-------|-----------|-------------------|
| `OpendeskSttModule` (`opendesk_stt`) | Absent until `OPENDESK_STT_URL`; health probe of the sidecar. | Keep; plugin page links to it. |
| `MeetingNotesController` `/api/v1/opendesk/meeting-notes*` | Save/list/get notes for the API-key owner. | Notes are owned by the key, not the starter. |
| `MeetingNoteStore` | JSON files in a server directory. | **Not in Synaplan Files**, not findable, not a source. |
| `sidecars/synaplan-transcriber` (Node) | Bridge WebSocket; fixed 8 s windows; per window: create STT session + post Ogg + delete session; captions `participant.id = tag`; language from env or `lang`; Element voice notes; Nextcloud/Matrix publish. | No voice detection, no overlap, no prompt continuity, no names, three calls per window, no session binding, image **not published**. |

## 5. Dev cluster state (2026-10-04)

| Item | State |
|------|-------|
| Synaplan | 5.1.0, plugins baked into the image: `castingdata`, `hello_world`, `serper_search`. No plugins volume. |
| Speech-to-text | **Not working**: default SOUND2TEXT is a cloud model without key; local Whisper row is active but `WHISPER_ENABLED=false` and no model files. No GPU speech server. |
| Jitsi | `stable-11031`, transcription not configured; `config.js` has no `transcription` block. |
| Keycloak | Realm `opendesk` (Nubus); clients for the openDesk apps and Synaplan; no client for the Jitsi button. |
| Realtime (Centrifugo) | Not deployed with Synaplan. Not needed by this plan (the loader polls; captions travel through Jitsi). |

## 6. Gaps → steps

| Gap | Step |
|-----|------|
| No public plugin route prefix | `MN-1a` |
| No `meeting` file source / label | `MN-1b` |
| Transcriber image not published | `MN-1c` |
| No plugin | `MN-2`, `MN-3`, `MN-6` |
| No Prosody control module | `MN-4` |
| Transcriber has no session binding, names, voice detection | `MN-5` |
| No Jitsi button / dialog / banner, no Keycloak client | `MN-7` |
| No self-hosted speech-to-text provider, no engine | `MN-8` |
| Captions filtered by viewer language | `MN-7` (config) |
