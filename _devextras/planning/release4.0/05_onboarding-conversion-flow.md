# Feature 4 — Frictionless Onboarding & the Token Wallet

**Release:** 4.0 · **Priority:** P1 · **Status:** Planned (wallet rework 2026-06-30)
**Target:** `web.synaplan.com` (SaaS) + native apps (`synaplan-apps`, Apple/Google IAP)

> **Goal:** Convert new users — especially consumers arriving from the iOS/Android
> app — by letting them experience the *full* power of Synaplan immediately (all
> models, image/video/audio), funded by a small **Welcome Wallet** (~€5). When the
> welcome balance runs out, they top the wallet up in fixed packs (Stripe on web,
> native IAP in the app) instead of hitting padlocks. A topped-up wallet is a
> **prepaid balance for AI compute** — you buy AI calculations, not a refundable
> account balance.

---

## 0. TL;DR — what changes vs. today

Synaplan already has a mature billing spine we are **building on, not replacing**:
subscriptions (Stripe + Apple/Google IAP), channel-aware pricing & block-cross,
a markup-aware **cost-budget gate** (`RateLimitService::checkCostBudget`, behind
`COST_BUDGET_GATE_ENABLED`), and a **period-scoped** top-up (`BUSER_TOPUPS`,
`SubscriptionController::/topup`, credited by `StripeWebhookController`). Cost is
already accounted **in EUR** (`BUSELOG.BCOST` + `BILLING/MARKUP_PERCENT`).

This feature adds **one new concept**: a **persistent wallet** (a credit ledger)
that:

1. is **seeded** with a Welcome grant on first verified login,
2. is **debited** as AI usage is recorded (charged cost = provider cost × markup),
3. is **topped up** in fixed packs via Stripe (web) and native IAP (app), and
4. **never expires and is never cashed out** — it is a prepaid consumable.

| Area | Today | After Feature 4 |
|------|-------|-----------------|
| New-user trial | NEW-tier *count* limits (50 msgs, 5 images, 2 videos…) → padlocks | Welcome Wallet (~€5) → **everything unlocked** until balance hits 0 |
| Top-up | Period-scoped budget raise in **€100** steps; expires with the period | **Persistent** wallet credit in **€19.95 / €49.95** packs; never expires |
| Top-up channel | Stripe web only | Stripe web **+ Apple/Google IAP** (app), with an internal **+25%** app surcharge |
| Exhaustion UX | `LimitReachedModal` (count limit) | Same modal, balance-aware: "top up" or "subscribe" |
| Accounting unit | EUR (`BCOST`) | EUR internally; **displayed as "credits"** (1 credit = €0.01) |

Everything below is **additive and flag-gated** (`WALLET_ENABLED`, default off) so
existing subscribers, the legacy `BUSER_TOPUPS` path, and open-source/web-only
deployments see **zero behaviour change** until the flag is turned on.

---

## 1. The problem (unchanged, restated)

A user who signs up via Email or Google OIDC lands in the `NEW` tier. The moment
they try a premium capability (flagship LLM, video) they hit the NEW-tier count
limits and "locked" UI. For a consumer who just installed the app expecting an
immediate wow, that friction kills conversion.

But unconditionally unlocking expensive provider routes (OpenAI, Veo, Higgsfield,
Anthropic) invites scrapers to mint infinite accounts and drain platform credit.

The Welcome Wallet resolves both: **show, don't tell** for verified humans, with a
**finite, financially-safe** balance and hard anti-abuse guards.

---

## 2. Product model — the Wallet

### 2.1 Unit of account (locked recommendation)

- **Internal unit = EUR.** The wallet balance, grants, top-ups and usage debits are
  all stored as EUR decimals, reusing the existing cost engine (`BUSELOG.BCOST`,
  `BILLING/MARKUP_PERCENT`). This is the lowest-regression choice — no parallel
  "token cost" table, no re-pricing of every model.
- **Displayed unit = "credits", where `WALLET_CREDITS_PER_EUR = 100`** (1 credit =
  €0.01). A €5 welcome grant shows as **500 credits**; a €19.95 pack as **1 995
  credits**. Showing credits (not euros) reinforces that the balance is *prepaid
  AI usage*, not refundable money — important legally (see §6).
