# Plan — OpenAI-Compatible Endpoints: Auth & Multimodal on AI Models

**Date:** 2026-07-16
**Status:** Draft — ready for public discussion / issue split
**Scope:** Admin AI Models page (`/ai/models`) — OpenAI-compatible server registration, authentication, and multimodal model wiring
**Audience:** Contributors and operators who self-host against LocalAI, vLLM, LiteLLM, OpenWebUI, Ollama `/v1`, etc.

---

## 1. Problem

Admins can already register an **OpenAI-compatible** backbone on the AI Models page (base URL + optional API key + capabilities) and attach models to it. In practice, authentication and multimodal setup still fail for real gateways:

1. **Auth feels missing or broken** — the API key field exists on the endpoints panel, but saved credentials often do not survive. `BCONFIG.BVALUE` is still `VARCHAR(250)` while endpoint payloads are AES-encrypted JSON (`label`, `base_url`, `api_key`, `headers`, `capabilities`). Real keys truncate or corrupt on write.
2. **Auth options are incomplete in the UI** — the backend already accepts custom `headers`, but the admin form only exposes a Bearer API key. Gateways that need `X-API-Key`, org headers, or “no `Authorization` header at all” cannot be configured from the page.
3. **Multimodal models are half-wired** — endpoint capabilities include `pic2text`, and the provider can explain images, but **Add a model** never sets `BJSON.features: ["vision"]`. Chat with attached images therefore falls back to another vision model instead of staying on the OpenAI-compatible chat model.
4. **Image generation / STT are not first-class yet** — `text2pic` and `sound2text` appear in the generic tag list for built-in providers, but OpenAI-compatible endpoints cannot advertise or exercise them end to end.

Ollama-native setup (System Config → `OLLAMA_BASE_URL`) remains URL-only with no key. Authenticated Ollama or any OpenAI-shaped proxy must go through the OpenAI-compatible path — that path must be trustworthy.

---

## 2. Goals

| Goal | Outcome |
|---|---|
| **G1 — Auth that sticks** | An admin can save a real API key (and optional headers) for an OpenAI-compatible server; reload shows `has_api_key`; chat/embed/vision calls send the right credentials. |
| **G2 — Auth that matches the server** | UI supports common patterns: Bearer token, custom header(s), and “no auth”. Empty key must not force `Bearer sk-no-key` when the server rejects it. |
| **G3 — Multimodal models from the form** | Adding a chat or vision model on an endpoint can mark vision capability (`features`) so image chat uses that model. |
| **G4 — Clear Ollama-like flow** | Same mental model as “point at a server”: register server → test → add models → set defaults. No DB spelunking. |

Non-goals for this plan: new cloud provider SDKs, Marketplace images, or changing how env-backed keys (`OPENAI_API_KEY`, …) work in System Config.

---

## 3. Current state (short)

| Piece | Path | Today |
|---|---|---|
| Endpoints panel | `frontend/src/components/config/OpenAiCompatibleEndpointsPanel.vue` | `name`, `label`, `base_url`, `api_key`, capability checkboxes (`chat`, `vectorize`, `pic2text`) |
| Add model form | `frontend/src/components/config/AddModelForm.vue` | Pick endpoint → writes `BSERVICE=OpenAICompatible`, `BJSON={ endpoint }`; **no `features`** |
| Registry | `backend/src/AI/Credential/OpenAiCompatibleEndpointRegistry.php` | Encrypted `BCONFIG` group `openai_compatible`; fields include `headers` |
| Provider | `backend/src/AI/Provider/OpenAICompatibleProvider.php` | Chat, embeddings, vision (`explainImage`); Bearer auth |
| Storage | `backend/src/Entity/Config.php` → `BVALUE` length **250** | Likely root cause of “auth missing after save” |
| Admin APIs | `/api/v1/admin/openai-endpoints`, `/api/v1/admin/models` | CRUD + connection test (`GET {base_url}/models`) |

Related earlier design: `20260709-hosting-partner-core-requirements.md` (CORE-1). This plan is the UX/reliability follow-up so that work is actually usable.

---

## 4. Work items

### P0 — Fix credential storage (blocker)

**Why:** Without this, every auth UI improvement is useless.

- Migrate `BCONFIG.BVALUE` from `VARCHAR(250)` to `TEXT` (or `LONGTEXT`) with a production-safe, idempotent Doctrine migration (raw `ADD`/`MODIFY` suitable for Galera — see `docs/MIGRATIONS.md`).
- Add a regression test: save an endpoint with a long API key + headers → list returns `has_api_key: true` → provider resolves a non-empty key.
- Document in `docs/CONFIGURATION.md` that OpenAI-compatible secrets live in encrypted `BCONFIG`, not `.env`.

**Acceptance:** Saving a 64+ character API key via the AI Models endpoints panel survives a page reload and is used on the next chat request.

---

### P1 — Complete authentication on the endpoints panel

**UI (`OpenAiCompatibleEndpointsPanel.vue` + i18n `en/de/es/tr`):**

