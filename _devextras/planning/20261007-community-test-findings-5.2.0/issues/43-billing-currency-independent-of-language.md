<!-- title: Billing: the usage meter hard-codes EUR while model prices are USD per 1M tokens and the embedding-switch dialog shows $, so one admin sees two currencies -->
<!-- type: Bug -->
<!-- labels: prio:3, area:billing, area:statistics -->
<!-- issue-type: Bug -->

## Problem
The usage meter shows amounts in EUR on a US deployment, the embedding-switch dialog shows its cost estimate in USD ($), and the README says model prices are in USD per 1M tokens. Currency is not configurable and is not tied to the instance's billing currency; number and date formats follow the UI language only.

---

## Expected
One display currency per instance (operator setting, default from the billing provider's currency or USD in open-source mode), applied consistently to the usage meter, per-answer cost, run cards, cost badges and the embedding-switch estimate; prices stored in USD are converted explicitly or shown as USD — never relabelled.

## Actual
1. Usage meter and per-answer cost: `€` (hard-coded `currency: 'EUR'` in the formatter).
2. Embedding-switch estimate: `$`.
3. Model prices: USD per 1M (README, seeder).

---

## Steps to reproduce
1. Chat once; read the usage meter.
2. Open the embedding-switch dialog; read the estimate.

---

## Notes
- Findings: F8 (currency half) — community test round on 5.2.0. The "Yours today" label is in the admin copy sweep.
- Verified in code: `frontend/src/utils/usageFormat.ts` — `new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR' })` with the doc comment "Format a euro amount". The product owner has to answer the testers' question first: is the EUR figure a conversion of USD prices, or are prices treated as EUR? The fix depends on it.

Fix direction: an instance setting `DISPLAY_CURRENCY` (runtime config), a single `formatCost(amount, currency)` used everywhere, and either (a) a stored exchange rate with "approx." in the tooltip, or (b) show USD throughout in open-source mode and the billing currency when Stripe is configured.

Verification:
1. One currency symbol across chat, usage, run cards and the embedding dialog.
2. The tooltip on the usage meter states the currency and, if converted, the rate.

---

## Screenshots/Logs
Usage meter: `0,00 €`; embedding dialog: `$`.