- The conversion rate is an operator-tunable constant so we can rebalance the
  "feel" (e.g. 1 credit = €0.005) without touching the cost engine.

> Product decision to confirm: keep "credits" as the user-facing word (matches the
> original trial copy "50 credits remaining"), vs. the looser "tokens" the PO used
> verbally. Recommendation: **credits** (avoids confusion with LLM tokens). The
> word "tokens" stays internal/marketing-only.

### 2.2 What the wallet is (and is NOT)

- ✅ A **prepaid balance for AI computation**. Spending it runs real provider calls.
- ✅ **Persistent** — purchased credit does **not** expire (contrast: the legacy
  `BUSER_TOPUPS` raise expired with the billing period).
- ❌ **Not e-money, not refundable to cash, not transferable** between accounts.
- ❌ **Not withdrawable.** There is no path from wallet → money. (See §6 legal.)

### 2.3 Ledger design (auditable, idempotent)

A double-entry-lite **append-only ledger** plus a cached balance on the wallet row:

- **`BWALLET`** (one per user): `BUSERID` (unique), `BBALANCE_EUR` (decimal 10,2),
  `BLIFETIME_CREDITED_EUR`, `BLIFETIME_DEBITED_EUR`, `BCURRENCY` (EUR),
  `BUPDATED`. Balance is a **cached** sum of the ledger for O(1) gate checks.
- **`BWALLET_LEDGER`** (append-only): `BID`, `BUSERID`, `BTYPE`
  (`welcome_grant` | `topup_stripe` | `topup_apple` | `topup_google` |
  `usage_debit` | `adjustment` | `reversal`), `BAMOUNT_EUR` (signed: credits +,
  debits −), `BBALANCE_AFTER_EUR`, `BCURRENCY`, `BSOURCE_REF` (Stripe session id /
  IAP transaction id / `BUSELOG.BID`), `BCHANNEL` (`web`|`apple`|`google`|`system`),
  `BCREATED`, `BMETADATA` (JSON).
- **Idempotency:** UNIQUE on `(BTYPE, BSOURCE_REF)` — a webhook retry, an IAP
  notification replay, or a double-recorded `BUSELOG` row can never credit/debit
  twice. (Mirrors the existing `uniq_topup_session` guard.)
- **Concurrency:** balance mutations go through a single
  `WalletService::applyEntry()` that runs the insert + cached-balance update in one
  transaction with `SELECT … FOR UPDATE` on the `BWALLET` row, so concurrent
  workers (async media jobs!) can't race the balance.

Schema lands via **Doctrine migration** with raw, comparator-free, idempotent SQL
(`CREATE TABLE IF NOT EXISTS …`) per the Galera rules in `AGENTS_DEV.md` §"Production
Platform Specifics". Catalog/defaults (grant size, pack prices, conversion rate)
live in **idempotent seeders** (`App\Seed\*`), not migrations.

### 2.4 How the wallet interacts with subscriptions & the cost-budget gate

The wallet **composes** with the existing per-period cost budget rather than
replacing it. Effective spend allowance, evaluated in `checkCostBudget()`:

```
allowance = (subscription monthly budget left this period)   ← existing, period-scoped
          + (persistent wallet balance)                       ← NEW
```

- **Pay-as-you-go users (NEW / no active subscription):** monthly budget = €0, so
  spend draws **straight from the wallet** (welcome grant + top-ups). This is the
  consumer flow the PO described.
- **Subscribers (PRO/TEAM/BUSINESS):** keep their monthly budget; the wallet is an
  overflow / always-available prepaid balance they may also top up. Draw order:
  **period budget first, then wallet** (so subscribers don't burn prepaid credit
  while they still have included budget).
- **Debit timing:** when `RateLimitService::recordUsage()` writes a `BUSELOG` row,
  a follow-on `WalletService` debit is written for the portion of the charged cost
  that falls on the wallet (charged cost = `BCOST × markupMultiplier`). The debit
  references `BUSELOG.BID` for traceability and idempotency.

> Product decision to confirm: do **subscribers** also draw from the wallet after
> their monthly budget, or is the wallet a pay-as-you-go-only construct?
> Recommendation: **universal wallet, period-budget-first draw order** (above) — it
> is the most flexible and keeps one code path.

