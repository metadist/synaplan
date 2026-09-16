# MCP OAuth Connectors — Notion MCP + Higgsfield MCP

**Date:** 2026-08-21 · **Status:** Implemented on `feat/mcp-update` · **Priority:** P1
**Related:** `release4.0/08_mcp-data-nodes-and-skill-registry.md`,
`release4.0/09_external-data-nodes.md`, the outbound client in
`backend/src/Service/Mcp/`, the connections OAuth stack in
`backend/src/Service/OAuth/`.

> **Goal:** Let users connect the hosted **Notion MCP** (`https://mcp.notion.com/mcp`)
> and **Higgsfield MCP** (`https://mcp.higgsfield.ai/mcp`) as outbound MCP servers
> and pull data from them in chat/task plans — via one **generic OAuth mode** on the
> existing MCP Servers page, **gated by an admin system-config flag** (off by default).
> No vendor SDKs, no REST wrappers: both services are standard remote MCP servers.

---

## 0. Step 0 — "Connection works" (DONE, verified 2026-08-21)

All probes run twice: from the dev host **and from inside the backend container**
(egress + DNS + SSRF-relevant path confirmed; both hosts resolve to public IPs,
so `SsrfGuard` passes unchanged).

### 0.1 Both servers speak the same standard

| | Notion MCP | Higgsfield MCP |
|---|---|---|
| Endpoint (Streamable HTTP) | `https://mcp.notion.com/mcp` | `https://mcp.higgsfield.ai/mcp` |
| Unauthenticated `initialize` | 401 + `WWW-Authenticate: resource_metadata=…` | 401 + `WWW-Authenticate: resource_metadata=…, scope="openid email offline_access"` |
| PRM (RFC 9728) | `/.well-known/oauth-protected-resource/mcp` → 200 | same path → 200 (root form also 200) |
| AS metadata (RFC 8414) | `/.well-known/oauth-authorization-server` → 200 | same → 200 |
| Authorize / token | `/authorize`, `/token` | `/oauth2/authorize`, `/oauth2/token` |
| Dynamic client registration (RFC 7591) | `/register` → **HTTP 201** | `/oauth2/register` → **HTTP 201** |
| PKCE | S256 (+plain) | S256 only |
| Client auth method | `none` supported (public client) | `none` supported (public client) |
| Scopes | `default` | `openid email offline_access` |
| Static token instead of OAuth? | **No** (`ntn_…` rejected, verified 401) | **No** ("no API key" by design) |

### 0.2 Live DCR proof (the exact call our backend will make)

Both providers issued a client against the production callback URI with zero
pre-registration:

```text
POST https://mcp.notion.com/register          → 201, client_id atclHxQjy13pQbwb
POST https://mcp.higgsfield.ai/oauth2/register → 201, client_id XirNp6APWCavQe8h
redirect_uri: https://web.synaplan.com/api/v1/mcp-servers/oauth/callback
token_endpoint_auth_method: none
```

### 0.3 Findings that shape the design

1. **One generic implementation covers both** (and any future spec-compliant
   remote MCP: GitHub, Atlassian, Linear, …). Discovery → DCR → PKCE consent →
   Bearer. Nothing vendor-specific beyond a template card.
2. **Higgsfield DCR quirk:** we requested `grant_types: [authorization_code,
   refresh_token]`; the registration response contains only
   `authorization_code`, although the AS metadata advertises `refresh_token`.
   → Request scope `offline_access` at authorize time; if no `refresh_token`
   arrives in the token response, the connection must degrade to
   `reauth_required` on expiry instead of silently failing.
3. **Notion sessions are stateless** (documented): a stale `Mcp-Session-Id` is
   never rejected. Our per-operation session in `McpClient::withSession()` is
   already compatible.
4. **Higgsfield tools are mostly mutating** (image/video generation costs
   credits). Read surfaces (history browsing, asset fetch) fit `mcp_fetch`;
   generation tools must go through the existing `allow_write` opt-in +
   `McpActionRunner` path. No new mechanism needed — the annotations guard
   (`readOnlyHint`) already decides.
5. Higgsfield also offers a `device_code` flow. **Not needed** — Synaplan has a
   browser UI, so authorization-code + PKCE is the right (and simpler) choice.

---

## 1. Current state (what exists, what's missing)

Exists and is reused as-is:

