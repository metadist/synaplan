# On-Premise / Open Source Mode — Stripe-Gated Platform Features

**Issue:** [#462 — on-premise: experience needs to be streamlined](https://github.com/metadist/synaplan/issues/462)
**Date:** 2026-02-21

## Problem Statement

When Synaplan is deployed on-premise / self-hosted (without a Stripe billing configuration), users encounter:

1. **Upgrade Button → "Subscription Service Unavailable"** — confusing dead-end
2. **Rate limits enforced** — FREE/NEW users hit message/image/file limits despite running their own server
3. **"← Back to www.synaplan.com"** on login/register pages — irrelevant for self-hosted
4. **Subscription-related UI elements everywhere** — upgrade prompts, plan badges, billing sections

**Goal:** When no valid Stripe configuration exists, the application should behave as a **fully unlimited, open-source edition** with no subscription UI, no rate limits, and self-hosted-appropriate branding.

## What Constitutes "Valid Stripe Config"

From `synaplan-platform/.env` (production hosted):

```
STRIPE_SECRET_KEY=sk_live_...
STRIPE_PUBLISHABLE_KEY=pk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_PRO=price_...
STRIPE_PRICE_TEAM=price_...
STRIPE_PRICE_BUSINESS=price_...
```

From `synaplan/backend/.env` (dev/self-hosted defaults):

```
STRIPE_SECRET_KEY=sk_test_your_key_here
STRIPE_PUBLISHABLE_KEY=pk_test_your_key_here
STRIPE_WEBHOOK_SECRET=whsec_your_webhook_secret_here
STRIPE_PRICE_PRO=price_pro_monthly
STRIPE_PRICE_TEAM=price_team_monthly
STRIPE_PRICE_BUSINESS=price_business_monthly
```

**Existing logic** in `SubscriptionController::isStripeConfigured()`:
- `STRIPE_SECRET_KEY` is non-empty and not `your_stripe_secret_key_here`
- `STRIPE_PRICE_PRO` is non-empty and not `price_xxx` / `price_pro`

**Enhanced check (to be centralized):** A valid Stripe config requires:
- `STRIPE_SECRET_KEY` starts with `sk_live_` or `sk_test_` (not a placeholder)
- `STRIPE_PRICE_PRO` starts with `price_` and is not a placeholder like `price_pro_monthly`, `price_pro`, `price_xxx`

## Architecture Decision

Introduce a **single source of truth**: `billing.enabled` flag exposed via the `/api/v1/config/runtime` endpoint. This flag is derived server-side from the Stripe configuration check. The frontend uses this flag to conditionally show/hide all billing-related UI.

On the backend, when billing is disabled, the `RateLimitService` treats all users as unlimited.

## Implementation Plan

### Phase 1: Backend — Central Billing Flag + Unlimited Mode

#### 1.1 Create `BillingService` (new, lightweight)

**File:** `backend/src/Service/BillingService.php`

A small service that centralizes the "is billing/Stripe configured" check. Injected where needed.

```php
final readonly class BillingService
{
    public function __construct(
        private string $stripeSecretKey,
        private string $stripePricePro,
    ) {}

    public function isEnabled(): bool
    {
        // Must have a real Stripe secret key (not placeholder)
        if (empty($this->stripeSecretKey) || str_contains($this->stripeSecretKey, 'your_')) {
            return false;
        }

        // Must have real price IDs (not placeholders)
        if (empty($this->stripePricePro)
            || in_array($this->stripePricePro, ['price_xxx', 'price_pro', 'price_pro_monthly'], true)) {
            return false;
        }

        return true;
    }
}
```

Register in `services.yaml` with the existing Stripe env params.

#### 1.2 Expose in Runtime Config

**File:** `backend/src/Controller/ConfigController.php` — `getRuntimeConfig()`

Add to the response:

```php
$response['billing'] = [
    'enabled' => $this->billingService->isEnabled(),
];
```

This is a **public** flag (no auth required) so the login page can use it.

#### 1.3 RateLimitService — Bypass When Billing Disabled

**File:** `backend/src/Service/RateLimitService.php` — `checkLimit()`

Inject `BillingService`. At the top of `checkLimit()`:

```php
if (!$this->billingService->isEnabled()) {
    return [
        'allowed' => true,
        'limit' => PHP_INT_MAX,
        'used' => 0,
        'remaining' => PHP_INT_MAX,
        'reset_at' => null,
        'limit_type' => 'unlimited',
    ];
}
```

When billing is disabled, **all users get unlimited usage** — no lifetime, hourly, or monthly limits.

#### 1.4 StorageQuotaService — Bypass When Billing Disabled

**File:** `backend/src/Service/StorageQuotaService.php`

Same pattern: inject `BillingService`, return unlimited storage when billing is disabled.

#### 1.5 SubscriptionController — Use Centralized Check

**File:** `backend/src/Controller/SubscriptionController.php`

Replace the private `isStripeConfigured()` with the injected `BillingService::isEnabled()`.

### Phase 2: Frontend — Config Store + Conditional UI

#### 2.1 Config Store — Billing Flag

**File:** `frontend/src/stores/config.ts`

Add billing accessor:

```typescript
billing: {
    get enabled(): boolean {
        return getConfigSync().billing?.enabled ?? false
    },
},
```

#### 2.2 Hide Subscription/Upgrade UI When Billing Disabled

| Component | What to hide | Condition |
|-----------|-------------|-----------|
| `SidebarV2.vue` | Subscription/Upgrade menu item | `v-if="config.billing.enabled"` |
| `StorageQuotaWidget.vue` | Upgrade button + plan label | Hide upgrade button; show "Unlimited" as plan |
| `LimitReachedModal.vue` | Entire modal / upgrade button | Don't show modal when billing disabled (limits won't be hit anyway) |
| `ChatView.vue` | Rate limit error handling | Should never trigger, but guard with billing check |
| `WidgetSummaryPanel.vue` | "Team Required" gate | Remove gate when billing disabled — all features available |
| `FilesView.vue` | Upgrade handler in storage widget | Hide upgrade, show unlimited |
| `ProfileView.vue` | Billing address section | `v-if="config.billing.enabled"` |
| `AdminView.vue` | "Active Subscriptions" section | `v-if="config.billing.enabled"` |
| `SubscriptionView.vue` | Entire page content | Redirect to `/chat` or show "Self-hosted — all features included" |
| `SubscriptionSuccessView.vue` | Success page | Redirect to `/chat` when billing disabled |
| `SubscriptionCancelView.vue` | Cancel page | Redirect to `/chat` when billing disabled |

