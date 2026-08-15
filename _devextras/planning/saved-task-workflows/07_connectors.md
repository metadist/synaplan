# Connectors — inventory, prerequisites, and the sign-off gate

**Status:** Draft 2026-08-15. **This document gates the epic.** No Saved Task connector code starts until [§7 sign-off](#7-sign-off-gate-tick-before-any-connector-code) is ticked.

**Why this file exists:** Saved Tasks are only useful through their connections — "read my Office 365 mail", "file the result in my Nextcloud folder", "create a Jira issue". Those connections do **not** exist today in the shape the epic assumes. Building the scheduler first and discovering the connectors later is the fastest route to a half-working feature. Connections get prepared, verified and signed off **before** implementation.

---

## 1. Reality check — what exists today

Verified against the code on 2026-08-15. ❌ means **not in any repo**, regardless of what planning docs or marketing pages imply.

| Capability | Status | Evidence |
| ---------- | ------ | -------- |
| Parse PPTX / XLSX / DOCX / PDF | ✅ Shipped | `Service/File/FileProcessor.php` → `TikaClient.php` (`PUT {TIKA_URL}/tika`); extension map covers `doc/docx/xls/xlsx/ppt/pptx`; chat entry via `MessagePreProcessor::DOCUMENT_EXTENSIONS` |
| Generate DOCX / XLSX / PPTX (+ csv, txt, md, ics) | ✅ Shipped | `Service/File/DocumentGeneratorService.php` (`BINARY_FORMATS`), PhpWord / PhpSpreadsheet / `PptxRenderer`; DAG node `document_generation` |
| Mailbox IN (IMAP/POP3, **password only**) | ✅ Shipped | `Entity/InboundEmailHandler.php` → `BINBOUNDEMAILHANDLER`; AES-256-CBC password via `EncryptionService`; `imap_open` in `InboundEmailHandlerService` |
| Mailbox search inside a turn | ✅ Shipped | `email_search` capability, read-only (`OP_READONLY` / `FT_PEEK`) |
| Email OUT | ✅ Shipped | `email_me`, `InternalEmailService`; per-handler SMTP credentials in `BCONFIG` JSON |
| Calendar OUT | ⚠️ Partial | `calendar_event` writes an **`.ics` file** (`CalendarEventService`). No calendar system is written to |
| Public share link | ✅ Shipped | `POST /api/v1/files/{id}/share` → `/up/{token}` (`FileController`) |
| MCP client (read) | ✅ Shipped | `mcp_fetch`, `McpClient`, SSRF-guarded, per-topic `tool_mcp` |
| MCP server | ✅ Shipped | `POST /mcp` — `synaplan_chat`, `rag_search`, `list_prompts`, … |
| File provenance | ✅ Shipped | `BFILES.BSOURCE` / `BORIGINALNAME`; `File::SOURCES` includes `nextcloud`, `opencloud` |
| **Nextcloud integration** | ⚠️ **Inbound only** | External NC app `synaplan-nextcloud` (`OCA\SynaplanIntegration`). It **pulls**: `MediaController::save()` downloads from Synaplan via `SynaplanClient` with an **admin API key** and writes through NC's `IRootFolder` into `Synaplan/<Kind>/`. Save button in NC chat is still image/video-only (Phase A2 open) |
| **OpenCloud integration** | ⚠️ **Inbound only, read-only** | Repo `synaplan-opencloud`: OpenCloud web extension + Go backend. Reads user files through the **CS3/reva gateway** (`internal/cs3reader` — `Stat` + `InitiateFileDownload` only, **no upload path**). Handlers: summarize, translate, knowledge, assets. Auth: **RFC 8693 token exchange** (`internal/tokenexchange`) — the user's OpenCloud token is exchanged at Keycloak for a Synaplan-audience token; fallback Mode B is a shared `SYNAPLAN_API_KEY` |
| **Any write path from Synaplan into Nextcloud/OpenCloud** | ❌ **Does not exist** | No WebDAV client, no CS3 upload, no push endpoint in `synaplan/backend` |
| **Generic "send file to a destination"** | ❌ **Not built** | `DestinationProvider`, `ShareableFile`, `POST /files/{id}/send` are planning only — `release4.0/07_file-sharing-destinations.md` Phase B |
| **Outbound OAuth2 to a third party** | ❌ **Does not exist** | No token storage, no refresh, no consent flow. Synaplan's OIDC is *login* (`OidcTokenService`, `OidcBearerAuthenticator`) and its refresh token lives in a **browser cookie**, not the database |
| **Microsoft Graph / Office 365** | ❌ **Does not exist** | No Graph client, no Azure app registration |
| **Jira / Confluence** | ❌ **Does not exist** | No client, no plugin |
| Plugins in `synaplan/plugins/` | ✅ | `synaform`, `synamail`, `castingdata`, `hello_world` — **no** nextcloud/opencloud plugin lives here |

### 1.1 Four findings that change the plan

**Finding A — Both cloud integrations point the wrong way.** Nextcloud and OpenCloud each *pull* from Synaplan while a user is logged into **their** UI. A Saved Task running at 07:00 with nobody logged in has no delivery path into either. The user's own summary was exactly right: *plugins exist, but there is no standard way into the apps.* Fixing this needs the destination seam (**F4**) plus a real write client (**C10**).

**Finding B — Office 365 mailboxes cannot connect with the mailbox form we have.** Microsoft disabled Basic Authentication for Exchange Online; modern tenants require OAuth2. `InboundEmailHandler` is username + password only. **"Connect Office 365" is blocked on an OAuth2 framework (F3), not on Saved Tasks.** Any plan treating O365 mail as "just another IMAP account" is wrong.

**Finding C — Jira/Confluence writes are blocked by policy, not effort.** `docs/MULTITASK_DATA_NODES.md` states MCP is read-only in v1. "Create a Jira issue" requires the mutating-action decision (§7 row S6) before any client is written.

**Finding D — OpenCloud gives us a sovereignty-friendly auth pattern we should reuse, but it does not solve unattended runs.** Token exchange works because OpenCloud and Synaplan share a Keycloak realm, and because a *live user token* is present to exchange. A scheduled run has no user token, and Synaplan keeps the OIDC refresh token in a cookie. Acting as the user unattended therefore requires one of: storing refresh tokens server-side, a Keycloak service account with impersonation rights, or a per-user app password. **This is a real design decision (§7 row S11), not an implementation detail.**

---

## 2. Build the seams before the connectors

Ten bespoke connectors means ten CRUD forms, ten test buttons, ten status pills, ten credential formats and ten failure dialogs — in four languages. That is exactly how the UI becomes incomprehensible, which is the outcome this product cannot afford.

Build **five foundations** first; every connector afterwards is a small adapter.

| ID | Foundation | Why it must come first | Status |
| -- | ---------- | ---------------------- | ------ |
| **F1** | **Connection registry** — one `Connection` entity per user: `type`, `name`, `status`, `last_checked_at`, `scopes`, `credential_ref`. One list UI, one add/test/remove flow | Every integration today has its own table and its own screen (`BINBOUNDEMAILHANDLER`, MCP servers, Higgsfield, plugin configs). Five more multiplies the UI surface. A Saved Task must reference **one** connection id | ❌ new |
| **F2** | **Credential vault** — one encrypted secret store behind an interface (`store` / `fetch` / `rotate` / `forget`); never logged, never returned by the API (masked only). Generalises today's `EncryptionService` AES-256-CBC usage | Passwords currently live in `BPASSWORD` plus JSON blobs in `BCONFIG`. OAuth tokens need refresh, expiry and revocation, which that shape cannot carry | ❌ new |
| **F3** | **Outbound OAuth2 framework** — authorization code + PKCE, per-user tokens, refresh **in cron/worker context** (no HTTP session), scope tracking, revoke, `reauth_required` state | Blocks Microsoft 365 (mail, calendar, OneDrive/SharePoint) and Google Workspace. A run at 07:00 must refresh a token with no user present — the hard part, designed once | ❌ new |
| **F4** | **Destination seam** — `ShareableFile` DTO + `DestinationProvider` registry + `POST /api/v1/files/{id}/send` + a Saved Task action node "Save to…" | Every "put the result somewhere" requirement (Nextcloud, OpenCloud, OneDrive, email, webhook) collapses into one contract. Already designed as Phase B in `release4.0/07_file-sharing-destinations.md` — adopt it, do not redesign it | ❌ planned only |
| **F5** | **Connection health UX** — one shared component set: test-connection button, status pill (`connected` / `error` / `reauth needed` / `never tested`), last-checked time, readable error; four locales | Users must diagnose "why did my task stop" without support. Also the visible half of auto-pause | ❌ new (per-integration patterns exist) |

**Rule:** a connector PR may not introduce its own credential storage, its own status widget, or its own delivery endpoint. If it needs one, a foundation is missing — stop and build the foundation.

---

## 3. Connector inventory

Direction is from Synaplan's perspective: **IN** = data enters a Saved Task; **OUT** = a result leaves.

### Tier 0 — already shipped (wire + test, do not rebuild)

| ID | Connector | Dir | Auth | Saved Task role | Work needed |
| -- | --------- | --- | ---- | --------------- | ----------- |
| K1 | Document parsing (PPTX/XLSX/DOCX/PDF/CSV) via Tika | IN | – | File content becomes step input | Coverage for Office formats inside a *scheduled* run; document size/timeout limits |
| K2 | Document generation (DOCX/XLSX/PPTX/CSV/MD/ICS) | OUT | – | Action node produces the artifact | Expose as an authored action; assert each binary opens cleanly |
| K3 | Mailbox IMAP/POP3 (app password) | IN | Password (AES) | Trigger source + `email_search` | Migrate onto F1/F2 without breaking existing handlers |
| K4 | Email out (`email_me`, per-handler SMTP) | OUT | Password (AES) | Deliver results | Mutating → `allow_unattended` |
| K5 | Public share link | OUT | – | Cheap delivery fallback | Offer as an F4 destination |
| K6 | MCP client (read) | IN | Per-server | `mcp_fetch` step | Reference by connection id once F1 lands |
| K7 | Chat / API / WhatsApp / widget | IN/OUT | Existing | Trigger + reply | Unchanged (invariants C5/C6) |
| K8 | Nextcloud app (pull) | IN | Admin API key | User pushes a file into Synaplan from NC | Unchanged; C10 is additive (row S10) |
| K9 | OpenCloud extension (pull, read-only CS3) | IN | RFC 8693 token exchange | Summarize / translate / index an OC file | Unchanged; reuse its auth thinking for C11 |

### Tier 1 — the connectors this epic requires

Ordered by **value ÷ risk**. `PR≈` counts reviewable steps (see [`09_work_breakdown.md`](./09_work_breakdown.md)), not calendar time.

| ID | Connector | Dir | Auth | Needs | PR≈ | Recommendation |
| -- | --------- | --- | ---- | ----- | --- | -------------- |
| **C10** | **Generic WebDAV write** | OUT | App password (Basic) | F1, F2, F4 | 3–4 | **Build first.** One adapter covers Nextcloud, ownCloud and any WebDAV server. No OAuth dependency |
| **C1** | Nextcloud folder | OUT | via C10 | C10 | 0–1 | A preset of C10 (`https://host/remote.php/dav/files/{user}/`). Keep the `Synaplan/<Kind>/` layout the NC app already uses |
| **C11** | **OpenCloud / OCIS folder** | OUT | **Open — see S11** | C10 or CS3, F1–F2 | 2–4 | Strategically the most important (sovereignty stack). **Do a spike before committing**: does the target OCIS expose WebDAV with an app token (`auth-app`), or must we use CS3 upload / token exchange? Decide in the spike, not in the plan |
| **C3** | Microsoft 365 mail | IN | **OAuth2 (Graph)** | F1–F3 | 5–7 | Required for the flagship story on O365 tenants. Basic auth is dead — no shortcut exists |
| **C4** | Microsoft 365 calendar (real events) | OUT | **OAuth2 (Graph)**, mutating | F1–F3, S6 | 3–4 | The honest upgrade over `.ics`. Ship only after C3 proves the OAuth plumbing |
| **C5** | OneDrive / SharePoint folder | OUT | **OAuth2 (Graph)** | F1–F4, C3 | 2–3 | Nearly free once C3 + F4 exist |
| **C6** | Google Workspace (Gmail IN, Drive/Calendar OUT) | IN/OUT | OAuth2 | F1–F3 | 4–6 | Gmail IMAP already works with app passwords (K3). Drive/Calendar need OAuth. **Defer unless a customer asks** |
| **C7** | Jira (read issues / create issue) | IN/OUT | API token or OAuth | F1, F2; write needs S6 | 2 via MCP / 5+ native | **Prefer MCP** |
| **C8** | Confluence (read pages / create page) | IN/OUT | API token or OAuth | as C7 | 2 via MCP / 5+ | As C7 |
| **C9** | Generic outbound webhook | OUT | Shared secret + HMAC | F1 | 2 | Already scoped in Sprint 4. Covers n8n / Make / anything |

### Tier 2 — explicitly not building

| Not building | Instead |
| ------------ | ------- |
| Bespoke CRM / ticketing / wiki clients (Salesforce, HubSpot, ServiceNow, Notion, …) | MCP connection (C7/C8 pattern) or outbound webhook (C9) |
| Slack / Teams / Telegram channels | Outbound webhook, or an existing channel |
| Dropbox / Box / S3 | WebDAV where supported, else webhook. Revisit on demand |
| An n8n runtime | Interface only — [master plan §4](./00_master_plan.md#4-n8n-embed-vs-interface) |

---

## 4. Connector detail sheets

Each sheet is the minimum a developer needs before writing code. **A connector without a completed sheet is not ready to build.**

### 4.1 Sheet template (copy for every new connector)

```markdown
### C<id> — <name>
- Direction / Saved Task role:
- Auth model + exact scopes:
- Test account owner (person + tenant/instance):
- Config fields the user fills (and which are secret):
- Endpoint(s) + API version + docs link:
- Rate limits / quotas / throttling response:
- Failure modes -> user-visible message (EN key + 3 translations):
- Unattended behaviour (token refresh, reauth-required path):
- Data leaving Synaplan (what content, to where) - privacy note:
- Test plan: unit (fake client) / fixture / manual (live account) / negative:
- Rollback: how the user disconnects, and what happens to tasks using it:
```

### 4.2 C10 — Generic WebDAV write (build first)

- **Direction:** OUT. Saved Task action "Save to folder".
- **Auth:** HTTP Basic with an **app password** (Nextcloud issues these per-user under Security settings). No OAuth.
- **Config fields:** display name, base URL, username, app password *(secret)*, target folder, on-conflict behaviour (`rename` default / `overwrite`).
- **Endpoints:** `PROPFIND` (verify folder + test connection), `MKCOL` (create missing folders), `PUT` (upload). Nextcloud path shape `https://host/remote.php/dav/files/{username}/{path}`.
- **Rate limits:** none documented; enforce our own per-run cap (files per run, max bytes) and a request timeout.
- **Failure modes:** 401 → `reauth needed`; 403/404 → folder missing or no permission; 507 → quota full; TLS failure; timeout. Each maps to one plain sentence, never a bare HTTP code.
- **Unattended:** app passwords do not expire → safe for scheduled runs. A 401 sets the connection to `error` and auto-pauses dependent tasks (master plan row 12).
- **Security:** SSRF guard on the base URL (reuse the MCP client's guard); HTTPS enforced; no cross-host redirects; secret never returned by the API.
- **Privacy:** file content leaves Synaplan to a **user-chosen host** — show the destination hostname in the confirmation copy.
- **Test plan:** unit against a fake WebDAV client (PUT/MKCOL/PROPFIND and each error code); fixture-based integration; **manual against a live Nextcloud**; negative: wrong password, missing folder, oversized file, private-IP URL.
- **Rollback:** disconnecting marks dependent tasks `needs attention` and pauses them; delivered files are untouched.

### 4.3 C11 — OpenCloud / OCIS write (spike before committing)

The most strategically important destination (it is the sovereign-stack story) and the least certain mechanism. **Timebox a spike; the spike output replaces this section.**

Three candidate mechanisms, to be decided by the spike:

| Option | How | Pros | Cons / unknowns |
| ------ | --- | ---- | --------------- |
| **a) WebDAV + app token** | OCIS `auth-app` service issues app tokens; `PUT` to the OCIS WebDAV endpoint | Identical code path to C10 — near-zero extra work | Requires the `auth-app` service to be enabled in the target deployment; endpoint shape and token lifetime need verification against a live OCIS |
| **b) CS3 upload via the Go backend** | Extend `synaplan-opencloud`'s backend with an upload handler (mirror of `cs3reader`) that Synaplan calls | Uses the integration we already own; native to OCIS | Reverses the call direction — Synaplan must authenticate **to** the OC backend, which today only accepts reva tokens from the OC proxy. Needs a new inbound auth path there |
| **c) Keycloak token exchange, reversed** | Synaplan exchanges for an OpenCloud-audience token and writes as the user | Cleanest identity story; no stored file credentials | **Needs a subject token that a cron run does not have** (Finding D). Would require stored refresh tokens or a Keycloak service account with impersonation — a security decision, see S11 |

