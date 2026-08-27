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
| Top-up channel | Stripe web only | Stripe web **+ Apple/Google IAP** (app), with an internal **price buffer** on the app SKUs (~20–25%) |
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

### 2.1 Unit of account (LOCKED)

- **Internal unit = EUR.** The wallet balance, grants, top-ups and usage debits are
  all stored as EUR decimals, reusing the existing cost engine (`BUSELOG.BCOST`,
  `BILLING/MARKUP_PERCENT`). This is the lowest-regression choice — no parallel
  "token cost" table, no re-pricing of every model.
- **Displayed unit = "credits", `WALLET_CREDITS_PER_EUR = 100`** (1 credit = €0.01).
  A €5 welcome grant shows as **500 credits**; a €19.95 pack as **1 995 credits**.
  Showing credits (not euros) reinforces that the balance is *prepaid AI usage*, not
  refundable money — important legally (see §6). **User-facing term is "credits" in
  every locale** (`en` credits · `de` Guthaben/Credits · `es` créditos · `tr`
  kredi) — never "tokens" in UI (tokens stays internal/marketing-only).
- The conversion rate stays an operator-tunable constant so we can rebalance the
  "feel" without touching the cost engine.
- **Debits ALWAYS include the markup.** What is debited from the wallet is the
  *charged* cost = provider cost × `(1 + BILLING/MARKUP_PERCENT/100)`, never the raw
  provider cost. Example with the default 10% markup: a provider image that costs
  €1.00 debits **€1.10 (110 credits)**. The markup is the platform's margin and is
  single-sourced from the existing `BILLING/MARKUP_PERCENT` (operators can raise it).
  Wallet credit value (welcome grant + purchases) is denominated in this same
  *charged* EUR, so a 500-credit grant buys ~4–5 such images.

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
- **Subscribers (PRO/TEAM/BUSINESS) — LOCKED draw order:** they spend their included
  **monthly budget first**; only **after that budget is exhausted** do they draw from
  the wallet. When the wallet then runs dry, they **top it up** (same packs). So a
  subscriber never burns prepaid credit while included budget remains, and the wallet
  is the seamless "keep going past my plan" overflow.
- **Debit timing:** when `RateLimitService::recordUsage()` writes a `BUSELOG` row,
  a follow-on `WalletService` debit is written for the portion of the charged cost
  that falls on the wallet (charged cost = `BCOST × markupMultiplier`). The debit
  references `BUSELOG.BID` for traceability and idempotency. The portion still
  covered by the period budget is **not** debited from the wallet.

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

**Funded-trial soft caps (LOCKED anti-abuse ceiling).** "Everything unlocked" lifts
the count padlocks for *capability access*, but the **welcome-grant trial** keeps a
hard ceiling on the most expensive media so a single €5 grant can't be turned into a
pile of costly assets even before the balance math bites:

- **Images: max 7**, **Videos: max 2** while the user is on the welcome grant (no
  purchased credit, no active subscription). Text/chat is balance-limited only.
- These caps are seeded config (`RATELIMITS_TRIAL` group: `IMAGES_TOTAL=7`,
  `VIDEOS_TOTAL=2`) so they are operator-tunable and counted lifetime over the trial.
- Once the user **buys** any credit or subscribes, the trial caps no longer apply —
  the wallet balance / period budget is the only limit.

This keeps the financial blast radius bounded by the *balance* **and** a media-count
ceiling — a bot with a drained €5 wallet can spend no more, and can't extract more
than 7 images / 2 videos even within it.

---

## 3. Money flow — top-ups

### 3.1 Fixed packs (replaces the €100 step) — LOCKED

Three fixed packs, mirroring the subscription price points (PRO/TEAM/BUSINESS =
€19.95/€49.95/€99.95), configured as seeded catalog data
(`App\Seed\WalletPackSeeder`):

| Pack | Web price (Stripe, reference) | Credit value granted | Displayed credits |
|------|-------------------------------|----------------------|-------------------|
| Small | €19.95 | €19.95 of wallet credit | 1 995 |
| Medium | €49.95 | €49.95 of wallet credit | 4 995 |
| Large | €99.95 | €99.95 of wallet credit | 9 995 |

