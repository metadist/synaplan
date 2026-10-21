# Per-user budget

An administrator sets a monthly usage budget on one account. That person
can buy more for the current period with Stripe, when the install takes
payments. The shared plan amounts stay where they are.

This replaces the October 2026 workaround of inserting one
`BUSER_TOPUPS` row per future month. A top-up only counts in the period
that contains its date, so a standing allowance cannot be stored that way.

## What already exists

| Piece | Where | What it does today |
| ----- | ----- | ------------------ |
| Plan budget | `BSUBSCRIPTIONS.BCOST_BUDGET_MONTHLY`, edited in **Admin → Subscriptions** (`AdminSubscriptionsPanel.vue`) | One number for every account on that level. Team is €40, Business is €80. Changing it changes every account on the level. |
| Period top-up | `BUSER_TOPUPS`, summed by `TopupRepository::sumForUserInPeriod()` | Added to the plan for the period that contains `BCREATED`. Expires with the period. Not a wallet. |
| The formula | `RateLimitService::checkCostBudget()` | `base = plan monthly`, `budget = base + top-ups`. `0` with no top-ups means unlimited. Charged cost is raw provider cost plus `BILLING/MARKUP_PERCENT` (default 10). The yearly column is not read. |
| Stripe purchase | `POST /api/v1/subscription/topup`, `StripeWebhookController::handleTopupCompleted()` | Checkout in €100 steps (1–50). The webhook writes one `BUSER_TOPUPS` row, idempotent on the Stripe session id. Refuses when Stripe is not configured. The native app is refused by `blockNativeStripeChannel()`. |
| Blocked-state UI | `LimitReachedModal.vue`, opened from chat, the messages stream, and live voice | Offers the €100 stepper only when `topup_available`, billing is on, and the shell is not the native app. |
| Budget read | `GET /api/v1/subscription/budget` | Returns base, top-ups, used, remaining, period, `gate_enabled`, `billing_enabled`, `topup_step_eur`. |
| Statistics | `UsageStatistics.vue` | A percent bar and the period dates. No euro amounts, no button to buy more. The unlimited branch prints a `$`. |
| Return from Stripe | `SubscriptionSuccessView.vue` | Every return is treated as a plan activation ("your plan is now …"). A top-up uses the same `success_url`. Cancel goes to the subscription-cancel page. |
| People → Users | `UsersTab.vue`, `PATCH /api/v1/admin/users/{id}/level` | Level can be changed. Budget cannot. Level changes are audited. |
| Website gate | `PremiumFeatureGate::isCostBudgetEnforced()` | Chat, media, and live voice block only when `COST_BUDGET_GATE_ENABLED` is set **and** billing is on. |
| Coding-client gate | `MessagesGateway` (operator-key branch, around lines 273–285) | Blocks on `checkCostBudget()` for the operator key. It does not read `PremiumFeatureGate` or the Stripe configuration, so a positive budget stops these clients even when the website gate is off and even when Stripe is not configured. |

With no Stripe key, the website does not enforce the budget
(`docs/BILLING_SELFHOST.md`, `PremiumFeatureGate::isCostBudgetEnforced()`).
Coding clients that use the operator key still do. A positive override on
a no-Stripe install therefore stops those clients once spend reaches it.
Do not add a second website path around `PremiumFeatureGate`.

## Decisions

1. **One nullable column, not more top-up rows.** `BUSER.BCOST_BUDGET_OVERRIDE`
   decimal(10,2), null. Null means "use the plan". A positive amount
   **replaces** the plan monthly figure as `base_budget` for that account
   only. Period top-ups still add. The column is never `0`: a plan budget
   of `0` already means unlimited, and a stored zero would not say whether
   the admin meant "unlimited" or "cleared". Clearing the field is the undo.
2. **The formula stays in one method.** `checkCostBudget()` is the only
   reader. Chat, statistics, live voice, email, and the messages gateway
   already call it. No second budget in group policy. `resolveRateLimitLevel()`
   may swap the rate-limit tier; it does not choose this number.
3. **Admin editing lives on People → Users.** **Admin → Subscriptions**
   keeps editing the shared plans. The list shows the effective monthly
   base ("€40 · plan" or "€250 · this account"). The editor is a new
   component; `UsersTab.vue` is already past the component limit and does
   not grow a form.