- **Spike deliverable:** a one-page result in this file recording which option works against a live OpenCloud, with the exact endpoint, auth artefact and its lifetime.
- **Non-negotiable:** whichever option wins, it plugs into **F4** as one more `DestinationProvider`. Do not let it grow a bespoke UI.
- **Note:** `File::SOURCES` already contains `opencloud`, so provenance and the round-trip story work as soon as delivery exists.

### 4.4 C3 — Microsoft 365 mail (the OAuth spike)

- **Direction:** IN. Trigger source + `email_search`.
- **Auth:** OAuth2 authorization code + PKCE (Microsoft identity platform). **Decide: Graph (`Mail.Read`) vs IMAP with XOAUTH2 (`IMAP.AccessAsUser.All`).** Recommendation: **Graph** — better throttling semantics, no `imap_open`, keeps the PHP IMAP extension out of this path.
- **App registration:** who owns the Azure app? Multi-tenant implies an admin-consent conversation (S3). Self-hosters need their **own** registration with a documented setup path; this must not become a Cloud-only feature.
- **Tokens:** access + refresh in the F2 vault; refresh must work from cron with no session (master plan §3.4).
- **Failure modes:** consent revoked, password change, conditional-access block, tenant policy, throttling (429 + `Retry-After`).
- **Privacy / sovereignty:** mail content flows Microsoft → Synaplan → the user's configured AI provider. Document it, and label it in the connection UI (S7).
- **Test plan:** unit with a fake token store + fake Graph client; token-refresh unit including expired-refresh → `reauth_required`; **manual against a real M365 test tenant**; negative: consent revoked mid-schedule.