### 2.5 The "everything unlocked" behaviour

While `WALLET_ENABLED` is on **and** the user has a positive wallet balance (or
remaining period budget), the per-action **count** padlocks (NEW-tier `IMAGES`,
`VIDEOS`, `MAX_OUTPUT_TOKENS`, …) are treated as **PRO-equivalent / lifted** — the
spend gate (balance) becomes the single source of truth. Concretely:

- `RateLimitService::checkLimit()` for a wallet-funded user returns "allowed" for
  capability counts (it no longer enforces the NEW lifetime caps) — the wallet
  balance check in `checkCostBudget()` is what actually gates the request.
- The frontend stops greying-out premium models / media for wallet-funded users
  (`AIModelsConfiguration.vue`, chat composer capability chips).
- When the wallet AND period budget reach 0, count limits **fall back** to the
  user's real tier (NEW = local/free models only, no rich media) — graceful
  degradation, exactly as the original plan intended.

This keeps the financial blast radius bounded by the *balance*, not by trusting
the count limits — a bot with a drained €5 wallet simply can't spend more.

---

## 3. Money flow — top-ups

### 3.1 Fixed packs (replaces the €100 step)

Two fixed packs, configured as seeded catalog data (`App\Seed\WalletPackSeeder`):

| Pack | Web price (Stripe, reference) | Credit value granted | Displayed credits |
|------|-------------------------------|----------------------|-------------------|
| Small | €19.95 | €19.95 of wallet credit | 1 995 |
| Large | €49.95 | €49.95 of wallet credit | 4 995 |

This changes the existing `POST /api/v1/subscription/topup` contract (today: N ×
€100 steps) into a **pack-based** top-up (`packId: 'small'|'large'`). The legacy
"steps" path is removed behind the flag (see §7 regression note) and the
`LimitReachedModal.vue` EUR-100 step selector is replaced by the two packs.

### 3.2 Web (Stripe) flow

1. `POST /api/v1/wallet/topup { packId }` → one-time Stripe Checkout
   (`mode=payment`, `unit_amount` = pack web price, metadata
   `{ type: 'wallet_topup', pack_id, credit_eur }`).
2. `checkout.session.completed` webhook → `WalletService::creditFromStripe()`
   writes a `topup_stripe` ledger entry (idempotent on session id).
3. Stripe is **Merchant of Record** on web → Stripe Tax/OSS handles VAT, Stripe
   issues the invoice/receipt. Reuses the existing
   `billing_address_collection`/`tax_id_collection`/`automatic_tax` wiring already
   on the subscription + topup checkouts.

### 3.3 App (native IAP) flow + the internal +25% surcharge

The app **must not** open Stripe web checkout (Apple 3.1.1 / Google Play). Top-ups
in the app go through **consumable IAP products**, validated server-side by the
existing `MobilePurchaseService` seam (extended for consumables).

**The +25% app surcharge (internal — never surfaced to users):**

- The **store price** of each top-up SKU is set ~**25% above** the web reference
  price to absorb Apple/Google fees, exactly mirroring the ≈30% subscription
  commission-baking already documented in `docs/PAYMENTS_CHANNELS.md`.
- The **credit value granted is the web reference value**, identical across
  channels. App users pay more money for the *same* credits; the wallet is credited
  with the web value.

| Pack | Web price | App store price (≈ +25%) | Credit value granted (both channels) |
|------|-----------|--------------------------|--------------------------------------|
| Small | €19.95 | ≈ €24.99 (store SKU) | €19.95 → 1 995 credits |
| Large | €49.95 | ≈ €59.99 (store SKU) | €49.95 → 4 995 credits |

- Implementation: a new env-config map `WALLET_IAP_TOPUP_SMALL` / `_LARGE`
  (store product IDs) → mapped to a **credit-EUR value** by a `WalletPackService`
  (the consumable mirror of `IapPricingService`). The server credits the mapped
  value regardless of the store-charged amount.
