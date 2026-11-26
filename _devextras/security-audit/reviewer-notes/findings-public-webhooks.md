# Findings: public-webhooks (anonymous surfaces and machine-to-machine callbacks)

Commit a2e57b08d. Local stack, API http://localhost:8000. Paths are relative to
`/Users/cristiangrxs/Workspace/metadist/synaplan/` unless absolute.
Raw rule-to-route join: `SCRATCH/pubwh/join.txt`

Local preconditions that shaped testing: Test AI provider (canned answers, constant
embedding vector), WhatsApp / Telegram / Federation modules unconfigured (404
`feature_not_configured`), Stripe and IAP unconfigured, Platform Links (OAuth server)
enabled, setup complete, APP_ENV=dev.

Summary of findings

| # | Title | Severity | Status |
|---|---|---|---|
| F1 | Unauthenticated inbound-email webhook impersonates any registered user and returns their memories/RAG | Critical | Confirmed (live) |
| F2 | Widget visitor attaches session to any chat of the widget owner and reads it via /history | Critical | Confirmed (live) |
| F3 | Widget visitor attaches and downloads any file of the widget owner via /messages/stream | Critical | Confirmed (live) |
| F4 | Widget visitor uploads are vectorised into a RAG group shared by all visitors | Medium | Confirmed (live) |
| F5 | WhatsApp webhook POST has no Meta signature check; sender forgery and phone-verification hijack | High | Confirmed by trace (not runnable locally) |
| F6 | Connector / MCP OAuth callbacks trust a state that is not bound to the browser (account-linking CSRF) | Medium | Suspected |
| F7 | `/api/test/*` public in every environment; `/api/test/embed` calls the real embedding provider | Low | Confirmed (live) |
| F8 | WhatsApp verify GET accepts an empty verify token; non-constant-time compare; not module-gated | Low | Confirmed (live) |

---

## 1. Entry points enumerated

- All 78 PUBLIC_ACCESS rules in `backend/config/packages/security.yaml`, joined against
  `routes.json` (table below).
- Routes outside `^/api`, `^/v1`, `^/mcp`, `^/oauth`, `^/internal` (main firewall, no catch-all):
  `/.well-known/*` (6), `/_error/{code}.{_format}`, `/shared/{...}`, `/up/{path}`, `/widget.php`.
- Webhooks: `/api/v1/webhooks/{email,whatsapp,telegram/{botKey},saved-tasks/{token}}`,
  `/api/v1/stripe/webhook`, `/api/v1/iap/{apple,google}/notifications`, `/internal/voice/*`,
  WOPI (`wopi` firewall, security off, token in query).
- OAuth: `/oauth/*` (server), `/api/v1/connections/{m365,dropbox}/callback`,
  `/api/v1/mcp-servers/oauth/callback`, social login callbacks, `/mcp`.
- Guest: `/api/v1/guest/*`. Widget: `/api/v1/widget/{id}/*`, `/api/v1/messages/stream[/attach]`.
- Realtime: `/api/v1/realtime/subscribe`, `/api/v1/realtime/widget/{w}/sessions/{s}/token`.
- Setup: `/api/v1/setup/{state,admin}` and `SetupLockdownSubscriber`.

### 1.1 PUBLIC_ACCESS rule table (rule -> matched routes -> controller guard -> verdict)

"Regex" column: A = anchored with `$`; P = prefix-only (unanchored tail). No prefix-only rule
matches an unintended route in this build; all P rules are listed again under Hardening
because a future sibling route (for example `/api/v1/auth/login-as`, `/api/test2`,
`/api/v1/guest-admin`) would become public silently.