### 4.5 C7 / C8 — Jira & Confluence via MCP (preferred path)

- **Direction:** IN now (read issues/pages); OUT only after the mutating decision (S6).
- **Auth:** whatever the chosen MCP server needs, stored as an MCP connection — **no new credential type in Synaplan**.
- **Why MCP:** no bespoke client code, reuses the shipped SSRF guard, per-topic opt-in, timeout isolation, and the existing skill-catalog note for `mcp_fetch`.
- **Blocker for writes:** needs an `mcp_action` capability (planner-invisible, authored-graph only), confirmation on interactive runs, `allow_unattended` for scheduled runs, and a per-call audit record.
- **Test plan:** the existing fixture MCP server gains a fake `create_issue`; assert confirmation is enforced and that the planner never emits the mutating capability.

### 4.6 K1 / K2 — Office formats (shipped, still need task-level tests)

- **In:** Tika extracts PPTX/XLSX/DOCX. Verify inside a *scheduled* run (no HTTP request context) and with a large deck/sheet; document size and timeout limits, and what the user sees when extraction fails or returns junk.
- **Out:** `DocumentGeneratorService` writes real OOXML. Add a test asserting each generated binary is a valid archive with the expected content type — a corrupt PPTX that only fails inside PowerPoint is a support nightmare.