This changes the existing `POST /api/v1/subscription/topup` contract (today: N ×
€100 steps) into a **pack-based** top-up (`packId: 'small'|'medium'|'large'`). The
legacy "steps" path is removed behind the flag (see §8 regression note) and the
`LimitReachedModal.vue` EUR-100 step selector is replaced by the three packs.

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

### 3.3 App (native IAP) flow + the internal price buffer

The app **must not** open Stripe web checkout (Apple 3.1.1 / Google Play). Top-ups
in the app go through **consumable IAP products**, validated server-side by the
existing `MobilePurchaseService` seam (extended for consumables).

**The app price buffer (internal — never surfaced to users):**

- The **store price** of each top-up SKU is set **above** the web reference price to
  absorb Apple/Google fees and still make a margin — exactly mirroring the ≈30%
  subscription commission-baking already documented in `docs/PAYMENTS_CHANNELS.md`.
- It is **not** a flat 25%: each store price is a deliberately "nice" round-ish
  number that carries a comfortable buffer (≈20–25%) over the web price. The buffer
  just has to cover the store cut and leave service margin — not break us, not gouge.
- The **credit value granted is the web reference value**, identical across
  channels. App users pay more money for the *same* credits; the wallet is credited
  with the web value.

| Pack | Web price (Stripe) | App store price (buffered) | Credit value granted (both channels) |
|------|--------------------|----------------------------|--------------------------------------|
| Small | €19.95 | **€24.95** (≈ +25%) | €19.95 → 1 995 credits |
| Medium | €49.95 | **€59.95** (≈ +20%) | €49.95 → 4 995 credits |
| Large | €99.95 | **€119.00** (≈ +19%) | €99.95 → 9 995 credits |

- Implementation: a new env-config map `WALLET_IAP_TOPUP_SMALL` / `_MEDIUM` /
  `_LARGE` (store product IDs) → mapped to a **credit-EUR value** by a
  `WalletPackService` (the consumable mirror of `IapPricingService`). The server
  credits the mapped value regardless of the store-charged amount.
- **Internal-only:** the per-pack store prices above are *recommendations* set in
  App Store Connect / Play Console. **No user-facing copy, tooltip, invoice line, or
  API field ever mentions a "surcharge", "buffer", or "fee".** The app simply shows
  the store's localized price and the credits granted.
- IAP top-ups are **consumables** (each purchase grants credit and is "consumed"),
  not subscriptions — so block-cross / one-owner subscription rules do **not**
  apply, but **replay protection** (one transaction id credited once) does, via the
  ledger's `(BTYPE, BSOURCE_REF)` unique key + `MobilePurchaseService` verification.
- Apple/Google are MoR on IAP → they issue the receipt; B2B VAT-ID invoices remain
  **web/Stripe only** (inform in-app, do not steer — same anti-steering rule as
  subscriptions).

> Apple Pay / Google Pay on **web** (as Stripe payment methods) are a later,
> separate add (just a `payment_method_types` entry on the Stripe Checkout). They
> are **not** native IAP and carry the normal web price (no app buffer).

### 3.4 Welcome grant — LOCKED

- On the **first verified** login, `WalletService::grantWelcome()` writes a single
  `welcome_grant` ledger entry of `WALLET_WELCOME_GRANT_EUR` = **€5.00 → 500
  credits**, idempotent on `(welcome_grant, user:{id})` so it is granted **once ever**
  per account.
- "Verified" = OIDC (Google) verified email **or** a clicked email-verification
  link for local signups (see §4). The grant is **not** written at row-insert time
  — only after verification — which is the anti-abuse gate from the original plan.
- The welcome grant is a **promotional credit**: non-refundable, no cash value,
  subject to the anti-abuse guards in §5.