4. **Stripe stays €100 steps.** No package catalogue in this track. A
   custom standing amount is the admin field. A purchase is still a
   one-period top-up on top of that amount.
5. **Buy before you are blocked.** The statistics budget card gains
   **Add €100**, reusing `createTopupSession()`. The limit modal stays the
   blocked-state path and is not a second checkout implementation.
6. **A top-up return is not a plan change.** The top-up Checkout
   `success_url` carries `kind=topup`. That page says what was added, until
   which date, and what is left. Cancel returns to statistics and says no
   payment was taken.
7. **No Stripe control when Stripe is not the channel.** Billing off, or
   the native app: the button is absent, not disabled (U11). An admin may
   still save an override. The panel says whether the website enforces it.
8. **Do not flip the website gate.** This track does not set
   `COST_BUDGET_GATE_ENABLED`. The panel states the split: with the flag
   off, the website does not stop at the number, and coding clients that
   use the operator key do.
9. **Operator credits are not migrated by code.** Pre-dated
   `manual-budget-250-*` rows keep summing. After an override is saved for
   such an account, an operator deletes the future rows, or that month is
   override plus €210. The application does not special-case those ids.
10. **These screens speak in euros.** The budget card stops using `$`.
    Provider-cost summary cards are left alone.

Effective budget for a period:

```
base     = override    if the column is set
         = plan monthly otherwise
budget   = base + completed top-ups whose date falls in the period
allowed  = budget is 0 (unlimited) or charged < budget
```

A purchase never changes the override. An override never deletes a
purchase. Lowering an override does not refund spend already recorded.

## User-flow

Journeys, walked in the browser before the UI step merges (U10). Copy is
reviewed from this file before the first `.vue`.

- **J-UB-1 — Set one account.** An admin opens **People → Users**, finds
  the account, and reads "€40 · plan". **Budget** opens the panel: Team
  plan €40, this account empty, spent and bought this period, what is left,
  and the date the period ends. They enter 250 and save. The row shows
  "€250 · this account". The sentence says only this account changed and
  other Team accounts stay at €40. They open statistics as that person
  (or the person does): "€x of €250" until the period end. **Use the plan**
  clears the field; the row returns to "€40 · plan" and the next request
  uses €40. Spend already recorded is unchanged.
- **J-UB-2 — Buy more.** Billing is on, the shell is the website. The
  person opens statistics, sees euros used, the budget, what is left, and
  the end date, and clicks **Add €100**. Stripe Checkout opens. They pay.
  The return page says €100 was added until that end date and names what
  is left. **Statistics** shows the new remaining. They find the same
  number again from statistics without hunting. Cancel says no payment was
  taken and the budget is unchanged.
- **J-UB-3 — Already blocked.** A coding-client or chat refusal still opens
  the existing limit modal with the same €100 stepper. After payment, the
  next request is accepted when charged spend is under the new budget.
  The modal does not claim a plan upgrade.
- **J-UB-4 — Billing off, and the native app.** No **Add €100** anywhere.
  The admin panel can still save 250. It says the website is not applying
  the number and that coding clients which use the server key are. The
  native app never shows a Stripe button.

Ten-second findability (U2): the admin finds the number on the user row
in **People → Users**. The person finds used, budget, remaining, the end
date, and **Add €100** on the statistics budget card. A refusal still
offers the purchase in the dialog they already have open.

### Wireframe

```
People → Users
email                 level   budget              actions
ada@example.com       TEAM    €40 · plan          Budget
special@example.com   TEAM    €250 · this account Budget

Panel
Monthly budget — special@example.com
Team plan                          €40.00
This account                       [ 250.00 ]  Use the Team plan
Spent this period                  €209.88
Bought this period                 €0.00
Left                               €40.12 until 31 Oct 2026

Only this account. Other Team accounts stay at €40.
Takes effect on the next request. Does not change spend already recorded.
The website is not blocking on this number. Coding clients are.

[Save]  [Cancel]

Statistics, billing on
Usage budget
€209.88 of €250.00 · €40.12 left
until 31 Oct 2026
[Add €100 until 31 Oct]
```