- **Internal-only:** `WALLET_IAP_SURCHARGE_PERCENT = 25` is used only to *derive
  the recommended store price* in launch tooling/docs. **No user-facing copy,
  tooltip, invoice line, or API field ever mentions the surcharge.** Store prices
  are set in App Store Connect / Play Console; the app shows the store's localized
  price as-is.
- IAP top-ups are **consumables** (each purchase grants credit and is "consumed"),
  not subscriptions — so block-cross / one-owner subscription rules do **not**
  apply, but **replay protection** (one transaction id credited once) does, via the
  ledger's `(BTYPE, BSOURCE_REF)` unique key + `MobilePurchaseService` verification.
- Apple/Google are MoR on IAP → they issue the receipt; B2B VAT-ID invoices remain
  **web/Stripe only** (inform in-app, do not steer — same anti-steering rule as
  subscriptions).

> Apple Pay / Google Pay on **web** (as Stripe payment methods) are a later,
> separate add (just a `payment_method_types` entry on the Stripe Checkout). They
> are **not** native IAP and carry **no** surcharge.

### 3.4 Welcome grant

- On the **first verified** login, `WalletService::grantWelcome()` writes a single
  `welcome_grant` ledger entry of `WALLET_WELCOME_GRANT_EUR` (default **€5.00** →
  500 credits), idempotent on `(welcome_grant, user:{id})` so it is granted **once
  ever** per account.
- "Verified" = OIDC (Google) verified email **or** a clicked email-verification
  link for local signups (see §4). The grant is **not** written at row-insert time
  — only after verification — which is the anti-abuse gate from the original plan.
- The welcome grant is a **promotional credit**: non-refundable, no cash value,
  subject to the anti-abuse guards in §5.