- `BMCPSERVERS` / `McpServerConfig` entity — per-user server registry, encrypted
  auth value (`EncryptionService`), `enabled`, `allow_write`.
- `McpClient` — Streamable HTTP session handshake, SSE framing, SSRF guard,
  timeouts, size caps. Auth today = **one static header only**.
- `McpToolRegistry`, `McpFetchRunner` (read-only guard), `McpActionRunner`
  (write opt-in), `GatewayToolLoop`, per-topic `tool_mcp` opt-in.
- Master switch `MCP.CLIENT_ENABLED` (BCONFIG, seeded, admin UI section
  `channels → mcp` in `SystemConfigService`).
- A complete OAuth stack built for M365/Dropbox: `OAuthClient`
  (code+PKCE+refresh), `OAuthConsentService` (server-held PKCE verifier, signed
  state carrying `owner_id` — survives SameSite=Strict), `OAuthTokenSet`.

Missing (the actual work):

- OAuth **discovery** (RFC 9728 → RFC 8414) and **DCR** (RFC 7591).
- An `oauth` auth mode on the MCP server row + encrypted token storage + refresh.
- `OAuthClient` assumes a static, install-wide provider with a `client_secret`;
  MCP OAuth uses per-server discovered endpoints and public clients
  (`token_endpoint_auth_method: none`).
- Admin flag gating the OAuth connect capability.
- Notion/Higgsfield template cards + Connect/Reconnect UX.

---

## 2. Design

### 2.1 Auth is a mode on the connection, not a vendor plugin