Five questions on the panel (U7): who (this account), who else (nobody
else on the plan), what it changes (the monthly allowance before
purchases, from the next request), how to undo (**Use the Team plan**),
where it came from ("this account" versus "plan").

### Copy

English source. All five locales in the same PR as the first paint.
Placeholders match English.

| Surface | Sentence |
| ------- | -------- |
| Row, inherited | `{amount} · plan` |
| Row, override | `{amount} · this account` |
| Save | "Only this account. Other {plan} accounts stay at {planAmount}. Takes effect on the next request. Spend already recorded is unchanged." |
| Clear | "This account again uses the {plan} budget of {planAmount}." |
| Gate off | "The website is not blocking on this number. Coding clients that use the server key are." |
| Billing off, admin | "Payments are off. The website is not applying this number. Coding clients that use the server key are." |
| Add button | "Add €{amount} until {date}" |
| Success | "€{amount} was added until {date}. €{left} is left." |
| Payment not finished | "The payment is not finished. Nothing was added." |
| Paid, credit not stored yet | "Payment received. The budget updates within a minute — open Statistics to see what is left." |
| Session not this account | "This payment could not be confirmed. Nothing shown here was added." |
| Cancel | "No payment was taken. The budget is unchanged." |
| Stripe error | "The payment page did not open. Nothing was added. Try again from Statistics." |

No HTTP codes, no "override", no "top-up" in primary copy. The existing
limit-modal strings stay, and gain the same until-date sentence so the
blocked path and the statistics path do not disagree.

## Steps

| Step | Class | Does |
| ---- | ----- | ---- |
| UB1 | backend-only | Column, formula, admin read/write, audit, top-up session status, `period_end` on cost-budget refusals. No Vue. |
| UB2 | ota-candidate | People → Users row and panel. J-UB-1, J-UB-4. |
| UB3 | ota-candidate | Statistics card, Checkout return and cancel, limit-modal until-date. J-UB-2, J-UB-3. |
| UB4 | no-app-impact | One paragraph in `docs/BILLING_SELFHOST.md`. |

UB2 and UB3 each add their new paths to
`.github/mobile-impact-policy.json` in the same pull request. Neither
touches native purchase or the store shell.

### UB1 — the number

Migration, Galera-safe raw SQL (`ADD COLUMN IF NOT EXISTS`), nullable,
no default other than null. Existing rows inherit the plan.

`checkCostBudget()` uses the override as `base_budget` when set. The
response gains `budget_source`: `plan` or `account`. `GET
/api/v1/subscription/budget` passes it through.

`GET /api/v1/admin/users/{id}/budget` (admin) returns plan amount, plan
name, override, source, top-ups this period, used, remaining, period
end, `gate_enabled`, `billing_enabled`. The user list gains `budget_base`
and `budget_source` only — not a usage sum per row.

`PUT /api/v1/admin/users/{id}/budget` body `{ "amount": "250.00" }` or
`{ "amount": null }`. Rejects negative, rejects `0`, rejects more than
two decimal places, rejects a non-admin. Allows an admin to edit their
own budget (unlike the level change). Audits `admin.user_budget_change`
with the previous value, the new value, and the plan amount. One
transaction.

Tests extend `RateLimitServiceCostBudgetTest`: null inherits, a positive
override replaces the plan, top-ups still add, `0` is not a stored
value, unlimited stays "plan budget is 0 and there is no override".
Controller test: non-admin 403, bad amount 400, clear back to null, audit
row written.

`GET /api/v1/subscription/topup/session?session_id=` is authenticated and
scoped to the signed-in user. The server reads the Checkout session with
the secret key. If `metadata.user_id` and `client_reference_id` are not
this user, the response is 404 and names no other account. The body is
`payment` (`unpaid`, `paid`, or `expired`), `credited` (true only when
`BUSER_TOPUPS.BSTRIPE_SESSION_ID` is this session), and `amount_eur` from
metadata `topup_eur`, so a 1-step and a 50-step purchase are told apart.
`GET /api/v1/subscription/budget` is not used to decide whether this
payment landed. Another purchase in the same period must not satisfy the
check.

The cost-budget refusal adds `period_end` next to `limit`, `used`, and
`remaining` in `MessageController`, `StreamRequestResolver`, and
`VoiceLiveSessionService::assertBudget`. Those three bodies omit it today,
and the stream handler forces `resetTime: null`.