---

## 5. The "way into the apps" — one contract, four destinations

This is the answer to *"we have plugins for nextcloud and opencloud, but no standard way into the apps"*.

```
Saved Task run produces an artifact
                │
                ▼
        ShareableFile DTO            (F4 — one shape for every artifact)
                │
                ▼
     DestinationProvider registry    (F4 — one interface, many adapters)
        │        │        │       │
        ▼        ▼        ▼       ▼
    WebDAV   OpenCloud  Email   Share link
    (C10 →   (C11)      (K4)    (K5)
     C1 NC)
                │
                ▼
   delivery recorded on the run + provenance on the file
```

Rules that keep this from sprawling:

1. **One endpoint** — `POST /api/v1/files/{id}/send` with a destination id. Not one endpoint per cloud.
2. **One UI** — the "Save to…" action node lists the user's connections. Adding a provider adds a row, never a screen.
3. **One failure vocabulary** — `unauthorized`, `not_found`, `quota_exceeded`, `too_large`, `unreachable`, `conflict`. Providers map their own errors into it; the UI translates the vocabulary, so a new provider needs **zero** new translations.
4. **Delivery is recorded** — the run shows where the file went, and the file records where it was delivered (Phase C `delivered_to` in `release4.0/07`).
5. **The pull integrations stay** — the NC app and the OC extension keep working exactly as today (S10). Push is additive and is the only path that works unattended.

