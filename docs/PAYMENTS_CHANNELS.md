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
  channel A, purchasing via channel B is refused — server-enforced on **both** the Stripe
  checkout endpoint (HTTP 409 `SUBSCRIPTION_OWNED_BY_OTHER_CHANNEL`) and the IAP validation
  endpoint (HTTP 409 `IAP_OWNERSHIP_CONFLICT`). The UI explains where to manage the existing
  subscription.

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

Store EUR price points are operator-configurable via `IAP_STORE_PRICE_{PRO,TEAM,BUSINESS}`
(must match App Store Connect / Play Console). `GET /api/v1/subscription/plans` exposes them as
`appPrice`. If a tier's store price is `0`, the fallback is
`price × (1 + IAP_PRICE_MARKUP_PERCENT/100)` (default markup `30`), snapped to the nearest x.99
price point, never below the web price:

- the **web** always displays the plain `price`;
- the **native app** displays the store's own localized price once its catalogue is loaded, and
  `appPrice` as the fallback before that — the cheaper web price is never shown in the app
  (anti-steering).

| Tier | Web reference price (Stripe) | App / ASC price (EUR, DE) |
|------|------------------------------|---------------------------|
| PRO | €19.95 | **€24.99** (decided 2026-07-20; slightly under pure 30 % pass-through) |
| TEAM | €49.95 | €64.99 |
| BUSINESS | €99.95 | €129.99 |

When creating the store products in App Store Connect / Play Console, set exactly these
`IAP_STORE_PRICE_*` price points — the store price is what the buyer actually pays and what the
app shows once loaded.

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

## Server-side IAP validation (Epic 5.4)

The server **always re-verifies** a receipt before granting a tier — it never trusts a client
success callback or a raw notification payload. Three endpoints under `/api/v1/iap`:

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `POST /verify` | Bearer | Redeem a fresh purchase for the signed-in user. Apple: verify the StoreKit 2 transaction JWS. Google: query `purchases.subscriptionsv2`. |
| `POST /apple/notifications` | Apple signature | App Store Server Notifications V2 (renew/cancel/refund/grace) — JWS + cert-chain verified. |
| `POST /google/notifications` | Pub/Sub push | Real-time Developer Notifications — the purchase state is **re-queried** from Google (truth from the API, not the message). |

Guarantees enforced in `MobilePurchaseService`:

- **User-bound entitlement.** A verified purchase is written to the authenticated user only.
- **Replay protection.** A receipt's stable id (Apple `original_transaction_id` / Google
  `purchase_token`) maps to exactly one account; a receipt already owned by another user is
  rejected (`IAP_OWNERSHIP_CONFLICT`). Re-redeeming on the same account is idempotent.
- **PENDING never unlocks.** Google deferred/SCA purchases are accepted but not granted until the
  confirming RTDN arrives.
- **Google `acknowledge`** is called within the 3-day window once access is granted (else Google
  auto-refunds). Apple needs no acknowledgement.
- **Renew/cancel/refund/grace/hold** flow through the same `applyEntitlement()` core via
  notifications, so state changes apply without the app open; a notification never resurrects a
  subscription the user no longer owns (channel check).

Verification is delegated to `AppleReceiptVerifierInterface` / `GooglePlayVerifierInterface`
seams, so the business logic is fully unit-tested with fakes — **no real store calls, keys, or
accounts needed** for the test suite. Both verifiers report *not-configured* until their env
credentials are set (`IAP_APPLE_*` / `IAP_GOOGLE_*`), in which case `/api/v1/iap/*` returns 503 —
web-only / open-source deployments keep working untouched.

## Status of the implementation

| Part | Status |
|------|--------|
| 5.1 `source` model + unified ACTIVE status + manage URL | ✅ implemented + tested |
| 5.2 channel gating (no Stripe redirect in app) + block-cross + anti-steering guard | ✅ implemented + tested |
| 5.4 self-hosted IAP receipt validation (verify endpoint + Apple ASSN v2 / Google RTDN) | ✅ implemented + unit-tested (mocked stores); needs store accounts/keys to go live |
| 5.5 channel-aware pricing config + IAP product↔tier mapping + this doc | ✅ implemented + tested |
| 5.3 native IAP frontend (store billing plugin) | ✅ implemented — `nativeIap.ts` + `cordova-plugin-purchase`(+`-storekit2`); wired into `SubscriptionView` (purchase/restore) and onboarding (store prices); needs on-device QA with real store products |
| 5.6 infra & secrets (Pub/Sub, keys) | ⏳ see `synaplan-apps/docs/LAUNCH_CHECKLIST.md` |

### Display prices are operator-configurable

`GET /api/v1/subscription/plans` reads name, monthly price, and currency from the
`BSUBSCRIPTIONS` table (falling back to compiled defaults when a tier row is missing);
deactivated rows (`BACTIVE = 0`) hide the tier. Admins edit prices/currency in the
admin panel ("Subscription Plans" tab, `PATCH /api/v1/admin/subscriptions/{id}`), so a
self-hosted install shows its own prices in onboarding and on the subscription page.
Inside the native app the **store's localized price wins** (it is what the user is
actually charged); the server price is the fallback and the web's only source.

Creating the actual store products, sandbox testers, the real prices and the validation
credentials is account-bound and is tracked in `synaplan-apps/docs/LAUNCH_CHECKLIST.md`.