Exit: formula tests green; no UI.

### UB2 — People → Users

**User-flow.** J-UB-1 and J-UB-4 above. Pointer: this file.

New `UserBudgetPanel.vue`. The row shows the base and source. **Budget**
is a `btn-secondary` on the actions cell, including for the signed-in
admin. The panel loads the detail endpoint so spent and bought are fresh.

Empty: a brand-new account with no spend shows "€0.00 spent this period"
and the plan amount, plus **Save** (U5). Error: the save failure is one
sentence and the field keeps what they typed (U8). Billing off: the
Stripe button is not on this panel; the gate sentence is the one in the
copy table (U11).

Exit:

1. J-UB-1 and J-UB-4 walked in the browser (U10).
2. The amount is on the user row (U2).
3. Save and **Use the Team plan** use the sentences in the copy table, in all five locales (U3).
4. Empty spend, a refused save, and billing off are all present (U5, U8, U11).
5. Light, dark, design v2, 320 px (U9).

### UB3 — buy more

**User-flow.** J-UB-2 and J-UB-3 above. Pointer: this file.

The statistics budget card shows used, budget, remaining, and the period
end in euros. **Add €100 until {date}** calls the existing
`createTopupSession(1)`. The stepper for more than one step stays in the
limit modal, where the person is already choosing an amount. Statistics
offers the single step so the card has one primary action. Both paths
share the Checkout session and the webhook.

`success_url` for a top-up is
`/subscription/success?session_id={CHECKOUT_SESSION_ID}&kind=topup`.
`cancel_url` is `/statistics?budget=cancelled` (the real statistics
route, not a new one). The success view, when `kind=topup`, does not run
the plan-activation poll. It polls
`GET /api/v1/subscription/topup/session`. It says the payment is not
finished while `payment` is `unpaid`. It says "Payment received" only
once `payment` is `paid`. It says "€{amount} was added" only once
`credited` is true, and `{amount}` is that session's `amount_eur`. Only
then does it call `GET /api/v1/subscription/budget`, and only to show what
is left. A refresh of statistics shows the same remaining.

The limit modal's button gains "until {date}" from `period_end` on the
refusal. `memberError.ts` passes that field through instead of
`resetTime: null`. `liveBudgetResult()` in `ChatInput.vue` does the same
for the live-voice refusal, which today drops the date. The modal does
not gain a second Checkout endpoint.

Unlimited (`budget` 0): the card keeps the unlimited state and shows no
**Add €100**.

Exit:

1. J-UB-2 and J-UB-3 walked in the browser, including cancel and a Stripe test payment (U10).
2. Remaining budget is on the statistics card after return (U2).
3. Success, slow webhook, and cancel use the copy table, in all five locales (U3).
4. Billing off and the native app render no button; Checkout failure names what did not happen (U5, U8, U11).
5. Light, dark, design v2, 320 px, on the card and the success state (U9).

### UB4 — self-host doc

`docs/BILLING_SELFHOST.md` gains a short paragraph: an admin may set a
per-account monthly budget. The website applies it only when the
cost-budget gate is on. Coding clients that use the operator key apply a
positive amount even when Stripe is not configured. A person buys more
only when Stripe is configured. A purchase lasts the current period; the
standing amount does not.

## Out of scope

- Named pay-as-you-go packages, or a step other than €100.
- Editing `BSUBSCRIPTIONS` amounts. That screen already exists.
- Turning `COST_BUDGET_GATE_ENABLED` on for the hosted service.
- A Stripe purchase inside the native app.
- A yearly per-account budget. The gate does not read the yearly plan column.
- Deleting operator-inserted top-up rows from a migration.
- Group budgets.

## Operator follow-up

When UB1 is deployed, set the standing amount on any account that was
kept at a custom budget with future `manual-budget-250-*` rows, then
delete those future rows. Until they are deleted, that month's budget is
the override **plus** €210.

## Gate

UB1: `make -C backend lint`, `phpstan`, `deptrac`, `test`.

UB2 and UB3: that, plus `make -C frontend lint`, `npm run check:types`,
`make -C frontend test`, and `make test-e2e` for the People users flow
and the statistics purchase return. OpenAPI changes regenerate schemas
before `vue-tsc`.