#### 2.3 Router Guard for Subscription Routes

**File:** `frontend/src/router/index.ts`

Add a `beforeEnter` guard on `/subscription`, `/subscription/success`, `/subscription/cancel` that redirects to `/chat` when `config.billing.enabled` is false.

### Phase 3: Branding — Login/Register Page Adjustments

#### 3.1 Login Page

**File:** `frontend/src/views/LoginView.vue`

Currently shows:
```html
<a href="https://www.synaplan.com">{{ $t('auth.backToHomepage') }}</a>
```

Change to:
```html
<!-- When billing enabled (hosted): link back to homepage -->
<a v-if="billingEnabled" href="https://www.synaplan.com">
  {{ $t('auth.backToHomepage') }}
</a>
<!-- When self-hosted: "Powered by" branding -->
<a v-else href="https://www.synaplan.com" target="_blank">
  {{ $t('auth.poweredBySynaplan') }}
</a>
```

#### 3.2 Register Page

**File:** `frontend/src/views/RegisterView.vue`

Same pattern as login page for both the "back to homepage" link and the terms reference.

#### 3.3 Shared Chat View

**File:** `frontend/src/views/SharedChatView.vue`

The "register" link at line 257 pointing to `synaplan.com/register` should be conditional — when self-hosted, link to the local `/register` route instead.

#### 3.4 Cookie Consent

**File:** `frontend/src/components/CookieConsent.vue`

The privacy policy link to `synaplan.com/privacy-policy` — keep as-is (it's the open-source project's privacy policy). Or hide the cookie consent entirely when self-hosted if no Google Tag is configured (already gated on `googleTag.enabled`).

### Phase 4: i18n — New Translation Keys

Add to **all language files** (en, de, es, tr):