1. Keep **API key** (password field, keep-on-edit placeholder).
2. Add **Custom headers** editor (key/value rows) wired to the existing API `headers` field.
3. Add **Auth mode** (or equivalent clear UX):
   - `bearer` (default) — `Authorization: Bearer <api_key>`
   - `headers_only` — send custom headers, omit Bearer / omit placeholder key
   - `none` — no auth headers
4. Show a clear indicator when an existing endpoint has a stored key (`has_api_key`) without revealing the secret.
5. On **Test connection**, send the same auth the provider will use (including headers).

**Backend:**

- Persist optional `auth_mode` in the encrypted endpoint payload (default `bearer` for backward compatibility).
- Change `OpenAICompatibleProvider` (and test helper) so empty key + `none` / `headers_only` does **not** inject `Bearer sk-no-key`.
- OpenAPI annotations + regenerate frontend Zod schemas.

**Acceptance:** An admin can configure (a) Bearer key, (b) `X-API-Key` only, (c) no auth — each passes Test and a real chat call against a matching mock/upstream.

---

### P2 — Multimodal / vision when adding models

**Add model form + edit path:**

1. When capability is `chat` or `pic2text` (or endpoint advertises vision), offer a **“Supports vision / multimodal input”** checkbox.
2. On create/update, merge into `BJSON`:
   ```json
   { "endpoint": "<name>", "features": ["vision"] }
   ```
   Preserve other JSON keys on edit.
3. Expose the same control in the admin models table edit UI (today JSON is preserved but not editable).
4. Document that `pic2text` selects the dedicated vision path, while `features: ["vision"]` on a `chat` model keeps image chat on that model (`ChatHandler` already gates on `Model::hasFeature('vision')`).

**Acceptance:** Add a vision-capable chat model on an OpenAI-compatible endpoint from the UI only; send a chat with an image; the request stays on that model (no silent fallback).

---

### P3 — Broader OpenAI-compatible capabilities (follow-on)

Priority after P0–P2:

| Capability | Upstream shape | Notes |
|---|---|---|
| `text2pic` | `/v1/images/generations` | Endpoint capability toggle + provider method + Add Model tag |
| `sound2text` | `/v1/audio/transcriptions` | Same pattern |
| Model import | `GET /v1/models` | “Import selected” after Test — prefill provider ids |
| Files | `/v1/files` | Only where the gateway supports it |

Keep per-endpoint capability checkboxes as the source of truth for what the Add Model form offers.

---

### P4 — UX polish (same page, Ollama-like clarity)

- Short guided copy: **1) Add server → 2) Test → 3) Add models → 4) Set as default**.
- Empty states that point from Add Model to the endpoints panel when none exist.
- Optional: allow marking a chat model as default from the success toast after create.
- Do **not** add a second competing “Ollama auth” surface until P0–P1 are solid; document that authenticated Ollama should use OpenAI-compatible `/v1`.

---

## 5. Suggested implementation order

```text
P0 storage migration + tests
  → P1 auth modes + headers UI
    → P2 vision feature checkbox on Add/Edit Model
      → P3 text2pic / import (as capacity allows)
        → P4 copy / empty states
```

Estimate (one familiar contributor): **P0 ~0.5d**, **P1 ~1–1.5d**, **P2 ~0.5–1d**, **P3 ~2–3d**, **P4 ~0.5d**.

---

## 6. Acceptance checklist (definition of done)

- [ ] Long API keys and custom headers persist across reload (P0).
- [ ] Endpoints panel can configure Bearer, header-only, and no-auth; Test uses the same credentials (P1).
- [ ] Provider never sends a fake Bearer token when auth mode is none/headers-only (P1).
- [ ] Admin can mark a model as vision-capable from Add/Edit Model; image chat uses it (P2).
- [ ] Full unfiltered gate green: `make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types` (and frontend lint/test if UI touched).
- [ ] Docs: operator steps in `docs/CONFIGURATION.md` (or AI Models admin help) updated in all relevant locales for new UI strings (`en`, `de`, `es`, `tr`).

---

## 7. Files likely to change

| Area | Files |
|---|---|
| Migration | `backend/migrations/…` (`BCONFIG.BVALUE` → TEXT) |
| Entity | `backend/src/Entity/Config.php` |
| Registry / provider | `OpenAiCompatibleEndpointRegistry.php`, `OpenAICompatibleProvider.php` |
| Admin API | `AdminOpenAiEndpointsController.php` (+ DTOs / OpenAPI) |
| Frontend | `OpenAiCompatibleEndpointsPanel.vue`, `AddModelForm.vue`, `AIModelsAdminPanel.vue`, `adminOpenAiEndpointsApi.ts`, i18n |
| Tests | Unit tests for registry encrypt/decrypt size + auth header behaviour; frontend component tests if present |
| Docs | `docs/CONFIGURATION.md` |

---

## 8. Out of scope

- Replacing System Config cloud provider keys
- Native `OLLAMA_API_KEY` env support (document OpenAI-compatible `/v1` instead; revisit later if needed)
- Non-OpenAI protocol adapters (Anthropic-native, Bedrock, etc.)

---

_Last updated: 2026-07-16_