---

## 6. What connector work does to the epic's shape

**Decided 2026-08-15 (row S1): foundations first, not parallel tracks.** Phase F completes before the engine work that depends on it. This costs several PRs before the first demo and buys a single connection UI, one credential store, and no connector rework:

```
Phase F  F0 -> F1 F2 -> F4 -> F5              (seams, one UI pattern, vault)
              |
Engine        +-> E1 E2 -> E3 -> E4 -> E5      (Run now; may start once F2 lands)
                                    |
Connectors                          +-> C10 (C1) -> C11 spike -> F3 -> C3 -> C4/C5 -> C7/C8
                                              ^                        ^
                              Sprint 2's action palette      "Office 365" claim
                              becomes useful here            becomes truthful here
```

Sprint 0 (observe) is unaffected and can run at any time — it touches no connectors.

**Hard couplings:**

- Sprint 2's authored graph cannot ship a useful **action** palette without **F4 + C10**. Until then the honest actions are `.ics`, `email_me`, `compose_reply` and share link.
- Sprint 3's scheduler is where **F3 token refresh** is exercised for real. Do not ship C3 before the scheduler exists — unattended refresh cannot be tested without unattended runs.
- Sprint 4's plugin `graphNodes` seam is how **Synasort / Synaform / Synamail** join. It does **not** cover Nextcloud or OpenCloud, because neither is a plugin in this repo (Finding A).