| Key | EN | DE |
|-----|----|----|
| `auth.poweredBySynaplan` | `Powered by Synaplan` | `Powered by Synaplan` |
| `storage.unlimited` | `Unlimited` | `Unbegrenzt` |
| `subscription.selfHosted` | `Self-Hosted Edition — All features included` | `Self-Hosted Edition — Alle Funktionen inklusive` |

### Phase 5: Auth Store — User Level When Billing Disabled

When billing is disabled, the user's plan level becomes irrelevant for feature gating. The frontend's `isPro` and `isTeam` computed properties in `stores/auth.ts` should return `true` when billing is disabled:

```typescript
const isPro = computed(() =>
    !config.billing.enabled || ['PRO', 'TEAM', 'BUSINESS'].includes(userLevel.value)
)
const isTeam = computed(() =>
    !config.billing.enabled || ['TEAM', 'BUSINESS'].includes(userLevel.value)
)
```

This ensures all feature gates (like the AI Summary panel requiring Team plan) are automatically unlocked for self-hosted users.

## File Change Summary

### Backend (PHP)

| File | Change |
|------|--------|
| `Service/BillingService.php` | **NEW** — Centralized billing/Stripe check |
| `config/services.yaml` | Register `BillingService` with Stripe env params |
| `Controller/ConfigController.php` | Add `billing.enabled` to runtime config response |
| `Service/RateLimitService.php` | Bypass all limits when billing disabled |
| `Service/StorageQuotaService.php` | Bypass storage limits when billing disabled |
| `Controller/SubscriptionController.php` | Use `BillingService` instead of private method |

### Frontend (Vue/TS)

| File | Change |
|------|--------|
| `stores/config.ts` | Add `billing.enabled` accessor |
| `stores/auth.ts` | `isPro`/`isTeam` return true when billing disabled |
| `router/index.ts` | Guard subscription routes |
| `views/LoginView.vue` | Conditional branding link |
| `views/RegisterView.vue` | Conditional branding link |
| `views/SubscriptionView.vue` | Redirect or self-hosted message |
| `views/ChatView.vue` | Guard rate limit modal |
| `views/FilesView.vue` | Guard upgrade handler |
| `views/AdminView.vue` | Hide subscriptions section |
| `views/ProfileView.vue` | Hide billing address section |
| `views/SharedChatView.vue` | Local register link when self-hosted |
| `components/SidebarV2.vue` | Hide subscription menu item |
| `components/StorageQuotaWidget.vue` | Hide upgrade, show "Unlimited" |
| `components/common/LimitReachedModal.vue` | Guard modal display |
| `components/widgets/WidgetSummaryPanel.vue` | Remove Team plan gate |

### i18n

| File | Change |
|------|--------|
| `i18n/en.json` | Add `poweredBySynaplan`, `unlimited`, `selfHosted` keys |
| `i18n/de.json` | Same |
| `i18n/es.json` | Same |
| `i18n/tr.json` | Same |

## Testing Checklist

- [ ] **Self-hosted (no Stripe):** No upgrade buttons visible anywhere
- [ ] **Self-hosted:** No rate limit errors, unlimited messages/images/files
- [ ] **Self-hosted:** No storage quota limits
- [ ] **Self-hosted:** Login shows "Powered by Synaplan" not "Back to www.synaplan.com"
- [ ] **Self-hosted:** `/subscription` redirects to `/chat`
- [ ] **Self-hosted:** AI Summary panel works for all users (no Team gate)
- [ ] **Self-hosted:** Admin view hides "Active Subscriptions"
- [ ] **Hosted (with Stripe):** Everything works as before — upgrade buttons, rate limits, plans
- [ ] **Hosted:** Login still shows "← Back to www.synaplan.com"
- [ ] **Hosted:** Subscription page shows plans and Stripe checkout works

## Implementation Order

1. `BillingService` + `services.yaml` (foundation)
2. `ConfigController` runtime config (expose flag)
3. `RateLimitService` + `StorageQuotaService` (backend unlimited mode)
4. `SubscriptionController` refactor (use BillingService)
5. Frontend `config.ts` + `auth.ts` (consume flag)
6. i18n translations
7. UI components (sidebar, modals, widgets, views)
8. Login/Register branding
9. Router guards
10. Testing