| Line | Rule | Regex | Matched routes | Controller guard | Verdict |
|---|---|---|---|---|---|
| 78 | `^/internal/voice` | P | 5 POST routes, `InternalVoiceController` | HMAC over ts + body, secret >= 32 chars, +-60 s, `hash_equals` (`src/Service/Voice/VoiceLiveInternalAuth.php`) | Sound; 60 s replay window (no nonce) |
| 84 | `^/v1/free/keys$` POST | A | none in this build | - | Dead rule |
| 93 | `^/\.well-known/oauth-protected-resource` | P | 2 GET, `McpController::protectedResourceMetadata` | static metadata | Sound |
| 94 | `^/\.well-known/synaplan-federation` | P | `FederationWellKnownController::show` | module gate, 404 locally | Sound (not exercised) |
| 95 | `^/\.well-known/jwks\.json$` | A | `CloudWellKnownController::keys` | public keys only | Sound |
| 96 | `^/\.well-known/synaplan-configuration$` | A | `CloudWellKnownController::configuration` | public metadata | Sound |
| 97 | `^/\.well-known/oauth-authorization-server$` | A | `OAuthServerController::metadata` | public metadata | Sound |
| 100 | `^/oauth/` | P | authorize, par, token, revoke, register (+ RFC 7592 read/update/delete/lifecycle) | `PlatformLinksConfig::isEnabled`; PAR/token/revoke need `private_key_jwt` (401 `invalid_client` observed); registration management needs client assertion | Partly reviewed; see Coverage gaps |
| 101 | `^/mcp$` | A | `McpController::endpoint` | `mcp` firewall authenticator; anonymous POST -> 401 | Sound at the edge; tool layer not reviewed |
| 105 | `^/api/doc` | P | `/api/doc`, `/api/doc.json`, `/api/docs.{_format}` | none | Info exposure by design (2.7 MB spec); Hardening |
| 108 | `^/api/v1/config/runtime` | P | `ConfigController::getRuntimeConfig` | none | Public config; not deep-reviewed |
| 109 | `^/api/v1/config/memory-service/check` | P | `ConfigController::checkMemoryService` | none | Not deep-reviewed |
| 111 | `^/api/v1/config/capabilities` | P | `ConfigController::getCapabilities` | none | Not deep-reviewed |
| 114 | `^/api/v1/news/` | P | `NewsController::image`, `landing` | host allow-list `MarketingNewsFeedService::isAllowedImageUrl` (L85-135) | Sound against SSRF; SVG and redirects under Hardening |
| 118 | `^/api/v1/auth/human-check$` GET | A | `HumanCheckPageController::page` | static page | Not deep-reviewed |
| 119 | `^/api/v1/auth/login` | P | POST `AuthController::login` | credentials | Auth area (other reviewer) |
| 120 | `^/api/v1/auth/register` | P | POST `AuthController::register` | - | Auth area |
| 121 | `^/api/v1/auth/verify-email` | P | POST `AuthController::verifyEmail` | token | Auth area |
| 122 | `^/api/v1/auth/forgot-password` | P | POST `AuthController::forgotPassword` | - | Auth area |
| 123 | `^/api/v1/auth/reset-password` | P | POST `AuthController::resetPassword` | token | Auth area |
| 124 | `^/api/v1/auth/resend-verification` | P | POST `AuthController::resendVerification` | - | Auth area |
| 126 | `^/api/v1/checkout/start$` POST | A | `CheckoutStartController::start` | flag: 404 `PAY_FIRST_OFF` locally | Not exercised (flag off) |
| 127 | `^/api/v1/checkout/claim$` POST | A | `CheckoutClaimController::claim` | flag | Not exercised |
| 128 | `^/api/v1/auth/email-link(/consume)?$` POST | A | `EmailLinkController::request`, `consume` | one-time code | Auth area |
| 129 | `^/api/v1/auth/code-signup(/consume)?$` POST | A | `CodeSignupController::request`, `consume` | one-time code | Auth area |
| 134 | `^/api/v1/auth/refresh` | P | `AuthController::refresh` | refresh cookie | Auth area |
| 136 | `^/api/v1/auth/native/exchange` | P | `AuthController::nativeExchange` | one-time code | Auth area |
| 147 | `^/api/health` | P | `/api/health`, `/api/health/ready` (`/probe` caught by the earlier L144 rule) | none | Exposes provider health; Hardening |
| 148 | `^/api/test` | P | `TestMessageController::{testChat,testFlow,testImage}`, `TestController::testEmbed` | none, no env gate | F7 |
| 151 | `^/api/v1/oidc/discovery` | P | `OidcController::discovery` | none | Public metadata |
| 154 | `^/api/v1/auth/providers` | P | `AuthProvidersController::getProviders` | none | Public metadata |
| 155 | `^/api/v1/auth/google/login` | P | `GoogleAuthController::login` | - | Auth area |
| 156 | `^/api/v1/auth/google/callback` | P | `GoogleAuthController::callback` | provider state + code | Auth area |
| 157 | `^/api/v1/auth/github/login` | P | `GitHubAuthController::login` | - | Auth area |
| 158 | `^/api/v1/auth/github/callback` | P | `GitHubAuthController::callback` | provider state + code | Auth area |
| 159 | `^/api/v1/auth/keycloak/login` | P | `KeycloakAuthController::login` | - | Auth area |
| 160 | `^/api/v1/auth/keycloak/callback` | P | `KeycloakAuthController::callback` | provider state + code | Auth area |
| 161 | `^/api/v1/auth/apple/login` | P | `AppleAuthController::login` | - | Auth area |
| 162 | `^/api/v1/auth/apple/callback` | P | GET,POST `AppleAuthController::callback` | provider state + code | Auth area |
| 163 | `^/api/v1/auth/apple/native` | P | `AppleAuthController::native` | identity token | Auth area |
| 169 | `^/api/v1/connections/m365/callback` | P | `MicrosoftConnectionController::callback` | HMAC state only | F6 |
| 170 | `^/api/v1/connections/dropbox/callback` | P | `DropboxConnectionController::callback` | HMAC state only | F6 |
| 171 | `^/api/v1/mcp-servers/oauth/callback` | P | `McpOAuthController::callback` | HMAC state only | F6 |
| 178 | `^/api/v1/desktop/pair$` | A | `DesktopController::pair` | flag guard; per-IP limiter (L662-672); 8-char code from 30-symbol alphabet via `random_int`, 600 s TTL, consumed on use (`PairingCodeService` L28-34, L89+) | Sound |
| 183 | `^/api/v1/platform-links/instances/[^/]+/public$` | A | `PlatformInstanceController::public` | - | Not reviewed (404 for unknown id) |
| 188 | `^/api/v1/federation/invites/` | P | `FederationInviteController::preview` | module gate, 404 locally | Not exercised |
| 189 | `^/api/v1/federation/connect$` POST | A | `FederationConnectController::connect` | module gate, 404 locally | Not exercised |
| 192 | `^/api/v1/chats/shared/` | P | `ChatController::getShared`, `sharedRagChunk` | `findPublicByShareToken` (L964-993); returns all messages + files of a shared chat | By design; token entropy not checked |
| 195 | `^/api/v1/files/uploads/` | P | `StaticUploadController::serve` | traversal guard (`UploadRequestPath`, `FileHelper::resolvePathNfs` L311-328); anonymous only for `tts_*`, `ai_i2vsrc_*`, outbound-media name pattern, `og/`, `widget-icons/`; else owner / shared-chat check (L55-208) | Sound; unguessable-path reliance under Hardening |
| 201 | `^/api/v1/files/\d+/download$` GET/HEAD | A | `FileController::downloadFile` | cookie user or media token, then `isFileAccessibleByUser` (L602-624); anonymous -> 401 observed | Sound |
| 204 | `^/api/v1/user/[^/]+/plugins/[^/]+/assets/` (no method restriction) | P | Synaform assets, `PluginAssetController::serveAsset` (GET only) | `realpath` containment (`PluginAssetResponder`) | Sound; add methods |
| 205 | `^/api/v1/plugins/[a-z0-9_-]+/assets/` GET/HEAD | P | `PluginAssetController::serveBundledAsset` | same | Sound |
| 208 | `^/api/v1/plugins/synascriber/public/transcriber/` | P | `TranscriberController::bind`, `audio`, `events`, `preview` | per-session secret | Not reviewed |
| 212 | `^/api/v1/plugins/synascriber/jitsi/silent$` GET | A | `JitsiController::silent` | static | Not reviewed |
| 215 | `^/api/v1/guest/` | P | 7 routes, `GuestChatController` | server UUID session; messages and files bound to the session chat | Sound |
| 218 | `^/api/v1/widget/[^/]+/config` | P | `WidgetPublicController::config` | public by design | Sound |
| 219 | `^/api/v1/widget/[^/]+/history` | P | `WidgetPublicController::history` (L773+) | session's chat must belong to widget owner | F2 (session can be pointed at any owner chat) |
| 220 | `^/api/v1/widget/[^/]+/message` | P | `WidgetPublicController::message` | `WidgetTurnGate` + `WidgetDomainGuard`; client `chatId` | F2 |
| 221 | `^/api/v1/widget/[^/]+/upload` | P | `WidgetPublicController::upload` (L443-697) | allowFileUpload, session | F4 |
| 222 | `^/api/v1/widget/[^/]+/typing` | P | no-op | - | Sound |
| 223 | `^/api/v1/widget/[^/]+/human-handoff` | P | `WidgetPublicController::humanHandoff` | - | Not reviewed |
| 224 | `^/api/v1/widget/[^/]+/files/[^/]+/download` | P | `downloadWidgetFile` (L946-1040) | owner file + `isFileInChat(session chat)` | F3 (precondition forgeable through the stream path) |
| 225 | `^/api/v1/messages/stream` | P | `StreamController::streamMessage` (GET, POST), `StreamAttachController::attach` | `StreamCallerResolver` L50-125: widget headers -> acts as widget owner | F3 |
| 226 | `^/api` OPTIONS | P | no route (CORS preflight) | - | Sound |
| 236 | `^/api/v1/realtime/widget/[^/]+/sessions/[^/]+/token` | P | `RealtimeTokenController::issueWidgetToken` | `WidgetVisitorAccessChecker` (session exists + origin), per-IP limiter | Sound |
| 237 | `^/api/v1/realtime/subscribe` | P | `issueSubscriptionToken` | channel authorizers in `src/Realtime/Authorizer/*` | Sound |
| 240 | `^/api/v1/marketeer/public/` GET/HEAD | P | none in this build | - | Dead rule |
| 246 | `^/widget\.php` | P | `WidgetLegacyController::legacyWidget` | returns a JS stub | Not deep-reviewed |
| 249 | `^/api/v1/webhooks/email` | P | `WebhookController::email` | none | F1 |
| 250 | `^/api/v1/webhooks/whatsapp` | P | `whatsapp` (POST), `whatsappVerify` (GET) | POST: module gate only; GET: verify token | F5, F8 |
| 251 | `^/api/v1/webhooks/telegram/` | P | `TelegramWebhookController::receive` | botKey + secret-header sha256 `hash_equals`, `update_id` dedupe (`TelegramWebhookAcceptor`) | Sound |
| 252 | `^/api/v1/webhooks/saved-tasks/` | P | `SavedTaskWebhookController::run` | token lookup, optional `sha256=` HMAC with `hash_equals`, per-task limiter (`SavedTaskWebhookIngress`) | Sound; no timestamp in HMAC |
| 255 | `^/api/v1/stripe/webhook` | P | `StripeWebhookController::handleWebhook` | limiter, `\Stripe\Webhook::constructEvent`, event-id idempotency (L66-150) | Sound |
| 261 | `^/api/v1/iap/apple/notifications` | P | `MobilePurchaseController::appleNotifications` | Apple JWS verifier (`MobilePurchaseService` L120-184) | Sound at controller level; verifier not read |
| 262 | `^/api/v1/iap/google/notifications` | P | `MobilePurchaseController::googleNotifications` | Google server-side re-query | Sound at controller level; verifier not read |
| 265 | `^/api/v1/subscription/plans` | P | `SubscriptionController::getPlans` | none | Exposes Stripe price ids; Hardening |
| 274 | `^/api/v1/setup/state$` | A | `SetupController::state` | none | Sound |
| 275 | `^/api/v1/setup/admin$` | A | `SetupController::createFirstAdmin` | limiter, `isSetupRequired`, lock, recount (L171-218); 409 after setup observed | Sound |

