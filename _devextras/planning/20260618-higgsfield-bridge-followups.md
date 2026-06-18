# Higgsfield Bridge — Deferred Review Follow-ups (PR #1114)

**Date:** 2026-06-18
**Status:** Planned — not started
**Origin:** Code review "NEGATIVBEFUNDE – PR #1114 (feat/higgsfield bridge)".
The review's 25 findings were fixed in `feat/higgsfield-bridge` **except** the
8 items below, which are larger architectural / product decisions and were
deliberately deferred so the bridge could land. This document is the backlog
for them.

**Scope:** Backend (`backend/src`) + a small amount of seeder/config; one
frontend-adjacent dev-experience item. **Out of scope:** the already-landed
review fixes (window.confirm, Zod schemas, OpenAPI responses, useNotification,
webhook idempotency/typehints, getStatus metrics, docblocks, reachability
guard, top-up quantity, password field, stable error code, tests).

---

## Summary table

| # | Item | Type | Risk | Effort | Priority |
|---|------|------|------|--------|----------|
| 8  | Blocking `sleep()` in `HiggsfieldProvider::pollUntilTerminal()` | Architecture | High (worker exhaustion) | L | **P1** |
| 9  | Silent 10% markup for existing users | Product / billing | High (revenue/trust) | M | **P1** |
| 18 | `AiFacade` hard-coupled to `HiggsfieldCredentialResolver` | Architecture | Medium | M | **P2** |
| 12 | IMG2VID default points to Higgsfield without a key | Config / UX | Medium | S | **P2** |
| 11 | Per-user key invisible without platform key (`isAvailable()`) | UX gap | Medium | M | **P2** |
| 25 | Hardcoded DE/EN user strings in backend services | i18n / arch | Low | L | **P3** |
| 21 | Image-to-video not testable on `APP_URL=localhost` | Dev experience | Low | S | **P3** |
| 5  | `SubscriptionController::createTopupSession()` business logic in controller | Code quality | Medium (payment path) | M | **P3** |

`S` ≤ half a day, `M` ≈ 1–2 days, `L` ≈ 3+ days.

---

## #8 — Blocking `sleep()` in the request worker (P1)

**Problem.** `HiggsfieldProvider::pollUntilTerminal()` calls
`sleep($this->pollIntervalSeconds)` in a loop. For video the cap is
`240 × 3s = 12 min`, blocking a FrankenPHP/FPM worker for the whole duration of
the open request. Under load this exhausts the worker pool and trips
proxy/HTTP timeouts. It is inconsistent with the existing async pattern
(`startVideoGeneration` for Google Veo).

**Target design.** Make Higgsfield generation asynchronous like Veo:

1. Add a `startVideoGeneration()` / `startImageGeneration()` path that submits
   the job to Higgsfield and returns the provider job id immediately (no poll
   loop in the request).
2. Persist the job id + status on the message (mirror how Veo stores its
   operation handle).
3. Move polling into a Messenger handler / scheduled worker (or reuse the
   existing media-poll mechanism Veo uses) that flips the message to terminal
   when Higgsfield reports done/failed.
4. Keep the synchronous `pollUntilTerminal()` only for short image jobs **if**
   they are reliably sub-second; otherwise route everything through async.

**Acceptance.**
- No request worker blocks > ~1s on Higgsfield.
- A 12-minute video generation no longer holds a worker.
- Existing `HiggsfieldProviderTest` still green; add a test asserting the
  submit call returns without waiting (inject a fake clock and assert zero
  `sleep`).

**Touch points.** `backend/src/AI/Provider/HiggsfieldProvider.php`,
the Veo async handler for reference, `MediaGenerationHandler`, Messenger config.

---

## #9 — Silent 10% markup applied to all existing users (P1)

**Problem.** `RateLimitService::checkCostBudget()` applies
`DEFAULT_MARKUP_PERCENT = 10.0` (`chargedCost = rawCost × 1.10`) to every tier
immediately. Existing users' effective budget shrinks ~9% with no migration or
announcement.

