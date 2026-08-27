# Provider-Aware Model Catalog — Concept & Development Plan

**Date:** 2026-08-26
**Status:** AGREED — all open questions resolved, implementation in progress
in this workspace (all PRs, no ownership split).

**Decisions (2026-08-26):**
- **Never delete rows from `BMODELS`.** Disabling — by operator, CLI, or
  missing provider credentials — only greys out or hides entries. When a key
  is added later, the entries reappear automatically (read-time filtering).
  The CLI gets no deletion path at all; `ModelCatalog::remove` stays reserved
  for the retirement machinery, which also deactivates rather than deletes.
- **Visibility split:** regular users see only available models (unavailable
  = hidden); admin views show the full catalog with unavailable entries
  greyed out + badge linking to `/admin/setup`.
- **Ollama follows the same concept:** rows for models that are not pulled
  are hidden for users; admin views show all Ollama rows with a
  **"not pulled"** badge (instead of "no key").
- **Local whisper.cpp** enters the catalog as service **`Whisper`**
  (Piper precedent: tool name as `BSERVICE`).
- **We implement everything here** (PRs A–E); the tracking issue is opened
  by us, referencing the partner feedback.
**Trigger:** Hosting-partner feedback (Dominik, 2026-08) on model management for
self-hosted/on-prem installs; relates to #462 (on-premise tracker), #1521
(no-provider tombstone), #1532/#1533 (retirements + recommendations),
#1512/#1516 (availability/health), and the hosting-partner requirements in
`20260709-hosting-partner-core-requirements.md`.
**Tracking issue:** #1586

---

## TL;DR

The catalog ships 117 models across 14 providers, and every install seeds all
of them as active + selectable — regardless of whether the install has a
single API key. The backend **already knows** which providers are usable
(`ChatReadinessService::providerAvailability()`, `ModelConfigService::usableProviders()`)
and the **routing path already respects it** — but the presentation and
configuration surfaces do not: the model picker shows everything, "Select
suggested models" writes cloud BIDs into user rows on every registration, and
the CLI/charts fight the seeder. The fix is not a new subsystem; it is wiring
the existing availability truth into four surfaces (picker endpoint, suggested
defaults, admin UI, CLI/charts), un-freezing per-user default rows, and giving
local Whisper a first-class catalog entry. No schema change is required for
the core work.

---

## 1. Findings — what exists today (verified in code)

### 1.1 The availability layer EXISTS — it is just not consumed by the catalog surfaces