---

## 7. Sign-off gate (tick before any connector code)

Product and engineering both sign. An unticked row blocks its connector, not the whole epic.

### Strategic decisions

| # | Decision | Answer | OK? |
| - | -------- | ------ | --- |
| S1 | Build the **five foundations (F1–F5) before any Tier-1 connector**; no connector ships its own credential store, status widget or delivery endpoint. **Sequencing is foundations-first, not parallel** — the engine waits rather than building against a seam that does not exist yet | **Decided 2026-08-15: foundations first** | ✅ |
| S2 | **Generic WebDAV (C10) is the first connector**; Nextcloud ships as a preset of it, not as a bespoke integration | Agree | ☐ |
| S3 | **Microsoft 365 requires F3 plus an Azure app registration.** | **Decided 2026-08-15: Synaplan Cloud runs a multi-tenant app; self-hosters register their own.** Two consequences to honour: (a) the self-host registration path is **documented and supported**, never a second-class fallback — O365 must work on a self-hosted install; (b) the multi-tenant app needs a named owner for the admin-consent conversation and for credential rotation, recorded in the F3 PR | ✅ |
| S4 | **Graph API for M365 mail**, not IMAP-XOAUTH2 | Agree | ☐ |
| S5 | **Test accounts exist before the build starts**: live Nextcloud, live OpenCloud/OCIS, an M365 test tenant, a Jira/Confluence instance — named owner per system. **A connector without a test account is not scheduled** | Agree | ☐ |
| S6 | **Mutating external actions** (Jira create, Graph calendar write, MCP write) require: authored-graph only (the planner never emits them), confirmation on interactive runs, `allow_unattended` for scheduled runs, and a per-call audit record | Agree | ☐ |
| S7 | **Sovereignty labelling**: connectors that send content to US-hosted clouds (M365, Google, Atlassian Cloud) are documented as such and visibly labelled in the connection UI — required for the public-sector positioning of `synaplan.eu` | Agree | ☐ |
| S8 | **Jira/Confluence go through MCP first.** Native clients only after MCP is shown insufficient, with the reason recorded here | Agree | ☐ |
| S9 | **Google Workspace (C6) is deferred** until a customer requires it | Agree | ☐ |
| S10 | **The existing Nextcloud app and OpenCloud extension keep working unchanged.** Push (C10/C11) is additive; we do not migrate or deprecate the pull paths in this epic | Agree | ☐ |
| S11 | **OpenCloud write mechanism is decided by a timeboxed spike (§4.3), not by this plan.** If the spike lands on token exchange, the follow-up question *"may Synaplan hold a long-lived credential that acts as the user?"* is a **security decision requiring explicit approval** — server-side refresh tokens and Keycloak impersonation both widen the blast radius of a Synaplan compromise | Spike first, approve separately | ☐ |
| S12 | **Every connector failure is expressed in the shared vocabulary** (§5 rule 3) so a new provider adds no new translation keys | Agree | ☐ |