- **Expiry: never.** Both the welcome grant **and** all purchased credit are
  persistent and never expire. (Anti-abuse is handled by the §5 verification +
  Turnstile + 3-grants/IP/24h + funded-trial media caps, not by expiry.)

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
   used your free credits — top up (1 995 / 4 995 / 9 995) or go PRO." On native, the
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
5. **Per-capability caps inside the welcome trial (LOCKED):** even with grant
   balance, the trial is hard-capped at **7 images** and **2 videos** (seeded
   `RATELIMITS_TRIAL`), so a single drained €5 grant can't be turned into a pile of
   expensive assets. Caps lift the moment the user buys credit or subscribes (§2.5).
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
- **The app price buffer is internal cost-recovery only.** It is *never* presented as
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
- ✅ **Product decisions resolved 2026-06-30** — see §11. (Wording = "credits" in all
  locales; rate 100; grant 500; subscribers draw budget-first then wallet; nothing
  expires; trial caps 7 images / 2 videos; backfill grant + email; three packs with
  buffered app prices.)
- Legal sign-off on §6 copy + store tax categories (the remaining W0 blocker).
- **Exit:** legal sign-off recorded; no merge required.

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
- Rework `LimitReachedModal.vue`: replace the €100-step selector with the three packs
  (€19.95 / €49.95 / €99.95); make it balance-aware; native guard routes top-up to
  IAP, web to Stripe.
- `AIModelsConfiguration.vue` + chat composer: remove premium padlocks for
  wallet-funded users; restore at zero balance.
- `SubscriptionView.vue` / `UsageStatistics.vue`: show wallet alongside subscription.
- i18n: **all four locales** (`en`, `de`, `es`, `tr`) for every new string
  (credits, top-up packs, exhaustion, welcome). **No surcharge copy.**

### W5 — Native IAP top-ups + anti-abuse + welcome grant
- Extend `MobilePurchaseService` for **consumable** top-ups: `POST /api/v1/iap/topup`
  verify → `WalletService::creditFromIap()` (replay-protected via ledger unique key).
  Apple ASSN / Google RTDN paths handle refunds → `reversal` ledger entries.
- Store SKUs + recommended buffered store prices (€24.95 / €59.95 / €119.00)
  documented in `synaplan-apps/docs/LAUNCH_CHECKLIST.md`; `WALLET_IAP_TOPUP_*` env map.
- Add `RATELIMITS_TRIAL` seed (`IMAGES_TOTAL=7`, `VIDEOS_TOTAL=2`) + wire the
  funded-trial caps into `checkLimit()` (§2.5 / §5).
- `grantWelcome()` wired into the verification + OIDC-new-user paths.
- `TurnstileService` + runtime config + register/resend verification; **3 grants /
  IP / 24h** cap.
- **Rollout:** flip `WALLET_ENABLED=true` on web first, then app once IAP store
  products are live. **One-time backfill (LOCKED):** every existing user receives the
  500-credit welcome grant once (idempotent on the same `(welcome_grant, user:{id})`
  key) via an `app:wallet:backfill-grant` command, **plus a one-off email**: *"We've
  added 500 free credits to your account."* (localized × 4). Send via the existing
  mail pipeline; throttle the batch.

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
| Gate | `RateLimitServiceCostBudgetTest` | allowance = period budget + wallet; subscriber draw order (budget first, then wallet); wallet-funded lifts count caps; zero-balance falls back to tier; **welcome-trial caps 7 images / 2 videos** enforced and lifted after first purchase |
| Backfill | `WalletBackfillGrantCommandTest` | grants existing users once (idempotent); re-run is a no-op; enqueues one email per granted user |
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
| `LimitReachedModal.vue` | updated spec | shows three packs €19.95/€49.95/€99.95 (not €100 steps); balance-aware copy; **native guard** routes to IAP, web to Stripe (extend `SubscriptionViewNativeGuard.spec.ts` pattern) |
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
  purchase at the **buffered store price** (e.g. €24.95) credits the **web value**
  (€19.95 → 1 995 credits); verify the buffer never appears in any user-visible
  surface.
- Turnstile challenge on register in a real browser; verify 3/IP/24h grant cap.

---

## 10. Definition of done

- A new user (Apple/Google or verified email) lands with **no padlocks** and a
  visible **wallet meter (500 credits)**; can generate media (≤ 7 images / ≤ 2 videos
  in the trial) *or* a long flagship chat from the welcome balance.