### 1.2 Routes outside the guarded prefixes

| Route | Controller | Exposure |
|---|---|---|
| `/.well-known/*` (6) | see L93-97 | Public metadata and public keys only |
| `/_error/{code}.{_format}` | framework `error_controller::preview` | Dev-only error preview (500 page observed; APP_ENV=dev) |
| `/shared/{token}[/{slug}]` | `SharedChatPageController::servePage` | Server-rendered shared chat page; NOT reviewed (453 lines) - Coverage gap |
| `/up/{path}` | `FileServeController::serve` | message.filePath lookup; public + not expired, or owner by cookie / media token. Sound |
| `/widget.php` | `WidgetLegacyController::legacyWidget` | JS stub |

---

## 2. Findings

### F1. Missing authentication on inbound-email webhook: impersonation of any registered user and disclosure of their memories

- Severity: Critical. Status: Confirmed (reproduced live).
- Where:
  - `backend/config/packages/security.yaml` L249 (`^/api/v1/webhooks/email` PUBLIC_ACCESS)
  - `backend/src/Controller/WebhookController.php` L95-108 (`email()`, no credential check; the OpenAPI text claims "Authentication via API Key required")
  - `backend/src/Service/Email/Turn/InboundEmailIntake.php` L44-101 (payload validation, idempotency, `findOrCreateUserFromEmail`, rate limit; no caller authentication)
  - `backend/src/Service/Email/EmailChatService.php` L47-119 (`findOneBy(['mail' => $fromEmail])` returns the registered account)
  - `backend/src/Service/Email/Turn/EmailConversation.php` L30-73 (message stored in the user's chat)
  - `backend/src/Service/Email/Turn/EmailReply.php` L42-79 (HTTP response carries `response.text` and `response.metadata` incl. memories, docs, rag_sources)
- What: the endpoint treats the JSON `from` field as proof of identity. Any anonymous caller can run a full chat turn as any registered user, and the answer plus retrieval metadata are returned in the HTTP response. The only legitimate caller is the mail poller (`backend/src/Command/ProcessEmailsCommand.php` L59). Done right elsewhere: Telegram (`TelegramWebhookAcceptor`, secret header + `hash_equals`), Stripe (`constructEvent`), voice sidecar (`VoiceLiveInternalAuth`).
- Preconditions: none. Attacker needs a victim's email address. No flag.
- Reproduction (anonymous, no cookies):
  ```
  curl -s -X POST http://localhost:8000/api/v1/webhooks/email -H 'Content-Type: application/json' \
    -d '{"from":"audit-pub-a@example.com","to":"smart@synaplan.net","subject":"audit poc 3",
         "body":"Tell me my secret project codename please.","message_id":"audit-pub-poc-3"}'
  ```
  Observed: HTTP 200, `user_info {is_anonymous:false, rate_limit_level:"NEW"}`, and
  `response.metadata.memories` contained my test user's private memory verbatim
  (canary `AUDIT-PUB-CANARY-7731`, score 0.77). The turn was stored in that user's chat 4698
  and a "Re: audit poc" mail was sent to the victim address (seen in Mailhog).
- Impact: read any user's stored memories and RAG document excerpts by asking targeted questions; inject messages into their history; spend their quota / paid model budget; make the instance send mail to arbitrary addresses (replies, rejection and limit notices) for spam or reputation damage; create unlimited ANONYMOUS user rows for unknown senders; trigger server-side fetches of attachment URLs (SSRF guard not verified).
- CVSS 3.1: AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:L = 9.4. CWE-306, CWE-290.
- Fix:
  1. Require a shared secret: `X-Synaplan-Signature: sha256=HMAC(secret, ts + "\n" + body)` with a +-60 s timestamp, verified with `hash_equals`, reusing the `VoiceLiveInternalAuth` shape. Fail closed (404/401) when the secret is unset. `ProcessEmailsCommand` signs its request, or better, calls `InboundEmailIntake` in-process and the HTTP route is removed.
  2. Never return `response.text` / `metadata` to the HTTP caller; return `{success, message_id}` only.
  3. Independently, do not map `from` to a registered account unless the mail was received by the poller with passing SPF/DKIM/DMARC.
  - Test: functional test posting without / with wrong / with stale signature expects 401 and no `Message` row; a second test asserts the success body has no `response` key.
- Detection: today the request logs as an ordinary inbound email turn, indistinguishable from the poller. It would not be noticed. Proposed: log and alert on any request to this route whose source is not the poller, and on signature failures; metric for email turns per `from` per hour.
- Related occurrences: `findOrCreateUserFromPhone` (same file L125+) is the WhatsApp twin, see F5.

### F2. Broken access control in widget chat binding: visitor reads any chat of the widget owner

- Severity: Critical. Status: Confirmed (reproduced live).
- Where:
  - `backend/src/Service/Widget/Turn/WidgetTurnGate.php` (`admit`: reads client `chatId`)
  - `backend/src/Service/Widget/Turn/WidgetConversation.php` `chat()` / `ownedChat()`: `ownedChat($turn, $turn->session->getChatId()) ?? ownedChat($turn, $turn->chatId)`; `ownedChat` checks only `$chat->getUserId() === $turn->owner->getId()`, then `sessions->attachChat($turn->session, $chat, false)`
  - `backend/src/Controller/WidgetPublicController.php` L773+ (`history()` returns the last 50 messages of the session's chat)
  - same root cause on the stream path: `backend/src/Service/Chat/Stream/StreamTurnPreparer.php` L131-153 (`loadChat`: widget callers only get the `chat.userId == caller.user` check)
- What: widget turns run as the widget owner, so "chat belongs to the user" is true for every private chat of the owner. A fresh visitor session supplies an arbitrary `chatId` and gets bound to it. Done right in the same file for guests (`StreamTurnPreparer` L143-150 binds a guest to its session chat) and for files on the widget message path (`attachFiles` checks `getUserSessionId() !== session id`).
- Preconditions: any active widget of the victim (widget ids are public, embedded in customer pages). Chat ids are sequential integers. No flag. Domain allow-list does not help: `WidgetDomainGuard` trusts the client `x-widget-host` header.
- Reproduction (anonymous; W = my test widget, 4698 = my test user's private email-channel chat):
  ```
  curl -s -X POST http://localhost:8000/api/v1/widget/$W/message -H 'Content-Type: application/json' \
    -d '{"sessionId":"<new id>","text":"hello from anonymous visitor","chatId":4698}'     -> 200 SSE
  curl -s "http://localhost:8000/api/v1/widget/$W/history?sessionId=<new id>"
  ```
  Observed: history returned `chatId: 4698` with 8 messages, including the owner's private messages from F1's email channel; the visitor's message was appended to that chat.
- Impact: any anonymous visitor can enumerate chat ids and read (50 newest messages per chat, repeatable with new sessions) and write to every chat of any account that has published a widget, typically a business / admin account.
- CVSS 3.1: AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:L/A:N = 8.2 (rated Critical per guide: unauthenticated cross-tenant read at scale). CWE-639, CWE-862.
- Fix: for widget callers ignore the client `chatId` unless it equals `session->getChatId()`:
  ```php
  // WidgetConversation::chat()
  $chat = $this->ownedChat($turn, $turn->session->getChatId());   // never $turn->chatId
  ```
  and in `StreamTurnPreparer::loadChat` apply the guest rule (L143-150) to widget callers too. Additionally require the chat to be a widget chat of that widget.
  - Test: widget functional test: owner has private chat X; visitor posts with `chatId: X`; assert session chat != X and `/history` does not contain X's messages. Mirror for `/messages/stream`.
- Detection: nothing today; requests look like normal widget traffic. Proposed: warn-level log when a widget request carries a `chatId` different from the session's, alert on repeats per IP.
- Related occurrences: `StreamTurnPreparer` L131-153 (widget branch), F3 (same "acts as owner" root cause for files).

### F3. Broken access control on the stream path: widget visitor attaches and downloads any file of the widget owner

- Severity: Critical. Status: Confirmed (reproduced live).
- Where:
  - `backend/src/Service/Chat/Stream/StreamCallerResolver.php` L50-125 (anonymous + `X-Widget-Id` / `X-Widget-Session` -> caller user = widget owner; no `WidgetDomainGuard` call)
  - `backend/src/Service/Chat/Stream/StreamRequestResolver.php` L74-141 (`fileIds`, `modelId`, `webSearch`, `agentId` accepted from caller)
  - `backend/src/Service/Chat/Stream/StreamTurnPreparer.php` L254-272 (`attachFiles`: `$file->getUserId() === $turn->caller->user->getId()`, which is the owner for a widget caller)
  - `backend/src/Controller/WidgetPublicController.php` L946-1040 (`downloadWidgetFile`: `isOwnerFile` + `isFileInChat(session chat)`)
- What: `/api/v1/messages/stream` accepts widget visitors and evaluates file ownership against the widget owner. Attaching an owner file to the visitor's own chat satisfies the download guard on the widget endpoint. Done right on the widget message endpoint (`WidgetConversation::attachFiles` binds files to the session).
- Preconditions: active widget of the victim; file ids are sequential. No flag.
- Reproduction (anonymous; 360 = private file of my test user, 4704 = the visitor session's own chat):
  ```
  curl -s "http://localhost:8000/api/v1/widget/$W/files/360/download?sessionId=$S"      -> 403 "File not associated with this chat"
  curl -s -X POST http://localhost:8000/api/v1/messages/stream -H "X-Widget-Id: $W" -H "X-Widget-Session: $S" \
    -H 'Content-Type: application/json' -d '{"message":"x","chatId":4704,"fileIds":"360","trackId":1}'   -> 200 SSE
  curl -s "http://localhost:8000/api/v1/widget/$W/files/360/download?sessionId=$S"      -> 200, body contains AUDIT-PUB-OWNERFILE-CANARY-9902
  ```
  (message text and trackId value are illustrative; the load-bearing fields are the two headers, `chatId` = session chat, and `fileIds`.)
- Impact: anonymous download of every uploaded / generated file of any widget-publishing account by iterating ids; file text is also placed in the LLM context. The same path lets visitors bypass the widget's domain allow-list and `allowFileUpload`, and choose `modelId` / `webSearch` / `agentId` on the owner's budget.
- CVSS 3.1: AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:L/A:L = 8.6 (Critical per guide). CWE-639, CWE-863.
- Fix: in `StreamTurnPreparer::attachFiles`, for widget callers require `file.userSessionId === widget session id` (same rule as `WidgetConversation::attachFiles`); in `StreamCallerResolver` run `WidgetDomainGuard` and the widget's own gates, and in `StreamRequestResolver` drop `modelId`, `agentId`, `webSearch`, `ragGroupKey` for widget and guest callers. Simplest robust option: do not resolve widget callers on `/messages/stream` at all if the widget UI only uses `/widget/{id}/message`.
  - Test: stream functional test as widget visitor with an owner file id; assert no file link row and download stays 403.
- Detection: none today. Proposed: log widget-caller stream requests that carry `fileIds` not bound to the session; alert on 403 -> 200 transitions per session on the download route.
- Related occurrences:
  - Suspected, not tested: on this path no `WIDGET:<id>` `rag_group_key` is set for widget callers (L385-388 only ignores the caller's key), so retrieval may run over the owner's whole knowledge base. Missing step: a widget stream turn against an owner with indexed private documents and a real embedding model.
  - F2 stream-path chat binding.

### F4. Widget visitor uploads are indexed into a RAG group shared by all visitors of the widget

- Severity: Medium. Status: Confirmed (reproduced live).
- Where: `backend/src/Controller/WidgetPublicController.php` L584-697 (`processWidgetFile`: `vectorizeAndStore` under the owner id with group key `WIDGET:{widgetId}`); `backend/src/Service/Widget/Turn/WidgetReply.php` L93-117 (`rag_group_key => 'WIDGET:<id>'`, no session filter).
- What: a document uploaded by visitor A becomes retrieval context for visitor B. File rows themselves are session-bound (userId 0 + userSessionId), which is right; the vector index is not.
- Preconditions: widget with `allowFileUpload = true`.
- Reproduction: visitor A (session sidA) uploaded `visA.txt` via `POST /api/v1/widget/$W/upload` (multipart, `sessionId` + file) -> file id 359. Visitor B (new session sidB) sent `POST /api/v1/widget/$W/message {"sessionId":"<sidB>","text":"..."}`; the SSE response carried `ragSources: [{chunkId:"doc_4919_359_0", fileId:359, groupKey:"WIDGET:wdg_...", score:1}]`. Score 1 is an artefact of the test embedding provider; with a real model B needs a semantically related question.
- Impact: cross-visitor disclosure of uploaded documents (contracts, IDs, invoices in a support widget); also lets a visitor poison the widget's knowledge base for everyone (stored prompt injection).
- CVSS 3.1: AV:N/AC:H/PR:N/UI:N/S:U/C:H/I:L/A:N = 6.5. CWE-668, CWE-200.
- Fix: do not write visitor uploads into the shared `WIDGET:<id>` group. Use `WIDGET:<id>:S:<sessionId>` and query both the curated widget group and the session group, or skip vectorisation and pass the extracted text only in that session's turn.
  - Test: two sessions on one widget; A uploads; B's reply must not list A's file in `ragSources`.
- Detection: none. Proposed: log `ragSources.fileId` whose `userSessionId` differs from the requesting session.
- Related occurrences: none found for guest chat (guests have no shared group).

### F5. WhatsApp webhook has no Meta signature verification: sender forgery and phone-verification hijack

- Severity: High. Status: Confirmed by code trace; not reproducible locally (module unconfigured, POST returns 404 `feature_not_configured`). Missing step: `WHATSAPP_ENABLED` plus an access token.
- Where:
  - `backend/src/Controller/WebhookController.php` L174-246 (`whatsapp()`; no `X-Hub-Signature-256` handling anywhere in `backend/src`)
  - `backend/src/Module/Channel/WhatsAppModule.php` L99-108 (env names: `WHATSAPP_ENABLED`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, `WHATSAPP_DISPLAY_NUMBERS`, `WHATSAPP_GRAPH_API_BASE_URL`; there is no app-secret setting at all)
  - `backend/src/Service/WhatsApp/Turn/WhatsAppIntake.php` L74-121 (`admit`), L254-331 (`handleVerificationCode` sets `phone_number` / `phone_verified_at` from the forged `from`)
  - `backend/src/Service/Email/EmailChatService.php` L125+ (`findOrCreateUserFromPhone`: latest verification wins)
  - `backend/src/Controller/PhoneVerificationController.php` L72-204 (request; L192-197 logs the verification code at info level)
  - `backend/src/Service/WhatsApp/WhatsAppService.php` L682-718 (attacker `mediaId` interpolated into the Graph URL path)
- What: once WhatsApp is enabled, anyone can POST a Meta-shaped payload with any `from`. Done right: Telegram and Stripe webhooks.
- Preconditions: WhatsApp module configured (production deployments that use the channel). For the hijack: one ordinary attacker account.
- Attack path: (a) forge messages from a verified user's number: turns run as that user, history injected, quota burned, replies sent to the victim's phone from the business number. (b) attacker requests phone verification for the victim's number in their own account, reads the code from their own UI, then forges the inbound code message from the victim's number; the number is now verified on the attacker's account and, because the newest verification wins, the victim's future real WhatsApp messages land in the attacker's account.
- Impact: impersonation, message interception, outbound spam via the business number.
- CVSS 3.1: AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:H/A:L = 8.6. CWE-345, CWE-290.
- Fix: add `WHATSAPP_APP_SECRET`; verify `X-Hub-Signature-256 = sha256=HMAC(app_secret, raw body)` with `hash_equals` before parsing; module reports "not configured" without it. Dedupe on message id. Validate `mediaId` as `^\d+$`.
  - Test: unsigned / wrong-signature POST -> 401 and no `Message` row; valid signature -> 200.
- Detection: forged posts look like normal webhook traffic today. Proposed: count signature failures; alert on phone numbers re-verified to a different account.
- Related occurrences: F1 (email twin), F8.

### F6. Account-linking CSRF: connector / MCP OAuth callbacks trust a state that is not bound to the initiating browser

- Severity: Medium. Status: Suspected (code trace only; needs a real provider tenant to complete a code exchange).
- Where: `backend/src/Service/OAuth/OAuthStateService.php` (HMAC state: nonce, provider, owner_id, created_at; 600 s TTL; not stored, not single-use, no cookie binding); `backend/src/Service/OAuth/OAuthConsentService.php` L73-108 (`completeConsent` takes `owner_id` solely from the state); `backend/src/Controller/MicrosoftConnectionController.php` L132-163; `backend/src/Controller/DropboxConnectionController.php` (callback, same pattern, not line-read); `backend/src/Controller/McpOAuthController.php` L142-172 and `McpOAuthConsentService.php` L143. The three callbacks are PUBLIC_ACCESS (security.yaml L169-171), so no session is required either.
- What: an attacker starts a connect flow in their own account, takes the provider authorization URL (with their state) and gets a victim to open it and consent. The callback stores the victim's provider tokens on the attacker's Synaplan account, giving the attacker the victim's M365 / Dropbox / MCP data through connector tools. Existing mitigations: state HMAC and 10-minute TTL (bounds the window), PKCE verifier held server-side (does not help, the attacker's server-side record is the one used).
- Preconditions: attacker account; victim interaction (click + consent on the real provider page, where the app name is the legitimate Synaplan app); provider configured.
- CVSS 3.1: AV:N/AC:H/PR:L/UI:R/S:U/C:H/I:L/A:N = 5.9. CWE-352, CWE-384.
- Fix: at flow start set a random `oauth_bind` cookie (HttpOnly, Secure, SameSite=Lax) and put its hash in the state; at callback require the cookie to match; additionally require an authenticated session whose user id equals `owner_id`; make the nonce single-use (the PKCE record already keyed by nonce can serve as the one-time marker).
  - Test: callback with a valid state but without the cookie / with another user's session -> 400 and no token row.
- Detection: none. Proposed: log callback completions where the session user is absent or differs from `owner_id`.
- Related occurrences: all three callbacks above. Social login callbacks belong to the auth area and were not reviewed here.

### F7. Test endpoints public in every environment; `/api/test/embed` invokes the real embedding provider

- Severity: Low. Status: Confirmed (live).
- Where: `backend/config/packages/security.yaml` L148; `backend/src/Controller/TestController.php` L25-37; `backend/src/Controller/TestMessageController.php`.
- What / repro: `curl -s http://localhost:8000/api/test/embed` -> 200 with a 1024-dim vector; calls `$aiFacade->embed()` with the default embedding provider, no env gate, no rate limit. `POST /api/test/chat` forces provider `test`; `image` and `flow` are static.
- Impact: unauthenticated cost amplification against a paid embedding provider; confirms provider configuration.
- CVSS 3.1: AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:L = 5.3 (rated Low: cost only). CWE-489.
- Fix: register these controllers only `when@dev` / `when@test`, and remove the access_control rule in prod. Test: prod-kernel router test asserts `/api/test/*` does not exist.
- Detection: access-log count on `/api/test/` in production should be zero; alert otherwise.

### F8. WhatsApp verify handshake accepts an empty token; non-constant-time compare; route not module-gated

- Severity: Low. Status: Confirmed (live).
- Where: `backend/src/Controller/WebhookController.php` L118-138 (`$token === $this->whatsappWebhookVerifyToken`); `WhatsAppModule.php` L99-108 (only the POST route is gated).
- Repro: `curl -s "http://localhost:8000/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=abc"` -> 200 `abc` (token unset locally).
- Impact: the handshake itself grants nothing; it is a reflected-text oracle and shows the fail-open pattern that F5 shares.
- CVSS 3.1: AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:L/A:N = 5.3 nominal; rated Low. CWE-1188, CWE-208.
- Fix: reject when the configured token is empty; `hash_equals`; gate the GET route with the module; restrict the echoed challenge to digits.

---

## 3. Ruled out (examined and sound)

- Stripe webhook: limiter + `\Stripe\Webhook::constructEvent` + event-id idempotency, `backend/src/Controller/StripeWebhookController.php` L66-150. Unsigned POST -> 400 observed.
- Telegram webhook: botKey + sha256 secret header with `hash_equals`, `update_id` dedupe, `backend/src/Service/Telegram/TelegramWebhookAcceptor.php`.
- Saved-task webhook: token lookup, optional `sha256=` HMAC with `hash_equals`, per-task limiter, `backend/src/Service/SavedTask/SavedTaskWebhookIngress.php`.
- Voice sidecar: secret >= 32 chars, +-60 s timestamp, HMAC over ts + body, `hash_equals`, `backend/src/Service/Voice/VoiceLiveInternalAuth.php`. Redeem without signature -> 401 observed.
- WOPI: token carries fid + uid + purpose + 600 s expiry, HMAC with `hash_equals` (`backend/src/Service/Office/Wopi/WopiTokenService.php`); route id must equal token fid (`WopiController.php` L210-220); owner re-checked per call (`WopiDocumentService.php` L50, L201-207).
- IAP notifications: Apple JWS verification and Google server-side re-query before any state change (`backend/src/Service/Iap/MobilePurchaseService.php` L120-184). Verifier internals not read.
- Guest chat: server-generated UUID session; messages and files only via the session's chat (`backend/src/Controller/GuestChatController.php`; `StreamTurnPreparer.php` L143-150).
- Stream attach: owner key derived from user / widget session / guest session, `backend/src/Controller/StreamAttachController.php` L121-140, L297-328.
- Realtime: `UserChannelAuthorizer` (user id match), `WidgetOperatorsAuthorizer` (owner only), `WidgetSessionAccessGuard` (matching widgetId + sessionId, session must exist), `WidgetVisitorAccessChecker` (session exists + origin), per-IP limiters, all under `backend/src/Realtime/`. No cross-user or cross-widget subscription found.
- Media tokens: HMAC + purpose + expiry + `hash_equals`, `backend/src/Service/Media/MediaAccessTokenService.php`.
- `/api/v1/files/uploads/*` traversal and filename-pattern collision: `UploadRequestPath`, `FileHelper::resolvePathNfs` L311-328; user uploads are stored as `<name>_<ts>_<hex>.<ext>`, which does not match the anonymous outbound pattern.
- `/up/{path}`: public + unexpired or owner check, `backend/src/Controller/FileServeController.php`.
- `/api/v1/files/{id}/download`: user or media token then `isFileAccessibleByUser`, `backend/src/Controller/FileController.php` L602-624. Anonymous -> 401 observed.
- XSS through served uploads (SVG / HTML): CSP `default-src 'none'; ...; sandbox` added by `backend/src/EventSubscriber/UploadedFileSandboxSubscriber.php` L26-53.
- Plugin assets traversal: `realpath` containment in `backend/src/Service/Plugin/PluginAssetResponder.php`.
- First-admin claim after setup: limiter, `isSetupRequired`, lock, recount, `backend/src/Controller/SetupController.php` L171-218. 409 observed.
- Desktop pairing: 30^8 code space, 600 s TTL, one-time consume, per-IP limiter, same error for unknown / expired (`backend/src/Service/Desktop/PairingCodeService.php` L28-34, L89+; `DesktopController.php` L150-195, L662-672).
- Widget file attach on the widget message endpoint: session-bound (`WidgetConversation::attachFiles`).
- Module gates: federation / telegram / whatsapp POST return 404 `feature_not_configured` (`backend/src/Module/Http/ModuleGateListener.php`).
- `/api/health/probe`: protected by the earlier rule at L144 despite the broad `^/api/health` at L147.
- Access-control regexes: no rule matches an unintended route in this build (join.txt).
- OAuth server edge: `/oauth/par`, `/oauth/revoke` -> 401 `invalid_client` without `private_key_jwt`; `/oauth/token` -> `unsupported_grant_type`; `/oauth/register {}` -> 400; S256 only advertised. Internals not reviewed (see gaps).
- News image proxy SSRF: host allow-list, `MarketingNewsFeedService::fetchImage` L85-135.

## 4. Hardening ideas

- Anchor prefix-only PUBLIC_ACCESS regexes with `$` or `(/|$)` and add `methods:` (login, register, verify-email, health, test, doc, news, guest, stream, subscribe, all callbacks, L204 plugin assets). Add an architecture test that fails when a PUBLIC_ACCESS rule matches a route not on an allow-list (the join script in `pubwh/join.py` is a starting point).
- Remove dead rules L84, L240.
- Systemic root cause of F2/F3: anonymous widget callers are represented as the owner `User`. Introduce a distinct principal type so an "owner check" can never pass for a visitor.
- `SetupLockdownSubscriber` (`backend/src/EventSubscriber/SetupLockdownSubscriber.php` L44) guards only `/api`, `/mcp`, `/v1`; `/oauth/*`, `/internal/voice`, `/.well-known/*`, `/widget.php`, `/up`, `/shared` stay reachable during setup. First-run admin claim has no setup token (first network caller wins).
- `WidgetDomainGuard` trusts the client `x-widget-host` header; treat the allow-list as anti-embedding only, not as access control.
- Phone verification code logged at info level (`PhoneVerificationController.php` L192-197).
- Anonymous media patterns in `StaticUploadController` rely on unguessable paths (user id + message id + unix timestamp); prefer signed URLs.
- News image proxy: reject `image/svg+xml`, disable redirects, add `Content-Security-Policy: sandbox`.
- Saved-task HMAC lacks a timestamp (replayable); voice HMAC lacks a nonce (60 s replay).
- `/api/health` exposes provider health; `/api/v1/subscription/plans` exposes Stripe price ids; OpenAPI (2.7 MB) public. Consider gating in production.
- Email webhook OpenAPI text claims API-key auth that does not exist.
- Sequential integer ids for chats and files make F2/F3 trivially enumerable.

## 5. Coverage gaps

- OAuth server internals (`OAuthServerController` authorize / token, `src/Service/OAuth/Server/*`): redirect-URI matching, code reuse, consent screen, refresh rotation not read. Only edge behaviour probed.
- `McpController` tool layer and MCP session handling after authentication.
- `/shared/{token}` (`SharedChatPageController`, 453 lines): HTML rendering / XSS of shared chats not reviewed; share-token entropy not checked.
- Federation (invite preview, connect, well-known) and Telegram / WhatsApp live paths: modules unconfigured locally, flags not flipped.
- Checkout start / claim (`PAY_FIRST_OFF`), Apple / Google IAP verifier internals (`AppleStoreKitVerifier`, `GooglePlayVerifier`).
- Synascriber public transcriber routes, Jitsi silent page, `PlatformInstanceController::public`, widget `human-handoff`, `ConfigController` public endpoints, `HumanCheckPageController`, `WidgetLegacyController`.
- Social login callbacks, native exchange, email-link / code-signup (left to the auth reviewer).
- Dropbox callback read by pattern only. F6 not executed end to end (needs a real provider).
- F3 related RAG question (whole knowledge base on the stream path) untested.
- Email attachment URL fetch (SSRF guard) not verified. Realtime token TTL and saved-task token entropy not checked. Widget upload type validation and per-session rate limits not load-tested (no DoS rule).

## Test artefacts created (all mine, prefix audit-pub-, left in place)

- User `audit-pub-a@example.com` (id 4919), one memory (id 1795416616332601), chats 4698 / 4701 / 4704, widget `wdg_606d49e980d1532076cddd2fa2543cb6` ("audit-pub-widget"), files 359 and 360, inbound email messages `audit-pub-poc-1..3`, a few widget sessions, Mailhog messages to that address. No seeded data, flags or other reviewers' data were modified.