| Component | What it does | File |
| --- | --- | --- |
| `ChatReadinessService::providerAvailability()` | Probes every registered provider (`isAvailable()`), caches 30 s. Ollama counts as unavailable until the effective chat model is actually **pulled**. | `backend/src/AI/Credential/ChatReadinessService.php` |
| `ModelConfigService::usableProviders()` | Same truth on the hot resolution path (60 s cache); `isModelUsable()` = `BACTIVE=1` + provider usable + (Ollama) model pulled. | `backend/src/Service/ModelConfigService.php` |
| `getDefaultModel()` | Skips per-user AND global bindings whose provider is unusable; falls back to `firstUsableModelForCapability()`. | same |
| `app:provider:apply-defaults --auto` | At container start, repoints a broken cloud default at the best available provider (`PREFERENCE_ORDER`). Never overrides a deliberate keyless (Ollama) choice. | `ProviderDefaultsService`, `_docker/backend/docker-entrypoint.sh` |
| `/admin/setup` + `/api/v1/admin/provider-keys` | Per-provider key cards: save, test, remove, apply defaults. Keys live install-wide in encrypted `BCONFIG` (`ownerId=0`, group `provider_keys`); env vars are bootstrap-only, UI wins. | `AdminProviderKeysController`, `ProviderKeyStore`, `ProviderSetupView.vue` |
| `setup.chatReady` tombstone (#1521) | Full-page explainer when no real provider can serve chat. | `ConfigController`, `ProviderSetupBanner.vue` |

**Conclusion: routing is provider-aware. Presentation and configuration are not.**

### 1.2 The surfaces that ignore availability (the actual gaps)

1. **`GET /api/v1/config/models`** (`ConfigController::getModels`, ~line 775)
   returns every `BACTIVE=1` row. On an Ollama-only install the chat picker
   lists ~40 chat models, of which 1–2 work. This feeds: chat `ModelDropdown`,
   the "Again with…" picker, the `/ai/models` defaults page, widget model
   dropdowns, and the routing planner picker.
2. **"Select suggested models"** (`POST /api/v1/config/models/defaults/reset`
   → `ModelConfigService::resetUserDefaults()`) writes the code-recommended
   BIDs (Anthropic chat, Groq sort/STT, …) as **per-user** `DEFAULTMODEL`
   rows, skipping only `BACTIVE=0` models — availability is not checked. The
   same method runs at **every registration** (`initializeNewUserDefaults()`),
   freezing seed-time recommendations into user rows. A resolution-time
   mitigation exists (unusable per-user bindings are skipped in favour of the
   global default), but the UI still *displays* the frozen, non-working model
   as the user's default.
3. **Seeding** (`ModelSeeder` via `app:seed` at every container start) inserts
   all 117 catalog rows with catalog defaults (mostly `selectable=1`,
   `active=1`). Operator toggles survive re-seed (fingerprint mechanism,
   toggles only written on INSERT) — but **deleted rows are resurrected**:
   absent row → `ACTION_INSERT`.
4. **CLI** — `app:model:enable|disable` exist (the Helm chart's
   `51-init-models.sh` uses them), but:
   - `app:model:disable` **DELETEs** the row (`ModelCatalog::remove`). Next
     container start re-inserts it via `app:seed` → silent revert. Deletion
     also breaks history: `BMESSAGES` references BIDs (this is exactly why
     retirements deactivate instead of delete — see the xAI comments in
     `ModelCatalog.php`).
   - No provider-level operation; only per-model keys.
   - No `app:provider:list` that shows the availability truth.
5. **Local Whisper is special-cased, not a model.** `MessagePreProcessor`
   falls back to `WhisperService` (whisper.cpp, `WHISPER_*` env) when no
   SOUND2TEXT binding resolves. It has no `BMODELS` row, so it cannot be a
   default binding, does not appear in the STT picker, and
   `speechToTextAvailable` needs bespoke logic. Piper (local TTS) already IS a
   catalog entry — the precedent exists.

### 1.3 Browser STT reality (for the use cases)

- Streaming STT in the browser = **Web Speech API** (`webSpeechService.ts`):
  Chrome/Edge/Safari only, **and Chrome's implementation sends audio to
  Google's servers** — it is NOT local and must be off (`WEB_SPEECH_ENABLED=false`)
  in air-gapped installs.
- Fallback on every browser (and the only path on Firefox): MediaRecorder →
  `POST /api/v1/messages/upload-file` → server-side STT (API model per
  SOUND2TEXT binding, or local whisper.cpp fallback).
- So "STT on different browsers, fully local" already works mechanically —
  the missing piece is that the SOUND2TEXT binding cannot point at local
  Whisper, and the picker misleadingly offers cloud STT models with no keys.

---

## 2. Critical review of the partner feedback

Overall diagnosis: **correct and valuable** — the catalog surfaces treat every
install like synaplan.com. But several specifics need correction, which
matters because they change what the issue should ask for:

| Claim | Assessment |
| --- | --- |
| "Nichts fragt, welche Provider diese Installation hat" / "fehlt eine Ebene *Provider dieser Installation*" | **Partly wrong.** The layer exists (`providerAvailability()`, `usableProviders()`, key store, `/admin/setup`, boot-time auto-repair) and the routing path consumes it. What is missing is applying it to *presentation* (picker, suggested models, admin list) and *tooling* (CLI, charts). The issue should say "expose and consume the existing truth", not "create the layer" — otherwise someone builds a second one. |
| AC 1: "Provider-Verfügbarkeit ist Backend-Wahrheit (Key/URL gesetzt)" | **Agree, with a sharpening:** "key set" is necessary but not sufficient. The existing semantics are better than the criterion: Ollama counts only when the model is *pulled*; health monitoring (#1516) covers "key set but broken". Keep the existing three-layer split: **configured** (key/URL present) → gates *visibility*; **healthy** (probes, #1516) → gates *routing preference*; never conflate them, or a transient outage would empty the picker. |
| AC 2: "Katalog-**Seeding**, Picker und Suggested zeigen nur Modelle verfügbarer Provider" | **Disagree on the seeding half.** Seeding must stay provider-agnostic and the filter must be applied at **read time**: (a) keys are added at runtime in the admin UI — no re-seed happens, so seed-time filtering would show an empty catalog until the next deploy; (b) rows must exist for the fingerprint/operator-toggle machinery and for `BMESSAGES` history; (c) `app:seed` runs on every container start and resurrects absent rows anyway. Picker + suggested: **fully agree.** |
| AC 3: "Globale Defaults für User ohne eigene Auswahl, kein Einfrieren; lokales Whisper als Katalogeintrag" | **Agree.** Note a mitigation already shipped (resolution skips unusable per-user bindings), so the remaining harm is (a) the UI lies about the effective default, (b) the freeze recreates the problem on every registration and every "Select suggested models" click. Whisper-as-catalog-entry: agree, Piper is the precedent. |
| AC 4: "CLI: app:model:enable/disable --provider" | **Exists per-model already** (chart uses it) — the new part is the provider dimension **plus fixing the disable semantics**: today disable deletes and the seeder resurrects. Without that fix, `--provider` would inherit a broken foundation. |
| AC 5: "Container-Defaults (Chart/Compose) konsistent" | **Mostly falls out for free**: chart `apiKeys`/`ollama.baseUrl` already drive the same env vars the availability truth reads. Remaining work: provider-level chart values, an air-gap example values file, and docs. |
| "jedes neue Cloud-Modell läuft wieder als selectable rein" | **True and it should stay true at the DB level** — with read-time filtering it becomes harmless: the row is there (selectable=1) but invisible until its provider has credentials. That is the desired behavior: add a key → models appear, no re-seed, no handwork. |
| "eigenes, scharf umrissenes Issue statt Tracker-Epic" | **Agree.** #462 is UX/config items around auth and subscription; this is a coherent engineering change with its own acceptance criteria. Draft issue text in §7. |

Missing from the feedback (added to the plan): the STT/browser story
(Web Speech is a *cloud* service in Chrome — an air-gap trap), what admins
should see (grey-out vs. hide), and the disable/seed resurrection bug.

---

## 3. Concept — "Providers of this installation"

**One principle: seed everything, filter presentation by live availability,
route by usability.** No new tables, no new flags on `BMODELS`.

### 3.1 Availability semantics (per provider, install-wide)

| Provider type | *Configured* means | Examples |
| --- | --- | --- |
| Keyed cloud | Key present in `ProviderKeyStore` (env-bootstrapped or UI-saved) | OpenAI, Anthropic, Groq, Gemini, Mistral, xAI, HF, TrustedTokens |
| Credential-pair cloud | Both values present | Higgsfield, Cloudflare, TheHive, ElevenLabs |
| Local URL | URL set AND reachable | Ollama (+ per-model: pulled), Triton, Piper/`SYNAPLAN_TTS_URL` |
| OpenAI-compatible endpoints (CORE-1) | ≥1 endpoint registered in DB | LocalAI, vLLM, LiteLLM |
| Local Whisper (new) | `WHISPER_ENABLED` + binary + model file present | whisper.cpp |

This is exactly what `ProviderRegistry` + `isAvailable()` compute today; the
work is exposing it coherently, not building it.

### 3.2 Consumers and their rules

| Surface | Rule |
| --- | --- |
| `GET /api/v1/config/models` | Only models of available providers (plus a `providers` summary block: name, available, reason). `?includeUnavailable=1` for admin views (returns per-model availability + reason, e.g. `no_key` / `not_pulled`). Ollama models that are not pulled count as unavailable. |
| Chat picker, "Again with…", widget/prompt model dropdowns, planner picker | Consume the filtered endpoint — no frontend logic change beyond empty states. |
| "Select suggested models" + registration defaults | Recommend only within available providers: walk `PROVIDER_DEFAULTS` restricted to available, per-capability fallback through `PREFERENCE_ORDER`; a capability with no available provider stays **unset** and the UI says "not available on this installation". |
| Registration | **Stop writing per-user rows entirely** (drop the freeze); resolution falls through to the global default, which boot-time auto-repair already keeps sane. |
| Admin catalog (`/ai/models` edit tab) | Show ALL models, unavailable entries greyed with a badge — "no key configured" for keyed providers, "not pulled" for Ollama models — linking to `/admin/setup`. Admins must see what they *could* get. |
| Admin model health (#1516) | Unchanged — health is a routing preference, not a visibility gate. |
| Tombstone (#1521) | Unchanged (chat-scoped). |
| CLI | `app:provider:list` (availability table); `app:model:enable/disable --provider <name>`; disable becomes **soft** (`BACTIVE=0`, `BSELECTABLE=0`, row kept — survives re-seed via operator-toggle preservation). No deletion path — rows are never removed. |
| Charts/compose | Availability derives from the same env vars — nothing mandatory. Add: provider-level enable/disable values, an `examples/values-airgap.yaml`, docs. |

### 3.3 Why filter at read time (and not seed time)

1. Admin saves a key in the UI → `ChatReadinessService::invalidate()` fires →
   models appear within seconds. Seed-time filtering would require a re-deploy.
2. `BMESSAGES` rows reference BIDs; absent rows break history and billing.
3. `app:seed` runs at every container start; absent rows are re-inserted.
   Read-time filtering makes that harmless instead of fighting it.
4. The 30–60 s caches already bound the probe cost; the picker endpoint adds
   one cached lookup, not per-request provider probes.

### 3.4 Local Whisper as a catalog entry

- New service `Whisper` (display "Whisper (local)"), tag `sound2text`,
  `priceIn/Out = 0`, `providerId` = whisper.cpp model name (`base` initially;
  more sizes later if wanted).
- Thin `WhisperProvider` wrapping the existing `WhisperService`;
  `isAvailable()` = enabled + binary + model file present.
- SOUND2TEXT can then bind to it like any model: uniform resolution, appears
  in the STT picker on air-gapped installs, `speechToTextAvailable` and
  `hasConfiguredSttProvider()` stop needing special cases. Billing stays
  zero/bypassed as today.

---

## 4. Installation tree — expected setups

```text
Which AI does this installation use?
│
├─ A. "Just try it" (Linux beginner, laptop)
│     docker compose up -d              → Ollama + AUTO_DOWNLOAD (bge-m3;
│     no keys, no config                  chat opt-in via ENABLE_LOCAL_GPT_OSS)
│     RESULT: picker shows ONLY pulled Ollama models; STT = local Whisper;
│             tombstone guides to /admin/setup if no chat model is pulled.
│
├─ B. Local-first + 1-2 cloud keys (typical hoster)
│     Setup as A, then: Admin → AI Providers → paste Groq/OpenAI key
│     RESULT: that provider's models appear in pickers within seconds;
│             "Select suggested models" recommends only within {ollama, groq}.
│
├─ C. Kubernetes hoster (charts)
│     values: apiKeysSecretRef, ollama.baseUrl or triton.url,
│             optional models.enabled/disabled to narrow further
│     RESULT: same availability derivation; init-script model handwork
│             becomes optional instead of mandatory.
│
└─ D. Air-gapped (3-4 models total)
      OLLAMA_BASE_URL=… (models pre-pulled), no cloud keys,
      WEB_SPEECH_ENABLED=false   ← Chrome's Web Speech sends audio to Google!
      Local Whisper (STT) + Piper (TTS) catalog entries
      RESULT: users see 3-4 models and nothing else, STT works on every
              browser via record→upload→whisper.cpp; zero model handwork.
```

The happy path (A/B) requires **no CLI and no values file** — availability
does the work. The power path (C/D) keeps every existing knob and gains
provider-level ones.

---

## 5. Development plan

Ordered so the partner-sized piece is independent and first-mergeable.
Every PR passes the full gate (`make lint && make -C backend phpstan && make test`
+ frontend checks); frontend changes are `ota-candidate`, backend-only ones
`backend-only` per the mobile-impact policy.

### PR A — CLI provider dimension + disable-semantics fix

- `app:provider:list`: table of provider / credential source (env, UI, none) /
  available / model counts. Reuses `ChatReadinessService`.
- `app:model:enable|disable --provider <name>` (all catalog models of a
  provider); keep per-model keys.
- **Change `app:model:disable` to soft-deactivate** (`BACTIVE=0`,
  `BSELECTABLE=0` — operator-owned, survives re-seed). The deletion behavior
  is removed entirely (decided 2026-08-26): rows referenced by `BMESSAGES`
  must never disappear, and the seeder would resurrect them anyway.
- `synaplan-charts`: `providers.enabled/disabled` values mapped to the new
  flags in `51-init-models.sh`; `examples/values-airgap.yaml`.
- Tests: command tests + a seeder round-trip test proving a disabled model
  stays disabled after `app:seed`.
- **Effort:** S–M. **No behavior change** for installs not using the CLI.

### PR B — availability-filtered read surfaces *(core team)*

- `ConfigController::getModels`: filter by `usableProviders()` (+ Ollama
  pulled-model granularity), add `providers` summary block,
  `?includeUnavailable=1`.
- OpenAPI annotations → `make -C frontend generate-schemas` → `vue-tsc`.
- Frontend: empty-state copy for capabilities with no available model
  (all four locales); admin catalog tab uses `includeUnavailable=1` and greys
  unavailable providers with a badge → link to `/admin/setup`.
- Tests: controller tests for keyed/keyless matrices; frontend picker tests.
- ⚠️ Check `tests/Characterization/` snapshots (routing contract) for drift.
- **Effort:** M.

### PR C — un-freeze defaults *(core team)*

- `initializeNewUserDefaults()`: stop writing per-user rows at registration.
- `resetUserDefaults()` (the explicit button): recommend within available
  providers only (walk `PROVIDER_DEFAULTS` restricted by availability,
  fallback through `PREFERENCE_ORDER`).
- Frontend `/ai/models` choice tab: show the **effective** default
  ("Default — Claude Sonnet 5") when no user override exists.
- Existing frozen rows in the field: **leave them** (resolution already skips
  unusable ones); document. No migration needed.
- **Effort:** M. Depends on PR B for the availability lookup shape.

### PR D — local Whisper catalog entry *(core team)*

- Catalog row (service `Whisper`, tag `sound2text`, price 0) + thin
  `WhisperProvider`; `isAvailable()` = enabled + binary + model present.
- SOUND2TEXT binding resolution + `MessagePreProcessor` uses the binding
  uniformly (keep the current behavior as fallback for unmigrated installs).
- `speechToTextAvailable` derives from availability instead of bespoke checks.
- **Effort:** M. Independent of B/C.

### PR E — documentation *(after A–D land)*

- `synaplan-docs`: new page **"AI Providers & Models"** (the concept: add a
  key → models appear; the installation tree from §4) and a dedicated
  **"Air-gapped installation"** guide (Ollama pre-pull, `WEB_SPEECH_ENABLED=false`
  rationale, Whisper/Piper, chart values). Update `administration.md`,
  `hosting.md`, `kubernetes.md`, `quickstart.md`. Register in `index.php`.
- In-repo: `docs/CONFIGURATION.md` provider table gains the availability
  column; `docs/MIGRATIONS.md` notes soft-disable semantics.

### Explicitly out of scope

- Per-user provider keys (keys stay install-wide).
- New health/outage logic (#1516 covers it).
- OpenAI-compatible endpoint registry (CORE-1, in progress in PR #1299) —
  but PR B must treat it as a provider whose availability is DB-backed.

---

## 6. Open questions for discussion

All resolved 2026-08-26 (see the decisions block at the top):

1. **Never delete; hide for users, grey + badge for admins.**
2. **Ollama:** same concept — users see only pulled models, admins see all
   rows with a "not pulled" badge.
3. **Whisper** is the `BSERVICE` name.
4. **No ownership split** — everything is implemented in this workspace;
   the issue is opened by us.
5. **`AI_PROVIDERS_ALLOWLIST` env: deferred** (YAGNI — key presence already
   expresses intent).

---

## 7. GitHub issue — opened as #1586 (2026-08-26); text below kept for reference

> **Title:** Model catalog must reflect the providers of this installation
>
> **Context.** Every install seeds the full 117-model catalog as selectable.
> On installs with few or no cloud keys (on-prem, air-gapped, local-first
> hosters) users pick from dozens of models that cannot answer. The
> availability truth already exists (`ChatReadinessService::providerAvailability()`,
> `ModelConfigService::usableProviders()`) and the routing path uses it —
> the catalog surfaces do not. Related: #462 (on-prem tracker; this aspect
> was never part of it), #1521 (no-provider tombstone), #1516 (health).
>
> **Example install (air-gapped):** Ollama with 3 pulled models, no cloud
> keys, `WEB_SPEECH_ENABLED=false`. Users should see exactly those models —
> today they see the whole catalog.
>
> **Acceptance criteria**
> 1. Provider availability is the backend truth already computed today
>    (credentials/URL present; Ollama = model pulled) — consumed, not
>    reimplemented, and never per-model handwork.
> 2. `GET /api/v1/config/models`, all model pickers, and "Select suggested
>    models" only show/recommend models of available providers. Admin views
>    show the full catalog with unavailable providers greyed out.
>    Seeding stays provider-agnostic; filtering happens at read time, so
>    saving a key in the admin UI surfaces its models without a re-deploy.
> 3. New users get no frozen per-user default rows; global defaults apply
>    until a user explicitly chooses. Local whisper.cpp becomes a catalog
>    entry so SOUND2TEXT can bind to it (air-gapped STT on every browser).
> 4. CLI: `app:provider:list`, `app:model:enable|disable --provider <name>`;
>    disable is a soft-deactivate that survives `app:seed` (today's disable
>    deletes the row and the next container start resurrects it). Catalog
>    rows are never deleted — disabled/unavailable entries are greyed out or
>    hidden and reappear as soon as their provider gets credentials.
> 5. Helm chart/compose expose provider-level enable/disable consistent with
>    the CLI, plus an air-gap example values file; docs updated.

---

## 8. Test traps to respect (from AGENTS.md, verified relevant)

- `tests/Characterization/` routing snapshots will drift if resolution
  behavior changes (PR C touches `resetUserDefaults`) — re-record and review.
- `BCONFIG` defaults are bootstrap-only — PR C must not rely on changing a
  seeder value to affect existing installs (it doesn't: it changes code paths).
- OpenAPI changes (PR B) → regenerate Zod schemas → `vue-tsc`.
- All four locales for every new UI string (empty states, badges).
- Full unfiltered gate before every commit; frontend PRs classify as
  `ota-candidate` in `.github/mobile-impact-policy.json` terms.
