# Sprint 0 — API key scopes and the Desktop flag

**Goal:** Make `BAPIKEYS.BSCOPES` real, and add a kill switch for everything
that follows. No desktop UI. No new repo. No pairing.
**Depends on:** checklist rows 7, 13, 16.
**Unlocks:** Sprint 1 (pairing must mint a *narrow* key).
**Repos:** `synaplan/` only.
**Flag:** `DESKTOP_AGENT.ENABLED` (off). Scope enforcement is **not** behind
the Desktop flag — it is CORE-3 and stays on.

This sprint is independently valuable. Hosting-partner CORE-3 and the July
local-agent research both call unenforced scopes a blocker. Do it first so
a later pairing key cannot silently be god-mode.

---

## 0. Why this sprint exists

`ApiKey::hasScope()` is never called. `ApiKeyAuthenticator` logs scopes and
then authenticates the owner. Any `sk_*` is full account access. A laptop
key without enforcement is an account takeover when the laptop is stolen.

Existing integrations (Claude Code via `/v1/messages`, n8n, webhooks, `/mcp`)
must keep working. Grandfather rule: **empty scopes or the legacy
`webhooks:*` / `webhooks:email` / `webhooks:whatsapp` set = full access.**
Only keys that *opt into* a non-empty, non-legacy list are restricted.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Security/ApiKeyAuthenticator.php` | Sets user from key; must stash the `ApiKey` on the request |
| `backend/src/Entity/ApiKey.php` | `hasScope()` — extend for `*` and prefix `desktop:*` if needed |
| `backend/src/Controller/ApiKeyController.php` | Create/list; document legal scopes |
| `backend/config/packages/security.yaml` | Firewalls for `/v1`, `/mcp`, `/api/v1` |
| `_devextras/planning/20260709-hosting-partner-core-requirements/README.md` §CORE-3 | Vocabulary we must not contradict |
| `backend/tests/Unit/ApiKeyAuthenticatorTest.php` | Extend, do not replace |

---

## 2. Developer steps

### 2.1 Scope vocabulary (code constant, not a migration)

Add `App\Security\ApiKeyScope` (final class of string constants + helpers):

| Scope | Meaning |
| ----- | ------- |
| `*` | Full access (explicit) |
| *(empty list)* | Full access (legacy) |
| `webhooks:email`, `webhooks:whatsapp`, `webhooks:*` | Legacy full access **until a later CORE-3 migration**. Do not silently narrow them in this sprint |
| `desktop:messages` | `/v1/messages`, `/v1/models`, `/v1/messages/count_tokens` |
| `desktop:mcp` | `/mcp` |
| `desktop:files` | `/api/v1/files*` upload/list/download the owner already may |
| `desktop:jobs` | Sprint 6 check-in / report (declare now, unused until D-steps in Sprint 6) |
| `desktop:*` | All `desktop:` scopes |

Admin scopes from CORE-3 (`admin:*`) are **not** minted here. Do not invent
`chat` / `files` / `rag` until CORE-3 is fully implemented — this sprint only
needs desktop + grandfather.

A key is **restricted** iff its scope list is non-empty **and** is not a
legacy-webhook-only list **and** does not contain `*`.

### 2.2 Request attribute + voter / listener

1. `ApiKeyAuthenticator` already has the entity — put it on
   `$request->attributes->set('api_key', $entity)` (if not already).
2. New `ApiKeyScopeSubscriber` (or `#[RequiresScope]` attribute + listener):
   map path prefixes to required scopes.
3. Session-cookie users are unaffected (no `api_key` attribute).
4. Restricted key + missing scope → **403** with a stable JSON error
   (`code: insufficient_scope`, list required vs granted). Never 401
   (the key is valid).
5. Unrestricted key → no extra check.

Prefix map (v1):

| Prefix | Required (any of) |
| ------ | ----------------- |
| `/v1/` | `desktop:messages` or `*` or unrestricted |
| `/mcp` | `desktop:mcp` or `*` or unrestricted |
| `/api/v1/desktop/` | `desktop:jobs` or `desktop:*` or session user or unrestricted |
| everything else `/api/v1/` | unrestricted keys only **or** `*` — a desktop-only key must **not** hit admin, users, widgets, webhooks |

That last row is the point: a paired computer cannot administer the instance.

### 2.3 Tests that lock the grandfather

| Case | Expected |
| ---- | -------- |
| Key with `scopes = []` calls `GET /v1/models` | 200 (if it did before) |
| Key with `webhooks:email` calls `/v1/messages` | 200 (legacy full) |
| Key with `['desktop:messages']` calls `/v1/models` | 200 |
| Same key calls `GET /api/v1/admin/config/values` | 403 `insufficient_scope` |
| Same key calls `/mcp` | 403 |
| Session user calls admin | unchanged (not an API key) |
| Invalid key | still 401 |

Use the existing kernel-boot test style. No live LLM.

### 2.4 Feature flag

`DESKTOP_AGENT.ENABLED` in `BCONFIG`, group `DESKTOP_AGENT`, insert-if-missing
`0`, resolve per-user → global → code default `false` (same helper as
`MultitaskRoutingConfig::isFeatureEnabled`).

No consumer yet except a unit test of the resolver and a tiny
`DesktopAgentConfig` service. Sprint 1 reads it.

### 2.5 Do not do in this sprint

- Change the API Keys UI (optional hint only if cheap; prefer Sprint 1).
- Migration that rewrites existing `BSCOPES` JSON.
- Pairing, devices, client repo.

---

## 3. Tests (this sprint)

- Unit: `ApiKeyScope` helpers (restricted vs legacy vs `*`).
- Unit / WebTest: subscriber matrix in §2.3.
- Existing `ApiKeyAuthenticatorTest` still green.
- `DesktopAgentConfig` resolution: missing row → false; user `1` beats global `0`.
- Characterization snapshots **untouched**.
- Unfiltered `make -C backend phpstan` and `make -C backend test`.

---

## 4. Documentation

- `docs/OPENAI_COMPATIBLE_API.md` and `docs/ANTHROPIC_COMPATIBLE_API.md`:
  one paragraph — existing keys unchanged; new desktop keys are scoped.
- `docs/API_KEYS.md` if it exists; otherwise a short section on the API Keys
  page docs. Do not write a novel.

---

## 5. Exit criteria

1. A `desktop:messages` key cannot call admin or `/mcp`.
2. Empty-scope keys behave as today.
3. Flag resolver exists and defaults off.
4. Invariants C1, C2, C3, C6 green ([`08_testing_and_documentation.md`](./08_testing_and_documentation.md)).
5. Mobile policy: new PHP files listed `backend-only`.