- At zero balance, the action cleanly fails (429 / `COST_BUDGET_EXCEEDED`) and the
  balance-aware `LimitReachedModal` offers the **three packs** €19.95/€49.95/€99.95
  (web→Stripe, app→IAP) or PRO.
- Subscribers spend **included budget first, then the wallet**, then top up.
- Top-up credits land in the **persistent** wallet, **never expire**, and **cannot**
  be exchanged for cash; idempotent on every retry/replay. Every debit applies the
  `BILLING/MARKUP_PERCENT` markup (e.g. €1.00 provider cost → €1.10 debited).
- App top-ups are priced with a **buffer** at the store (≈ €24.95/€59.95/€119.00) yet
  grant the **same** credits as web; **no user-facing surface mentions the buffer.**
- Anti-abuse: Turnstile + verification gate + 3 grants/IP/24h + trial media caps
  (7 images / 2 videos) block farming.
- Existing users receive a one-time **500-credit backfill grant** + a localized
  "we added 500 free credits" email (idempotent, re-run safe).
- `WALLET_ENABLED=false` ⇒ **byte-for-byte** current behaviour (legacy top-ups,
  subscriptions, open-source mode all unchanged).
- i18n complete in `en`/`de`/`es`/`tr`; `docs/PAYMENTS_CHANNELS.md` updated; legal
  sign-off recorded.
- Full gate green; E2E covers "exhaust → top up → unlocked".

---

## 11. Product decisions — RESOLVED (2026-06-30)

| # | Decision | Resolution |
|---|----------|------------|
| 1 | User-facing term | **"credits"**, localized in all four locales (`de` Guthaben/Credits · `es` créditos · `tr` kredi). "tokens" never appears in UI. |
| 2 | Conversion rate `WALLET_CREDITS_PER_EUR` | **100** (1 credit = €0.01). Debits **always** apply `BILLING/MARKUP_PERCENT` first — a €1.00 provider cost debits €1.10 (110 credits). |
| 3 | Welcome grant size | **500 credits (€5.00)**. |
| 4 | Subscribers draw from wallet? | **Yes — included monthly budget first, then the wallet, then top up.** |
| 5 | Expiry | **Never** — welcome grant *and* purchased credit are permanent. |
| 6 | Per-capability trial caps | **Yes — 7 images, 2 videos** during the welcome trial (`RATELIMITS_TRIAL`); lifted after first purchase/subscription. |
| 7 | Backfill grant for existing users | **Yes — one-time, idempotent**, with a localized email "we added 500 free credits to your account". |
| 8 | App (IAP) store prices | **Not a flat +25%** — buffered "nice" prices mapped from the Stripe price points: **€19.95→€24.95, €49.95→€59.95, €99.95→€119.00.** Buffer just covers the store cut + service margin. |

Remaining W0 blocker: **legal sign-off** on the §6 ToS/refund/consent copy and the
store-product tax categories.

---

## 12. Repos & artifacts touched

| Repo | Change |
|------|--------|
| `synaplan` (backend) | migration (`BWALLET`, `BWALLET_LEDGER`); `Wallet`/`WalletLedgerEntry` entities + repos; `WalletService`, `WalletPackService`, `TurnstileService`; `WalletController`; wire `RateLimitService` (debit + gate + count-lift); extend `StripeWebhookController` + `MobilePurchaseService`; `App\Seed\WalletPackSeeder`; OpenAPI |
| `synaplan` (frontend) | `WalletMeter.vue`; rework `LimitReachedModal.vue`; padlock removal in `AIModelsConfiguration.vue` + composer; wallet in `SubscriptionView`/`UsageStatistics`; Turnstile in register/login; regenerated Zod schemas; i18n × 4 |
| `synaplan` (docs) | `docs/PAYMENTS_CHANNELS.md` wallet/consumable section |
| `synaplan-apps` | consumable IAP products (Apple/Google), buffered store prices (€24.95/€59.95/€119.00), sandbox testers, `docs/LAUNCH_CHECKLIST.md` |

All of the above sits inside the `AGENTS.md` "Ask First" boundary (DB schema, new
deps, payment/IAP, Docker/CI config). **This plan is that ask** — implementation
proceeds once W0 decisions + legal sign-off land.