> Product decision to confirm: should the **welcome grant** expire (e.g. 30 days)
> while **purchased** credit never expires? Recommendation: purchased credit never
> expires; welcome grant **does not expire** either (PO's stated preference) but is
> hard-gated by the §5 anti-abuse rules. Revisit only if abuse appears.

---

## 4. Onboarding flow (verified-then-funded)

The wallet plugs into the **existing** auth flow (`AuthController`,
`GoogleAuthController`, email verification via `/verify-email-callback`). No new
auth providers; Apple Sign-In on web stays out of scope (Apple appears only as an
IAP channel).

```
Email signup ──▶ POST /auth/register (reCAPTCHA today; + Turnstile §5)
   │                 └─ user row: level=NEW, emailVerified=false   (NO grant yet)
   ▼
Verification email (24h token) ──▶ /verify-email-callback?token= ──▶ /auth/verify-email
   │                 └─ emailVerified=true  ──▶  WalletService::grantWelcome()  ✅ €5
   ▼
First chat ──▶ everything unlocked, wallet meter shows "500 credits"

Google OIDC ──▶ callback ──▶ new user level=NEW, emailVerified=true (Google-verified)
   │                 └─ WalletService::grantWelcome()  ✅ €5 immediately
   ▼
(App) install ──▶ sign in to web.synaplan.com account ──▶ same wallet, same balance
```

Key points:

1. **Grant on verification, not on insert** — closes the "insert a row, grab the
   grant" hole. Local signups must click the link; OIDC is implicitly verified.
2. **One wallet per account, cross-channel.** The app and web share the *same*
   server account → the *same* wallet. Buying credit in the app tops up the wallet
   the web sees, and vice-versa. (The app cannot buy via Stripe; the web cannot buy
   via IAP — but both spend the one balance.)
3. **Exhaustion UX** reuses the centralized `useLimitCheck()` →
   `LimitReachedModal.vue` path already wired into `ChatView.vue` for the
   `COST_BUDGET_EXCEEDED` SSE code. The modal becomes **balance-aware**: "You've
   used your free credits — top up (1 995 / 4 995) or go PRO." On native, the
   top-up CTA routes to **IAP**, never Stripe (same `isNativeApp()` guard as
   `SubscriptionView.vue`).
4. **Wallet meter** in the shell (sidebar/header): a small `WalletMeter.vue` reading
   `GET /api/v1/wallet` (or the existing-but-unused `getBudget()` extended with
   `wallet_balance` / `wallet_credits`). Shows credits remaining + a top-up button.

---

## 5. Anti-abuse / bot defenses (crucial — a real €5 is at stake per account)

The wallet is heavily guarded; the financial exposure per fresh account is bounded
by the €5 grant, but we still block farming:

1. **Verification gate (above):** no grant until OIDC-verified or email-link-clicked.
2. **Cloudflare Turnstile** on `/api/v1/auth/register` and the resend-verification /
   magic endpoints, **in addition to** the existing reCAPTCHA v3 (`RecaptchaService`).
   New: `TurnstileService` + a runtime-config `turnstile.enabled/siteKey`, verified
   server-side before the `BUSER` row is created. (Turnstile is **not** integrated
   today — this is net-new; reCAPTCHA stays.)
3. **IP / device rate limit on grant:** max **3 welcome grants per IP / 24h**
   (reuse the Redis `RateLimitService` / cache pattern already used for checkout +
   webhook rate limits). The grant — not just account creation — is the thing we
   cap.
4. **Financially-safe grant size:** €5 (500 credits) — enough for a genuine wow
   (a couple of videos *or* a long flagship chat), too little for a scraper to
   extract commercial value before needing a fresh verified email **and** IP.
5. **Per-capability sanity caps inside the trial:** even with balance, keep a soft
   cap (e.g. ≤ N videos) so a single drained wallet can't be turned into one
   expensive asset; tuned via config. (Optional — flag for product.)
6. **Replay/ownership protection** on every credit source via the ledger unique key
   (Stripe session id, IAP transaction id) and `MobilePurchaseService`'s existing
   "one receipt → one account" rule.

---

## 6. Legal & tax (must be clean)

- **Nature of the product:** credits are a **prepaid consumable for AI computation**.
  Purchasing credit is purchasing the *service capacity*, not depositing money. ToS
  must state: credits are **non-refundable**, **have no cash value**, are **not
  transferable**, **cannot be exchanged back to money**, and are consumed by AI
  usage. (Statutory withdrawal rights for digital content consumed immediately with
  consent are handled by the standard consent checkbox at checkout — coordinate
  with legal.)
- **Welcome grant** is a free promotional credit with the same non-refundable,
  no-cash-value terms; clearly labelled "free credits".
- **VAT / Merchant of Record:**
  - **Web (Stripe):** Synaplan is MoR; Stripe Tax/OSS calculates & collects VAT;
    Stripe issues the invoice/receipt. B2B VAT-ID supported (VIES).
  - **App (Apple/Google IAP):** the store is MoR — collects/remits consumer VAT,
    pays net after the 15–30% cut; issues a consumer receipt. Book revenue per
    channel separately (as already documented in `docs/PAYMENTS_CHANNELS.md`).
- **The +25% app price is internal cost-recovery only.** It is *never* presented as
  a "fee", "surcharge", or "app tax" in UI, invoices, receipts, or API. The user
  sees only the store's localized price and the credits granted.
- **Update `docs/PAYMENTS_CHANNELS.md`** with a new "Wallet top-ups (consumables)"
  section describing the packs, the credit-value-is-channel-independent rule, and
  the per-channel MoR/VAT treatment.

> Action: this section is the **legal review ask**. Implementation can proceed on
> the technical model in parallel, but the ToS/refund/consent copy and the
> store-product tax categories must be signed off before go-live.

---

## 7. Implementation — sprints

Recommended order: **W0 → W1 → W2 → W3 → W4 → W5**. Each sprint is independently
shippable and CI-green on its own; everything is gated by `WALLET_ENABLED`
(default **off**) so partial merges never change live behaviour.

### W0 — Spike & sign-off (no code that ships behaviour)
- Confirm the open product decisions (credits vs tokens wording; subscriber draw
  order; grant expiry; per-capability trial caps).
- Legal sign-off on §6 copy + store tax categories.
- Lock the conversion rate (`WALLET_CREDITS_PER_EUR`) and pack prices.
- **Exit:** decisions recorded in §10; no merge required.

### W1 — Wallet backbone (backend-only, no UX)
- Migration: `BWALLET` + `BWALLET_LEDGER` (idempotent, comparator-free SQL).
- `App\Entity\Wallet`, `App\Entity\WalletLedgerEntry`, repositories.
- `App\Service\WalletService`: `applyEntry()` (txn + row lock + cached balance),
  `getBalance()`, `creditFromStripe()`, `creditFromIap()`, `debit()`,
  `grantWelcome()` — all idempotent on `(type, sourceRef)`.
- `App\Seed\WalletPackSeeder` (packs + grant size + conversion rate into BCONFIG
  group `WALLET`); `WalletPackService` (pack ↔ price ↔ credit-value, IAP product
  map — the consumable mirror of `IapPricingService`).
- Flag `WALLET_ENABLED` (Autowire `default::bool:WALLET_ENABLED`, default false).
- **No caller wired yet.** Pure unit-tested service + schema.

### W2 — Spend wiring (debit on usage) + gate integration
- `RateLimitService::recordUsage()` → after the `BUSELOG` insert, when
  `WALLET_ENABLED`, write the wallet `usage_debit` (charged cost, ref `BUSELOG.BID`).
- `RateLimitService::checkCostBudget()` → add `wallet_balance` to the allowance and
  return `wallet_balance` / `wallet_credits` in the payload.
- `RateLimitService::checkLimit()` → when wallet-funded, lift NEW-tier count caps
  (PRO-equivalent) and fall back to real tier at zero balance (§2.5).
- Keep the **429 + `COST_BUDGET_EXCEEDED`** contract (do **not** invent a 402 — the
  frontend already keys off this code). Add `topup_packs` to the payload.
- **No new top-up purchase path yet** — spend + gate only.

### W3 — Stripe top-up (web) → wallet
- New `WalletController`: `GET /api/v1/wallet` (balance + credits + packs),
  `POST /api/v1/wallet/topup { packId }` (one-time Checkout).
- `StripeWebhookController`: route `type: 'wallet_topup'` →
  `WalletService::creditFromStripe()` (idempotent on session id). Keep the legacy
  `type: 'topup'` branch alive for back-compat (see §8).
- Full OpenAPI annotations; `make -C frontend generate-schemas`.

### W4 — Frontend: meter, packs, unlock, exhaustion
- `WalletMeter.vue` in the shell (credits remaining + top-up button), reads
  `GET /api/v1/wallet`.
- Rework `LimitReachedModal.vue`: replace the €100-step selector with the two packs;
  make it balance-aware; native guard routes top-up to IAP, web to Stripe.
- `AIModelsConfiguration.vue` + chat composer: remove premium padlocks for
  wallet-funded users; restore at zero balance.
- `SubscriptionView.vue` / `UsageStatistics.vue`: show wallet alongside subscription.
- i18n: **all four locales** (`en`, `de`, `es`, `tr`) for every new string
  (credits, top-up packs, exhaustion, welcome). **No surcharge copy.**

### W5 — Native IAP top-ups + anti-abuse + welcome grant
- Extend `MobilePurchaseService` for **consumable** top-ups: `POST /api/v1/iap/topup`
  verify → `WalletService::creditFromIap()` (replay-protected via ledger unique key).
  Apple ASSN / Google RTDN paths handle refunds → `reversal` ledger entries.
- Store SKUs + recommended store prices (≈ +25%) documented in
  `synaplan-apps/docs/LAUNCH_CHECKLIST.md`; `WALLET_IAP_TOPUP_*` env map.
- `grantWelcome()` wired into the verification + OIDC-new-user paths.
- `TurnstileService` + runtime config + register/resend verification; **3 grants /
  IP / 24h** cap.
- **Rollout:** flip `WALLET_ENABLED=true` on web first, then app once IAP store
  products are live; grandfather existing users (one-time backfill grant is a
  product decision — default: existing users get the welcome grant once, gated by
  the same idempotency key).

---

## 8. Regression guardrails (do NOT break the live billing spine)

- **`WALLET_ENABLED` default off.** With the flag off, `checkCostBudget`,
  `checkLimit`, `recordUsage`, the legacy `/topup` (€100 steps), and `BUSER_TOPUPS`
  behave **exactly** as today. This is the primary safety net.
- **Legacy period top-ups coexist.** `BUSER_TOPUPS` + `sumForUserInPeriod()` stay
  wired in `checkCostBudget` for back-compat; the new wallet balance is **added**,
  not substituted. Any unexpired legacy top-up is still honored. New purchases go to
  the wallet. Document the deprecation; do not delete `BUSER_TOPUPS` in 4.0.
- **Keep the 429 / `COST_BUDGET_EXCEEDED` contract.** The frontend
  (`ChatView.vue`) and tests key off this exact code and the SSE shape — do not
  change the status code or rename the code string.
- **Markup stays single-sourced.** Debits use the *same* `BILLING/MARKUP_PERCENT`
  multiplier as `checkCostBudget`/`recordUsage`. Never compute a second markup.
- **Open-source / web-only deployments:** `WALLET_ENABLED` off + Stripe/IAP
  unconfigured → no wallet, no grant, unlimited (Open Source Mode) — unchanged.
- **Async media jobs (Feature 1):** debits must be written at the **terminal**
  (completed) state of a `MediaJob`, idempotent on `BUSELOG.BID`, and a
  **cancel/failure must not debit** (or must `reversal` a provisional hold). Align
  with Feature 1 Sprint E "billing at terminal state (#1146) + cancel-refund
  correctness" — this is a shared seam, build them together.
- **Characterization tests:** re-record `tests/Characterization/__snapshots__/`
  only if routing/classifier output legitimately changes (it shouldn't here);
  review every diff line.

---

## 9. Test procedures (the gate is non-negotiable — `AGENTS_DEV.md`)

Every sprint finishes green on the **full, unfiltered** gate:

```bash
make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types
```

### Backend (PHPUnit) — new/updated tests

| Area | Test | Asserts |
|------|------|---------|
| Ledger | `WalletServiceTest` (Unit) | credit/debit math; cached balance == SUM(ledger); signed amounts |
| Idempotency | `WalletServiceIdempotencyTest` | duplicate `(type, sourceRef)` credited/debited **once**; concurrent retry race (UniqueConstraintViolation → no-op) |
| Welcome grant | `WalletServiceTest::grantWelcome` | granted once per user; not before verification; respects 3/IP/24h cap |
| Conversion | `WalletPackServiceTest` | EUR ↔ credits; pack→price→credit-value; **IAP product→web credit value** (channel-independent credit) |
| Gate | `RateLimitServiceCostBudgetTest` | allowance = period budget + wallet; subscriber draw order (budget first); wallet-funded lifts count caps; zero-balance falls back to tier |
| Spend | `RateLimitServiceRecordUsageWalletTest` | debit written with correct markup; references `BUSELOG.BID`; not double-debited |
| Stripe webhook | `StripeWebhookControllerWalletTest` (Integration, mocked Stripe) | `type:'wallet_topup'` → `topup_stripe` entry; idempotent on session id; legacy `type:'topup'` still credits `BUSER_TOPUPS` |
| IAP | `MobilePurchaseServiceTopupTest` (Unit, fake verifiers) | consumable verify → `topup_*` credit; replay (same txn) → no double credit; refund RTDN/ASSN → `reversal` |
| Endpoints | `WalletControllerEndpointTest` (Integration) | `GET /wallet` shape; `POST /wallet/topup` packs; auth required; flag-off → wallet hidden/unchanged |
| Flag-off regression | extend existing `BillingServiceTest` / budget tests | with `WALLET_ENABLED=false`, every existing assertion still holds |

PHPStan must pass over **`src/` and `tests/`** (the full `make -C backend phpstan`,
not a scoped path — watch the `willReturnCallback` `: ?string` trap from
`AGENTS_DEV.md`).

### Frontend (Vitest) — new/updated tests

| Component | Test | Asserts |
|-----------|------|---------|
| `WalletMeter.vue` | mount + mocked `GET /wallet` | renders credits; unlimited/zero states; top-up button |
| `LimitReachedModal.vue` | updated spec | shows two packs (not €100 steps); balance-aware copy; **native guard** routes to IAP, web to Stripe (extend `SubscriptionViewNativeGuard.spec.ts` pattern) |
| `ChatView.vue` | extend existing | `COST_BUDGET_EXCEEDED` SSE still opens the (balance-aware) modal |
| Schemas | `usageApi.spec.ts` / generated | wallet fields parse via generated Zod schema (regen after OpenAPI change) |

Stub heavy deps (Pinia/i18n/`MessageText`) per the `AGENTS_DEV.md` frontend-test note.

### E2E (Playwright) — extend `frontend/tests/e2e`
- `wallet-topup.spec.ts`: sign in → exhaust balance (mock webhook credit) →
  `LimitReachedModal` → buy "small" pack (mocked Stripe via existing
  `helpers/webhook.ts` + `helpers/billing.ts`) → balance increases → chat unlocked.
- Extend `subscription-lifecycle.spec.ts` to assert the legacy subscription path is
  untouched.

### Manual / staging
- Stripe **test mode** end-to-end (real Checkout, test card) → webhook → ledger
  credit → meter updates.
- IAP **sandbox** (Apple sandbox tester / Google license tester): consumable
  purchase at the **+25% store price** credits the **web value**; verify the
  surcharge never appears in any user-visible surface.
- Turnstile challenge on register in a real browser; verify 3/IP/24h grant cap.

---

## 10. Definition of done

- A new user (Apple/Google or verified email) lands with **no padlocks** and a
  visible **wallet meter (~500 credits)**; can generate 1–2 videos *or* a long
  flagship chat from the welcome balance.
- At zero balance, the action cleanly fails (429 / `COST_BUDGET_EXCEEDED`) and the
  balance-aware `LimitReachedModal` offers the **two packs** (web→Stripe,
  app→IAP) or PRO.
- Top-up credits land in the **persistent** wallet, **never expire**, and **cannot**
  be exchanged for cash; idempotent on every retry/replay.
- App top-ups are priced ~**+25%** at the store yet grant the **same** credits as
  web; **no user-facing surface mentions the surcharge.**
- Anti-abuse: Turnstile + verification gate + 3 grants/IP/24h block farming.
- `WALLET_ENABLED=false` ⇒ **byte-for-byte** current behaviour (legacy top-ups,
  subscriptions, open-source mode all unchanged).
- i18n complete in `en`/`de`/`es`/`tr`; `docs/PAYMENTS_CHANNELS.md` updated; legal
  sign-off recorded.
- Full gate green; E2E covers "exhaust → top up → unlocked".

---

## 11. Open product decisions (resolve in W0)

1. User-facing term: **credits** (recommended) vs "tokens".
2. Conversion rate `WALLET_CREDITS_PER_EUR` (recommended **100**, i.e. 1 credit = 1¢).
3. Welcome grant size (recommended **€5.00 / 500 credits**).
4. Do **subscribers** also draw from the wallet (recommended **yes**, period-budget-first)?
5. Welcome-grant expiry (recommended **none**; purchased credit **never** expires).
6. Per-capability soft caps inside the funded trial (optional anti-abuse).
7. One-time **backfill** welcome grant for existing users at rollout (recommended **yes**, idempotent).
8. Exact store prices for the +25% SKUs (set in App Store Connect / Play Console).

---

## 12. Repos & artifacts touched

| Repo | Change |
|------|--------|
| `synaplan` (backend) | migration (`BWALLET`, `BWALLET_LEDGER`); `Wallet`/`WalletLedgerEntry` entities + repos; `WalletService`, `WalletPackService`, `TurnstileService`; `WalletController`; wire `RateLimitService` (debit + gate + count-lift); extend `StripeWebhookController` + `MobilePurchaseService`; `App\Seed\WalletPackSeeder`; OpenAPI |
| `synaplan` (frontend) | `WalletMeter.vue`; rework `LimitReachedModal.vue`; padlock removal in `AIModelsConfiguration.vue` + composer; wallet in `SubscriptionView`/`UsageStatistics`; Turnstile in register/login; regenerated Zod schemas; i18n × 4 |
| `synaplan` (docs) | `docs/PAYMENTS_CHANNELS.md` wallet/consumable section |
| `synaplan-apps` | consumable IAP products (Apple/Google), store prices ≈ +25%, sandbox testers, `docs/LAUNCH_CHECKLIST.md` |

All of the above sits inside the `AGENTS.md` "Ask First" boundary (DB schema, new
deps, payment/IAP, Docker/CI config). **This plan is that ask** — implementation
proceeds once W0 decisions + legal sign-off land.