`BMCPSERVERS.BAUTHMODE`: `none` | `bearer` (default, = today's behaviour) | `oauth`.

For `oauth`, one encrypted JSON blob (`BOAUTH`, via `EncryptionService`) holds
everything per server row:

```json
{
  "resource": "https://mcp.notion.com/mcp",
  "authorization_endpoint": "…", "token_endpoint": "…", "registration_endpoint": "…",
  "client_id": "…",
  "scopes": ["default"],
  "access_token": "…", "refresh_token": "…", "expires_at": 1787327000,
  "status": "connected|reauth_required"
}
```

Per-row DCR (not per-install): registration is free, instant, and keeps every
user's grant isolated. No operator setup for cloud or self-host.

### 2.2 New backend services (all `final readonly`, unit-testable with `MockHttpClient`)

| Service | Responsibility |
|---|---|
| `McpOAuthDiscovery` | 401-challenge `resource_metadata` → PRM (path-suffix **and** root form) → AS metadata. Validates `code_challenge_methods_supported` ⊇ S256, endpoints present, endpoints HTTPS + SSRF-checked. |
| `McpOAuthRegistration` | RFC 7591 POST: `client_name: "Synaplan"`, install redirect URI, `token_endpoint_auth_method: none`, requested scopes from PRM. |
| `McpOAuthConsentService` | Clone of the proven `OAuthConsentService` mechanics: PKCE verifier stays server-side in cache keyed by state nonce; signed state carries `owner_id` + `server_id`. Builds authorize URL; callback validates state, exchanges code (reuse `OAuthClient` exchange with **optional** `client_secret`), stores tokens encrypted, sets status. |
| `McpOAuthTokenProvider` | Hands `McpClient` a valid access token: returns stored token if fresh, refreshes if expired (single-flight via cache lock — Galera nodes!), throws `reauth_required` if refresh impossible (Higgsfield quirk 0.3-2). |

`McpClient` change is minimal: auth-header resolution becomes
mode-aware; on HTTP 401 in `oauth` mode → one refresh + retry → else mark
`reauth_required` and surface a **readable** error (include the response body's
`error_description` — today's bare "MCP server answered HTTP 400/401" cost us a
debugging session already).

`OAuthClient`: make `client_secret` optional (public clients). Additive,
M365/Dropbox untouched.

### 2.3 HTTP endpoints (in `McpServerConfigController`)

| Route | Auth | Purpose |
|---|---|---|
| `POST /api/v1/mcp-servers/{id}/oauth/start` | user | Discovery + DCR (idempotent) + returns `authorize_url` |
| `GET  /api/v1/mcp-servers/oauth/callback` | **public** (signed state carries owner) | Code exchange, store tokens, redirect to `/channels/mcp?connected={id}` or `…?oauth_error=…` |
| `POST /api/v1/mcp-servers/{id}/oauth/disconnect` | user | Clear tokens, keep the row |

The callback must not depend on the session cookie (SameSite=Strict lesson from
M365). Full OpenAPI annotations → `make -C frontend generate-schemas` → Zod.

### 2.4 Admin system-config flag (the requested gate)

New BCONFIG row, group `MCP`, key `OAUTH_CONNECTORS_ENABLED`:

- **Seeded `0` (off)** by `McpConfigSeeder` — insert-if-missing, so the
  operator's choice survives deploys. Connecting is an explicit admin opt-in.
- Surfaced in `SystemConfigService` next to `MCP_CLIENT_ENABLED`
  (admin UI: System Configuration → Channels → MCP servers).
- Enforced in **both** layers:
  - Backend: the three OAuth routes return a clear "disabled by administrator"
    error when off. Existing `bearer`/`none` servers are unaffected.
  - Frontend: OAuth template cards + Connect button hidden when off; already-
    connected servers keep working only if the flag is on (belt: token provider
    also checks the flag before refreshing).
- Hierarchy stays consistent with the existing pattern:
  `MCP.CLIENT_ENABLED` (any outbound MCP) → `MCP.OAUTH_CONNECTORS_ENABLED`
  (may users run OAuth consent flows) → per-server `enabled` → per-topic `tool_mcp`.

### 2.5 Frontend (MCP Servers page, `McpServersConfiguration.vue` + templates)

- Template cards **Notion** and **Higgsfield** (`mcpServerTemplates.ts` gains
  `authMode` + `urlPrefill`): URL prefilled + locked to the known endpoint,
  no token fields, a **Connect** button instead.
- Flow: Save row → `oauth/start` → full-page redirect to provider consent →
  callback → back on `/channels/mcp` with a success/error toast.
- Status chip per server: `Connected` / `Action needed (reconnect)` /
  `Not connected`; Reconnect re-runs the same start flow.
- Custom stays first-class; a custom URL that answers a 401 OAuth challenge on
  **Test connection** gets a hint to use Connect (auto-detect, no dead end).
- i18n: **all four locales** (`en`, `de`, `es`, `tr`) in the same change.
- Copy uses the canonical wording rules; no "DCR/PKCE" jargon in primary copy.

### 2.6 Data fetching (already works once connected — verify, don't rebuild)

- `tools/list` populates the planner's dynamic `mcp_fetch` sub-catalog exactly
  as for bearer servers.
- Notion: search/fetch tools are read-safe → usable immediately in `mcp_fetch`.
- Higgsfield: history/asset reads → `mcp_fetch`; generation tools are mutating →
  only via `allow_write` + `McpActionRunner` (existing guards, no change).

---

## 3. Step-by-step development plan (each step = green full gate before commit)

Every step ends with the unfiltered house gate:
`make lint && make -C backend phpstan && make test`
(+ frontend: `make -C frontend lint && docker compose exec -T frontend npm run check:types && make -C frontend test` when frontend files changed).

### Step 1 — Schema + entity (backend-only)

- Doctrine migration, **Galera-safe**: raw idempotent `addSql()` only —
  `ALTER TABLE BMCPSERVERS ADD COLUMN IF NOT EXISTS BAUTHMODE VARCHAR(16) NOT NULL DEFAULT 'bearer'`,
  `ADD COLUMN IF NOT EXISTS BOAUTH LONGTEXT NOT NULL DEFAULT ''`.
  **No `Schema $schema` API** (prod comparator throws on this cluster).
- Entity getters/setters incl. `getDecryptedOAuthState()/setDecryptedOAuthState()`.
- **Tests:** entity round-trip unit test (encrypt/decrypt, default mode
  `bearer` keeps existing rows working); migration runs on a fresh dev DB.

### Step 2 — Discovery + DCR services

- `McpOAuthDiscovery`, `McpOAuthRegistration` as in 2.2.
- **Tests (unit, `MockHttpClient`):** happy path Notion-shaped + Higgsfield-shaped
  fixtures (real payloads from Step 0); PRM at path-suffix vs root; missing
  S256 → clear exception; `registration_endpoint` absent → clear exception;
  non-HTTPS/private endpoint in metadata → blocked (SSRF); Higgsfield
  `grant_types` without `refresh_token` recorded in state.

### Step 3 — Consent + callback + token provider

- `McpOAuthConsentService`, `McpOAuthTokenProvider`; `OAuthClient` gains
  optional `client_secret`.
- Controller routes from 2.3 with full OpenAPI annotations; callback route added
  to the firewall's public list.
- **Tests:** unit — state/nonce round-trip, replayed callback rejected, expired
  verifier rejected, refresh single-flight, no-refresh-token → `reauth_required`;
  integration (`McpServerConfigControllerTest`) — start returns authorize URL,
  callback with bad state 4xx, flag-off returns "disabled by administrator";
  M365/Dropbox suites stay green (regression).

### Step 4 — `McpClient` auth modes + readable errors

- Mode-aware auth resolution; 401→refresh→retry-once in `oauth` mode;
  error messages include truncated `error`/`error_description` from the body.
- **Tests:** extend `McpClientTest` — bearer path unchanged (regression),
  oauth happy path, 401-refresh-retry sequence, refresh failure surfaces
  `reauth_required`, error body excerpt in exception message.

### Step 5 — Admin flag

- `McpConfigSeeder`: seed `MCP.OAUTH_CONNECTORS_ENABLED = '0'`.
- `SystemConfigService`: register the field in the `mcp` section (same pattern
  as `MCP_CLIENT_ENABLED`).
- **Tests:** seeder idempotency (operator `0`/`1` survives re-seed); config
  service exposes + persists the flag; OAuth routes blocked when off (already
  covered in Step 3, keep the assertion).

### Step 6 — Frontend

- Templates with `authMode`/`urlPrefill` (Notion, Higgsfield cards + icons),
  Connect/Reconnect/Disconnect UX, status chips, callback query handling
  (`connected` / `oauth_error` toasts), flag-off hiding, Test-connection
  OAuth hint. Regenerate Zod schemas after backend OpenAPI changes; `vue-tsc`.
- i18n en/de/es/tr complete in the same PR.
- **Tests (Vitest):** template picker prefill/lock behaviour; Connect button
  gated by runtime flag; status chip states; `mcpServerTemplates.spec.ts`
  extended; existing `McpServersConfiguration.spec.ts` stays green.

### Step 7 — Live verification (manual, against real services)

Checklist executed on dev (and later once on web100 after deploy):

1. Admin: flag off → cards hidden, `oauth/start` rejected. Flag on → visible.
2. Notion: Connect → consent → callback → status Connected → **Test connection**
   lists tools → chat task with `tool_mcp` topic fetches a Notion page.
3. Higgsfield: Connect → consent → tools listed → read tool via `mcp_fetch`;
   generation tool refused without `allow_write`, works with it (annotations guard).
4. Token expiry: force-expire `expires_at` → next call refreshes (Notion) /
   flags `reauth_required` (Higgsfield, if no refresh token) → Reconnect heals.
5. Light/dark/V2 visual check of the new cards + chips.

### Step 8 — Docs + rollout

- Update `docs.synaplan.com` MCP page (outbound client: OAuth servers section,
  admin flag, Notion + Higgsfield walkthrough with screenshots).
- Rollout: normal platform deploy; flag ships **off** — enabling on
  web.synaplan.com is a deliberate admin action after smoke test (Step 7 on prod).
- Classification for the app pipeline: backend steps = `backend-only`,
  frontend steps = `ota-candidate`. No native/store impact.

---

## 4. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Higgsfield refresh-token absence (0.3-2) | Explicit `reauth_required` state + Reconnect button; never a silent failure |
| Provider changes DCR/metadata shape | Fixtures from Step 0 pinned in unit tests; discovery failures surface as readable errors in Test connection |
| Token refresh races across Galera web nodes | Single-flight cache lock in `McpOAuthTokenProvider` (shared Redis cache) |
| Callback abuse / CSRF | Signed state (existing `OAuthStateService`), server-held PKCE verifier, one-time consumption — proven M365 pattern |
| SSRF via malicious metadata | Every discovered endpoint re-checked by `SsrfGuard` before any request |
| Costs on Higgsfield (credits) | Generation stays behind `allow_write` opt-in; default read-only |
| Existing bearer servers regress | `bearer` remains the column default; untouched code path covered by regression tests in Steps 1/4 |

## 5. Out of scope (v1)

- stdio/`npx` servers (impossible from the cluster), SSE-only legacy transport,
  device-code flow, per-install pre-registered OAuth apps, a Notion REST plugin,
  migrating `BMCPSERVERS` onto `BCONNECTIONS`.