**This is a product/billing decision, not a pure code fix.** Options to put in
front of product:

- **(a) Grandfather** existing users at 0% markup (store a per-user/per-tier
  markup, default 10% for new, 0% for accounts created before a cutoff).
- **(b) Raise the base budgets** by the markup so the net budget is unchanged.
- **(c) Keep 10% but announce it** (changelog + in-app notice) and bump budgets
  for paid tiers to compensate.

**Implementation notes (once decided).**
- Markup already reads from `BCONFIG` group `BILLING` (`MARKUP_CONFIG_GROUP`)
  with a hard fallback constant — make the effective value per-tier and/or
  per-cohort instead of one global constant.
- Add a migration/seed for the chosen policy; document in the billing seed.

**Acceptance.** Decision recorded here; budgets for existing users do not
silently drop; `RateLimitServiceTest` covers the chosen policy.

**Touch points.** `backend/src/Service/RateLimitService.php`, billing seed,
changelog.

---

## #18 — `AiFacade` coupled to a concrete provider (P2)

**Problem.** `AiFacade::maybeInjectProviderCredentials()` hard-codes
`'higgsfield' === strtolower($providerName)` and constructor-injects
`HiggsfieldCredentialResolver`. A generic facade should not know one concrete
provider.

**Target design.** Introduce a small interface, e.g.
`PerUserCredentialResolverInterface` with `supports(string $providerName): bool`
and `resolve(?int $userId): ?array`. Tag all implementations, inject the tagged
iterator into `AiFacade`, and let the facade pick the first resolver that
`supports()` the provider. `HiggsfieldCredentialResolver` becomes one
implementation; future providers add their own without touching the facade.

**Acceptance.** `AiFacade` has no `'higgsfield'` literal and no direct
dependency on `HiggsfieldCredentialResolver`; adding a second per-user-key
provider requires no facade change; existing resolver tests still pass.

**Touch points.** `backend/src/AI/Service/AiFacade.php`,
`backend/src/AI/Credential/HiggsfieldCredentialResolver.php`, `services.yaml`
(tagging).

---

## #12 — IMG2VID default points to Higgsfield without a key (P2)

**Problem.** `DefaultModelConfigSeeder` sets IMG2VID →
`higgsfield:higgsfield-ai/dop/standard` platform-wide. With no platform
Higgsfield key, every image-to-video request fails (or the guard trips) even
though it is the configured default.

**Options.**
- Keep Higgsfield as the IMG2VID default **only** when a platform key is
  present at seed/runtime; otherwise fall back to the previous default (or leave
  IMG2VID unset so the gate gives a clear "not configured" message).
- Or make the default resolution provider-availability-aware in
  `DefaultModelConfigSeeder` / model resolution.

**Acceptance.** On a platform without a Higgsfield key, IMG2VID either resolves
to an available provider or fails with an actionable "provider not configured"
message — never a raw provider error.

**Touch points.** `backend/src/Seed/DefaultModelConfigSeeder.php`, model
resolution, `MediaGenerationHandler` guard interplay.

---

## #11 — Per-user key invisible without a platform key (P2)

**Problem.** `HiggsfieldProvider::isAvailable()` only checks
`hasPlatformCredentials()`. A user with their own valid key but no platform key
never sees Higgsfield in the `ProviderRegistry` (acknowledged in a code
comment, still a UX gap).

