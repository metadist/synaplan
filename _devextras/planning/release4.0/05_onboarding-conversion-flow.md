# Feature 4 — Frictionless Onboarding & Conversion Flow

**Release:** 4.0 · **Priority:** P1 · **Status:** Planned
**Target:** `web.synaplan.com` (SaaS Platform)

> **Goal:** Accelerate user conversion, particularly from the iOS app to the web platform, by offering a frictionless, "fully unlocked" first-time experience. Instead of greeting new signups with padlocks and "feature not available" popups, let them experience the full power of Synaplan (all models, media generation) immediately, governed by a secure, anti-scraping "Welcome Wallet."

---

## 1. The Problem

Currently, when a user signs up via Email or Google/Apple OIDC, they land in the `NEW` tier. If they attempt to use premium features (e.g., advanced LLMs, video generation), they hit hard paywalls and "locked" UI overlays immediately. 
This creates high friction. For consumers coming from an iOS app expecting an immediate wow-factor, this friction kills conversion.

However, if we unconditionally unlock expensive API routes (OpenAI, Google Veo, Anthropic), automated scrapers will create infinite accounts to drain our platform credits.

## 2. The Solution: "Welcome Wallet" Trial

**"Show, don't tell."** We remove the UI padlocks for new, verified accounts. They receive a finite, non-renewable "Welcome Balance" that lets them try anything. The paywall only drops *after* they have experienced the value.

### 2.1 UX Experience

1. **Clean UI, No Padlocks:** On first login, all top-tier models (GPT-5.5, latest Claude, etc.) and capabilities (Image, Video, Audio) are selectable.
2. **Trial Indicator:** A subtle, friendly indicator in the sidebar or header shows: *"Free Trial: 50 credits remaining"* (or a visual meter).
3. **Graceful Exhaustion:** When the user attempts an action that exceeds their remaining welcome balance, *then* the beautiful subscription upgrade modal appears: *"You've used up your free trial! Upgrade to PRO to keep using Video Generation and Advanced Models."*
4. **Transparent Degradation:** If they refuse to upgrade, they fall back to the standard `NEW` tier limits (only local/free models, no rich media generation).

### 2.2 Anti-Scraping / Bot Defenses (Crucial)

To prevent API abuse, the Welcome Wallet is heavily guarded:

1. **Implicit vs. Explicit Verification:**
   * **Google / Apple OIDC:** Automatically considered verified. Wallet granted immediately.
   * **Email Signup:** Account is created, but the Wallet remains locked (padlocks visible) until the user clicks the verification link in their email.
2. **Cloudflare Turnstile:** Add invisible CAPTCHA to the `/api/v1/auth/register` and magic-link request endpoints to stop automated headless browsers.
3. **IP Rate Limiting:** Strictly limit account creation to a maximum of 3 accounts per IP address per 24 hours. (Using existing Redis `RateLimitService` logic).
4. **Hard-Capped Wallet:** The wallet size must be financially safe. Example: 500 tokens. 
   * Text query = 1 token.
   * Image gen = 50 tokens.
   * Video gen = 200 tokens.
   * A bot cannot extract meaningful commercial value from a single account before needing a new verified email and IP.

---

## 3. Implementation Steps

### Sprint A: Backend Welcome Wallet

1. **Data Model:** We can implement the wallet in two ways:
   * *Option A (Preferred):* Add a `BTRIAL_CREDITS` (int) column to `BUSER` (defaults to 0).
   * *Option B:* Store it in `plugin_data` or `BCONFIG` per user.
2. **Onboarding Hook:** On successful verified signup (OIDC or email verification), initialize `BTRIAL_CREDITS = 500`.
3. **RateLimitService Integration:** Update `RateLimitService::checkLimit()`. Before denying a `NEW` tier user access to a premium capability, check if `BTRIAL_CREDITS > required_cost`. If yes, allow the action and deduct the cost asynchronously.

### Sprint B: Frontend Padlock Removal & Meter

1. **Remove Pre-emptive Locks:** Update `AIModelsConfiguration.vue` and `ChatMessage.vue` capabilities. If the user's `trial_credits > 0`, treat them visually as a `PRO` user (no grayed-out options, no lock icons).
2. **The Wallet Meter:** Create a small `WelcomeWalletMeter.vue` component in the Sidebar or Header. It reads the current balance from the user profile API and updates dynamically.
3. **The "Upsell" Intercept:** When the backend returns a `402 Payment Required` (trial exhausted), intercept this globally (or in `TaskCard.vue` / chat composer) to trigger the `SubscriptionModal.vue`, highlighting the exact feature they tried to use.

### Sprint C: Anti-Abuse Hardening

1. **Turnstile:** Integrate Cloudflare Turnstile into the frontend login/register forms. Pass the token to the backend and verify it before creating the `BUSER` row.
2. **Email Verification Gate:** Ensure the wallet allocation only triggers *after* the email magic link is clicked, not just when the row is inserted.

---

## 4. Definition of Done

* A new user signing in via Apple/Google sees NO padlocks on premium models or video generation.
* They can successfully generate 1-2 videos or have a prolonged text conversation using the latest flagship models (e.g., GPT-5.5).
* Upon reaching the limit, the action cleanly fails and pops open the Subscription pricing modal.
* Automated account creation scripts are blocked by Turnstile and IP limits.
* The feature is cleanly flag-gated so we can tune the initial wallet size or disable it if abuse patterns emerge.