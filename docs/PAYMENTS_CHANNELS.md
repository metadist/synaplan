# Payment Channels & Subscription Source (Epic 5)

> A Synaplan subscription is either **ACTIVE** or not, and it is owned by **exactly one
> channel** at a time. The server is the single source of truth for entitlement.

This document explains the channel-aware billing model, the ≈30 % store-commission assumption,
and the product mapping that powers it. It is the human-readable companion to
`App\Service\BillingService`, `App\Service\IapPricingService`, and the subscription `source` field.

## The three channels

| Channel | `source` | Where it can be bought | Manage URL |
|---------|----------|------------------------|------------|
| Stripe (web) | `stripe` | **Web only** — hosted Stripe Checkout | On-demand billing portal (`POST /api/v1/subscription/portal`) |
| Apple IAP | `apple` | **App only** — StoreKit | `https://apps.apple.com/account/subscriptions` |
| Google IAP | `google` | **App only** — Play Billing | `https://play.google.com/store/account/subscriptions` |

- **Web buys via Stripe only.** The app must never open the Stripe web checkout
  (Apple 3.1.1 / Google Play). Enforced client-side (`SubscriptionView.vue`, native guard) and
  server-side.
- **The app buys via native IAP only.**
- **One owner at a time (block-cross).** If a user already has an ACTIVE subscription from
  channel A, purchasing via channel B is refused — server-enforced on the Stripe checkout
  endpoint (HTTP 409 `SUBSCRIPTION_OWNED_BY_OTHER_CHANNEL`) and, once wired, on the IAP
  validation endpoint. The UI explains where to manage the existing subscription.

## `source` field (migration-free)

The owning channel lives in `BPAYMENTDETAILS.subscription.source` (`stripe` | `apple` | `google`).
No schema migration was needed — it is a key inside the existing JSON column.

**Backfill-on-read:** a legacy subscription written before `source` existed always came from
Stripe (the only pre-IAP channel), so `User::getSubscriptionSource()` reports `stripe` for any
subscription that has a `stripe_subscription_id` or a `status` but no explicit `source`. New
Stripe subscriptions stamp `source: 'stripe'` explicitly (webhook + sync).

The unified status endpoint `GET /api/v1/subscription/status` returns:

```jsonc
{
  "active": true,            // unified entitlement truth (any valid channel)
  "tier": "PRO",             // entitled tier (alias of legacy `plan`)
  "source": "stripe",        // owning channel
  "manageUrl": null,         // Apple/Google system URL; null for Stripe (use /portal)
  "cancelAtPeriodEnd": false
  // … legacy keys (status, nextBilling, cancelAt, stripeSubscriptionId, paymentFailed) unchanged
}
```

## The ≈30 % store-commission assumption

Apple and Google treat AI subscriptions as digital goods, so **IAP is mandatory in the app** and
the store keeps a commission:

- **≈30 %** standard.
- **15 %** may apply: Apple Small Business Program (first $1M/yr) and Google auto-renewing subs
  after the subscriber's first paid year.

**Therefore the STORE price for each tier must BAKE IN that commission** so the net revenue is
comparable to the Stripe (web) price. Concretely: the web (Stripe) price and the store (IAP) price
are configured **independently per channel**, and the store price is set higher to absorb the cut.

| Tier | Web reference price (Stripe) | Store price (IAP) |
|------|------------------------------|-------------------|
| PRO | €19.95 | set in App Store Connect / Play Console, commission baked in |
| TEAM | €49.95 | set in the stores, commission baked in |
| BUSINESS | €99.95 | set in the stores, commission baked in |

> Finance/tax note: on web, Synaplan is Merchant of Record (Stripe Tax/OSS handles VAT). On IAP,
> Apple/Google are MoR — they collect/remit consumer VAT and pay net after their 15–30 %. Book
> revenue per channel separately. B2B invoices with a buyer VAT-ID are only available on
> web/Stripe (IAP issues simple consumer receipts) — inform in-app, do not actively steer.

## Product ID ↔ tier mapping

| Side | Maps | Implemented by |
|------|------|----------------|
| Stripe | price ID → tier | `SubscriptionController::mapPriceIdToLevel()` |
| IAP | store product ID → tier | `IapPricingService::mapProductIdToLevel()` |

IAP product IDs are configured via env (`IAP_PRODUCT_PRO` / `_TEAM` / `_BUSINESS`). Defaults are
the documented placeholder convention (`com.synaplan.app.<tier>.monthly`), so web-only /
open-source deployments keep IAP **off** (`IapPricingService::isConfigured()` is false, mirroring
`BillingService::isEnabled()` for Stripe). `GET /api/v1/subscription/plans` exposes the resolved
`iapProductId` per tier plus an `iapConfigured` flag so the app knows whether to offer a purchase.

## Status of the implementation

| Part | Status |
|------|--------|
| 5.1 `source` model + unified ACTIVE status + manage URL | ✅ implemented + tested |
| 5.2 channel gating (no Stripe redirect in app) + block-cross + anti-steering guard | ✅ implemented + tested |
| 5.5 channel-aware pricing config + IAP product↔tier mapping + this doc | ✅ implemented + tested |
| 5.3 native IAP frontend (store billing plugin) | ⏳ needs the IAP plugin dependency + on-device store products |
| 5.4 self-hosted IAP receipt validation (Apple ASSN v2 / Google RTDN) | ⏳ needs Apple/Google validation libs + store accounts |
| 5.6 infra & secrets (Pub/Sub, keys) | ⏳ see `synaplan-apps/docs/LAUNCH_CHECKLIST.md` |

Creating the actual store products, sandbox testers and the real prices is account-bound and is
tracked in `synaplan-apps/docs/LAUNCH_CHECKLIST.md`.