**Target design.** `isAvailable()` (or the registry's visibility check) must
consider per-user credentials. Because `isAvailable()` has no user context
today, this needs a user-aware availability path:

- Add an availability check that takes the current user (or resolves via the
  credential resolver) and returns true when **either** a platform **or** a
  per-user key exists.
- Thread the current user into the registry's per-request availability
  computation (the runtime-config endpoint already knows the user).

**Acceptance.** A user with only a personal key sees Higgsfield as available;
a user with neither does not; platform-key behaviour unchanged. Covered by a
provider/registry test with both credential sources.

**Touch points.** `backend/src/AI/Provider/HiggsfieldProvider.php`,
`ProviderRegistry`, runtime-config / availability computation.

---

## #25 — Hardcoded DE/EN user strings in the backend (P3)

**Problem.** `MediaGenerationHandler` (and likely others) build user-facing
messages with `'de' === $lang ? '…' : '…'` inline. There is no backend i18n
system, so multilingual strings are hand-wired in services.

**Target design.** Introduce a minimal backend i18n mechanism:

- Use Symfony's `translation` component with `messages.<locale>.yaml`
  catalogues (locales aligned with the frontend set: `en`, `de`, `es`, `tr`).
- Inject `TranslatorInterface` into services that emit user-facing text; replace
  inline ternaries with translation keys.
- Resolve the user's language from the existing per-message/user language field.

**Acceptance.** No `'de' === $lang ? …` ternaries for user-facing copy in
services; strings live in catalogues; adding a locale doesn't require touching
PHP. Start with `MediaGenerationHandler`, then sweep other offenders.

**Touch points.** `backend/src/Service/Message/Handler/MediaGenerationHandler.php`,
new `translations/`, `config/packages/translation.yaml`, service wiring.

---

## #21 — Image-to-video not testable on `APP_URL=localhost` (P3)

**Problem.** `MediaGenerationHandler::isPublicBaseUrlReachable()` (now also
rejecting private LAN ranges, see the landed #13 fix) blocks image-to-video on
the default dev `APP_URL=localhost`, so the feature can't be exercised locally.

**Options.**
- A documented dev workflow using a tunnel (e.g. cloudflared / ngrok) that sets
  `APP_URL` to the public tunnel host — add to `_devextras/planning/quick-dev-commands.md`.
- An explicit, clearly-named dev override (e.g. `MEDIA_ALLOW_LOCAL_INPUT_URL=1`)
  that bypasses the reachability guard **only** in `dev`/`test`, off by default,
  loudly logged. Never reachable in prod.

**Acceptance.** A developer can run image-to-video end-to-end locally following
a documented step; prod behaviour unchanged; the override (if chosen) cannot be
enabled in prod.

**Touch points.** `MediaGenerationHandler`, dev docs, `.env.example`.

---

## #5 — Extract top-up session creation out of the controller (P3)

**Problem.** `SubscriptionController::createTopupSession()` (~95 lines) builds
the Stripe Checkout session directly in the controller. AGENTS_DEV wants thin
controllers / fat services. It is consistent with the existing
`createCheckoutSession()`, so a partial extraction would just move the
inconsistency.

**Target design.** Extract a `StripeCheckoutService` (or extend `BillingService`)
that owns **both** subscription checkout and top-up checkout, plus the shared
`getOrCreateStripeCustomer()` / customer-payload logic. Controllers call the
service and only translate the result to HTTP.

**Why deferred.** It touches the money path, which currently has **no**
controller-level test for the outbound Stripe call in topup. Do the extraction
**after** adding characterization tests around the current behaviour (the
landed work already added budget + webhook-topup tests; add a topup-session
outbound test next, mirroring `SubscriptionControllerStripeOutboundTest`).

**Acceptance.** `createTopupSession()` and `createCheckoutSession()` are thin
(< ~50 lines), all Stripe calls live in the service, outbound tests cover both
paths, no behaviour change.

**Touch points.** `backend/src/Controller/SubscriptionController.php`,
`backend/src/Service/BillingService.php` (or new `StripeCheckoutService`),
`tests/Integration/Stripe/*`.

---

## Suggested sequencing

1. **P1 first, independently:** #8 (async) and #9 (markup decision) — both are
   user-impacting and unrelated.
2. **P2 batch:** #18 → unblocks clean multi-provider work, then #11 and #12
   (both benefit from availability/credential awareness).
3. **P3 batch:** #5 (after adding the outbound topup test), #25, #21.

Each item should ship on its own branch + PR with the standard gate
(`make lint && make -C backend phpstan && make test && docker compose exec -T
frontend npm run check:types`).
