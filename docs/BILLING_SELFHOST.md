# Billing on a self-hosted install

Synaplan ships with billing **off**. Without Stripe credentials the install runs
in open-source mode: no limits, no quotas, no upgrade prompts, no subscription
routes, and no cost-budget gate. That is the state every self-host and every
marketplace image starts in, and most operators never need to change it.

If you run Synaplan **for other people** and want to charge them, you can switch
billing on with your own Stripe account. You are then the merchant of record —
the money goes to you, we are not involved in the payment and take no cut.

## What "billing off" actually means

`BillingService::isEnabled()` returns `false` while `STRIPE_SECRET_KEY` or
`STRIPE_PRICE_PRO` is empty or still a placeholder (`sk_test_your_key_here`,
`price_pro_monthly`, …). Everything downstream reads that one flag:

| Area | Behaviour with billing off |
|------|----------------------------|
| Rate limits (`RateLimitService`) | `unlimited` for every user |
| Storage quota (`StorageQuotaService`) | effectively unbounded |
| Cost-budget gate | never enforced, even with `COST_BUDGET_GATE_ENABLED=true` |
| Embedding-model switch, BYO provider keys, historical email import | open to every user level |
| Subscription routes, Stripe checkout, upgrade CTAs | hidden |

Because the tier checks hang off that single flag, a `NEW` user on an install
without billing has the same feature set as an admin. Do not re-add a tier check
that bypasses `PremiumFeatureGate` — that is what makes the open-source mode
trustworthy.

## Requirements before you switch it on

- **A public HTTPS domain.** Stripe Checkout builds its `success_url` and
  `cancel_url` from `FRONTEND_URL`, and Stripe must be able to reach your
  webhook endpoint from the internet. A `localhost` or IP-only install cannot
  complete a checkout.
- **A Stripe account** in a country Stripe supports, with the tax and payout
  settings you need. Consult your own tax advice — subscriptions you sell are
  your revenue and your VAT obligation.

## Setup

### 1. Create products and prices in Stripe

In the Stripe Dashboard, create one recurring product per tier you want to sell
(Pro, Team, Business) and copy each **price ID** (`price_…`, not the product
ID). You do not have to offer all three; the tiers you leave unset simply cannot
be bought.

### 2. Put the credentials into your deployment

Add these to `deploy/.env` (or your platform's environment configuration):

```bash
STRIPE_SECRET_KEY=sk_live_…
STRIPE_PUBLISHABLE_KEY=pk_live_…
STRIPE_WEBHOOK_SECRET=whsec_…      # from step 3
STRIPE_PRICE_PRO=price_…
STRIPE_PRICE_TEAM=price_…
STRIPE_PRICE_BUSINESS=price_…

APP_URL=https://chat.example.com
FRONTEND_URL=https://chat.example.com
```

Then restart the stack so the new environment is picked up:

```bash
docker compose -f deploy/compose.yaml up -d
```

### 3. Register the webhook

In the Stripe Dashboard under **Developers → Webhooks**, add an endpoint:

```
https://<your-domain>/api/v1/stripe/webhook
```

Subscribe it to exactly the events Synaplan acts on:

```
checkout.session.completed
customer.subscription.created
customer.subscription.updated
customer.subscription.deleted
customer.subscription.paused
customer.subscription.resumed
invoice.payment_succeeded
invoice.payment_failed
```

Copy the signing secret into `STRIPE_WEBHOOK_SECRET`.

The endpoint is intentionally public and is authenticated by Stripe's signature
over that secret — a wrong or missing secret means every webhook is rejected,
so subscriptions would be paid for but never activated. Verify with a test event
from the dashboard before going live.

### 4. Check the result

`GET /api/v1/config/runtime` reports `billing.enabled: true` once the
configuration is valid. From then on the subscription UI appears, tier limits
apply, and the plans from `BSUBSCRIPTIONS` are sold at your Stripe prices.

## Adjusting plans

Prices, monthly cost budgets and quotas are editable in **Admin → Subscriptions**
and stored in `BSUBSCRIPTIONS`. Note that the amount actually charged comes from
the Stripe price you created, not from that table — the table drives the
displayed price and the entitlements. Keep the two in sync.

Plan names and feature lists are currently part of the application (`PLAN_CATALOGUE`
in `SubscriptionController`) and cannot be renamed from the admin UI.

## Branding

Everything in the payment and shared-chat flow renders the operator brand from
**Admin → Branding** (`BRAND_NAME`, homepage, privacy and terms URLs), so your
users never see a link back to synaplan.com. Set those values before you take
the first payment.

## Turning billing off again

Remove the `STRIPE_*` values (or set them back to the placeholders) and restart.
The install returns to open-source mode immediately. Existing subscriptions in
Stripe are unaffected — cancel or refund them there.