### Per-connector readiness (repeat before each connector's first PR)

| Check | C10 | C11 | C3 | C4 | C7/C8 |
| ----- | --- | --- | -- | -- | ----- |
| Detail sheet (§4.1) completed and reviewed | ☐ | ☐ | ☐ | ☐ | ☐ |
| Auth model + exact scopes agreed | ☐ | ☐ | ☐ | ☐ | ☐ |
| Live test account available, owner named | ☐ | ☐ | ☐ | ☐ | ☐ |
| Rate limits + timeout + retry policy written down | ☐ | ☐ | ☐ | ☐ | ☐ |
| Every failure mode maps to the shared vocabulary, translated ×4 | ☐ | ☐ | ☐ | ☐ | ☐ |
| Unattended behaviour defined (refresh / reauth / auto-pause) | ☐ | ☐ | ☐ | ☐ | ☐ |
| Privacy note written (what content leaves, to where) | ☐ | ☐ | ☐ | ☐ | ☐ |
| Disconnect / rollback behaviour defined | ☐ | ☐ | ☐ | ☐ | ☐ |
| Test plan covers unit + fixture + live-manual + negative | ☐ | ☐ | ☐ | ☐ | ☐ |
| Docs section drafted (`docs/CONNECTIONS.md`) | ☐ | ☐ | ☐ | ☐ | ☐ |

---

## 8. Testing rules for connectors (all of them)

1. **No live network in CI, ever.** Unit tests use a fake client; integration tests use recorded fixtures. Live verification is a documented manual step with evidence pasted into the PR body.
2. **Every error path has a test and a translated message.** An untranslated or code-only error (`WebDAV 507`) fails review.
3. **Secrets:** a test asserts the credential never appears in logs, exception messages or API responses (spy on the logger; assert masked output).
4. **SSRF:** every user-supplied URL is guarded. Test `http://127.0.0.1`, `http://169.254.169.254`, and a redirect to a private host.
5. **Unattended path:** each connector has a test that runs **without a session or security context** (the cron shape — master plan §3.4).
6. **Disconnect:** removing a connection pauses dependent Saved Tasks with a visible reason — never a silent failure, never a crashed tick.
7. **Idempotency:** re-delivering the same file must not silently duplicate — either dedupe or the documented `rename` behaviour, asserted.
8. **Cross-repo:** a change to `synaplan-nextcloud` or `synaplan-opencloud` runs that repo's own gate (`make ci-local` / `make backend-test frontend-test-unit`) and is linked from the Synaplan PR.

---

## 9. Documentation deliverables

| Doc | Content |
| --- | ------- |
| **`docs/CONNECTIONS.md`** (new) | One page per connector: what it does, what you need, how to connect, what leaves your server, how to disconnect. Written for a non-technical admin |
| `docs/FEATURES.md` | Connections section linking to the above |
| `docs/CONFIGURATION.md` | Flags per connector |
| `docs/MULTITASK_DATA_NODES.md` | Update when the read-only rule gains its mutating exception (S6) |
| `release4.0/07_file-sharing-destinations.md` | Cross-link: F4 is implemented here; update its status |
| `synaplan-nextcloud` README | Note that Synaplan can now push via WebDAV; state which direction to use when |
| `synaplan-opencloud` README | Record the C11 spike result and the chosen write mechanism |
| i18n | Connector names, field labels and the shared failure vocabulary — four locales, native-speaker reviewed ([`08_ux_and_i18n.md`](./08_ux_and_i18n.md)) |
