# Per-user budget

Two ways a person gets a monthly usage budget, on one formula.

A person who signs in with the company login (OIDC) is placed in directory
groups by the identity provider. An administrator assigns that group's
usage budget once, under People → Policies. Stripe is not part of that
path. Membership changes when the identity provider changes, on the next
sign-in.

Everyone else keeps the shared plan. An administrator can set one
account's amount without changing the plan. That person can buy more for
the current period with Stripe, when this install takes payments and the
app is not the native shell.

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
| Company login groups | `DirectoryGroupSync`, claim `IAM.DIRECTORY_GROUPS_CLAIM` (default `groups`) | On sign-in, the claim is matched to `BGROUPS` (`kind=directory`, `BEXTERNALSOURCE` + `BEXTERNALID`). Members are added and removed. An administrator cannot type those members in (`DirectoryGroupReadOnlyException`). A browser login syncs every time. A bearer token re-syncs at most every 5 minutes. |
| Group limit table | People → Policies, `RATELIMITS.TIER`, `resolveRateLimitLevel()` | A group may replace the account level for message, storage, and the other `RATELIMITS_{level}` tables. Highest of NEW / PRO / TEAM / BUSINESS wins. The code comment on `resolveRateLimitLevel()` says this does **not** choose the usage budget. `checkCostBudget()` still reads `getRateLimitLevel()`. |
| OIDC roles | `OidcUserService` | The role claim can promote or demote `ADMIN`. It does not set a group and it does not set a budget. |
| Website gate | `PremiumFeatureGate::isCostBudgetEnforced()` | Chat, media, and live voice block only when `COST_BUDGET_GATE_ENABLED` is set **and** billing is on. |
| Coding-client gate | `MessagesGateway` (operator-key branch, around lines 273–285) | Blocks on `checkCostBudget()` for the operator key. It does not read `PremiumFeatureGate` or the Stripe configuration, so a positive budget stops these clients even when the website gate is off and even when Stripe is not configured. |

With no Stripe key, the website does not enforce the budget
(`docs/BILLING_SELFHOST.md`, `PremiumFeatureGate::isCostBudgetEnforced()`).
Coding clients that use the operator key still do. A positive override on
a no-Stripe install therefore stops those clients once spend reaches it.
Do not add a second website path around `PremiumFeatureGate`.

## Decisions

1. **One nullable column, not more top-up rows.** `BUSER.BCOST_BUDGET_OVERRIDE`
   decimal(10,2), null. Null means "no exception". A positive amount
   replaces the group budget and the plan budget for that account only.
   Period top-ups still add. The column is never `0`: a plan budget of `0`
   already means unlimited, and a stored zero would not say whether the
   admin meant "unlimited" or "cleared". Clearing the field is the undo.
2. **The formula stays in `checkCostBudget()`.** Chat, statistics, live
   voice, email, and the messages gateway already call it. The group
   amount is a new allow-listed policy, read only from directory groups,
   inside this method. It does not go through `LayeredConfigResolver::resolve()`,
   because that resolver also sees manual groups. `resolveRateLimitLevel()`
   keeps choosing the message and storage tables. It does not choose the
   euro amount. Setting a group to Team does not set €40.
3. **Two admin surfaces, one number.** People → Users sets an exception on
   one account. People → Policies sets the budget on a directory group.
   **Admin → Subscriptions** keeps the shared plans. The user list shows
   the effective monthly base ("€40 · plan", "€250 · Finance", or
   "€250 · this account"). The account editor is a new component;
   `UsersTab.vue` does not grow a form.
4. **Stripe stays €100 steps, and only off the company login.** No package
   catalogue. A purchase is a one-period top-up on top of the base. A
   person with any directory membership is not offered it, even when this
   install has Stripe keys for its local accounts.
5. **Buy before you are blocked, when buying is the channel.** For a person
   who is not a directory member, the statistics budget card gains
   **Add €100**, reusing `createTopupSession()`. The limit modal stays the
   blocked-state path and is not a second checkout implementation.
6. **A top-up return is not a plan change.** The top-up Checkout
   `success_url` carries `kind=topup`. That page says what was added, until
   which date, and what is left. Cancel returns to statistics and says no
   payment was taken.
7. **No Stripe control when Stripe is not the channel.** The button is
   absent, not disabled (U11), when billing is off, the shell is the native
   app, or the person has a directory membership. An admin may still save
   an account exception. The panel says whether the website enforces the
   number, and, for a directory member, which group supplied it.
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
base     = account exception          if the column is set
         = directory group budget     if this person has a directory membership
                                      and at least one of those groups sets one
         = plan monthly               otherwise
budget   = base + completed top-ups whose date falls in the period
allowed  = budget is 0 (unlimited) or charged < budget
```

A directory group budget is the highest positive amount among that
person's directory groups. The stored word `unlimited` on any of those
groups wins over every amount. A group that leaves the field empty does
not take part. Manual groups never take part. An account exception wins
over `unlimited`, so one person inside an unlimited group can still be
capped.

Top-ups still add when a row exists. A purchase never changes the account
exception or the group amount, and neither deletes a purchase. A directory
member is not offered a way to create a purchase. A purchase made before
the person was placed in a directory group keeps counting until the period
ends. Lowering an exception or a group amount does not refund spend
already recorded.

The identity provider assigns membership. Synaplan assigns the amount.
The claim is not a price list. Putting euros in the token is out of scope.

## Company login

OIDC already does the membership half. This track adds the amount.

On People → Policies, when the selected group has `kind=directory`, the
rate-limit section gains **Monthly usage budget**:

- empty — this group does not set a budget
- a positive amount in euros, two decimal places — that amount
- **No monthly limit** — stored as `unlimited`

The existing tier dropdown stays what it is: the message and storage
tables. The helper under the new field says the tier does not fill this
amount in. A manual group does not show the field. `PUT` of
`RATELIMITS.COST_BUDGET_MONTHLY` for a manual group is rejected. The key
is on `PolicyAllowList`. `0` and negatives are rejected. There is no
instance lock for this key: there is no single instance default to lock,
and an account exception is the per-person override.

Several directory groups: the person sees the winning amount and the name
of the group that supplied it. Removing the person from that group in the
identity provider, then signing in again, drops the amount. A browser
login syncs immediately. An API token picks the change up within 5
minutes. Synaplan does not write directory membership.

A directory member's statistics card names the group and has no
**Add €100**. A cost-budget refusal has no stepper and no upgrade button.
The sentence is "Your organization sets this limit. The message was not
sent." The subscription page, for a directory member, is that same
sentence and the current limit. It does not show plan prices or Checkout.
Local accounts on the same install keep Checkout when Stripe is configured.
Turning OIDC on does not turn payment off for them.

The account panel for a directory member shows the group, the group
amount, and the optional exception. Saving an exception says it replaces
the group limit for this person only. **Use the group limit** clears the
column.

```
People → Policies — Finance (company login)
Limit table          [ Team ]     messages and storage
Monthly usage budget [ 250.00 ]   No monthly limit
This group comes from the company login. The login says who is in it.
It does not send this amount. Empty means this group sets no budget.
A higher amount on another company group wins. No monthly limit wins
over an amount.

People → Users — special@example.com
€250 · Finance

Panel
Company login                      Finance
Group limit                        €250.00
This account                       [        ]  Use the group limit
Spent this period                  €209.88

This replaces the Finance limit of €250 for this person only.
Clear it and the Finance limit applies again.
```

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
- **J-UB-2 — Buy more.** The person is not in a directory group. Billing
  is on, the shell is the website. They open statistics, see euros used,
  the budget, what is left, and the end date, and click **Add €100**. Stripe Checkout opens. They pay.
  The return page says €100 was added until that end date and names what
  is left. **Statistics** shows the new remaining. They find the same
  number again from statistics without hunting. Cancel says no payment was
  taken and the budget is unchanged.
- **J-UB-3 — Already blocked.** The person is not in a directory group. A
  coding-client or chat refusal still opens the existing limit modal with
  the same €100 stepper. After payment, the next request is accepted when
  charged spend is under the new budget. The modal does not claim a plan
  upgrade.
- **J-UB-4 — Billing off, and the native app.** No **Add €100** anywhere.
  The admin panel can still save 250. It says the website is not applying
  the number and that coding clients which use the server key are. The
  native app never shows a Stripe button.
- **J-UB-5 — Company login.** Directory sync is on. The identity provider
  puts Ada in the group `finance`. That group already appears under
  People → Groups as a directory group; its member list is not editable.
  An admin opens People → Policies, selects Finance, leaves the limit
  table on Team, and sets the monthly usage budget to €250. The helper
  says the table and the amount are separate. Ada signs in. Her row shows
  "€250 · Finance". Her statistics card says the limit is set by Finance
  and has no **Add €100**. A cost-budget refusal says the organization set
  the limit and the message was not sent, with no payment button. A second
  directory group set to €80 does not lower her. The admin opens her
  account panel, sets €40, and saves: the sentence says this replaces
  Finance for her only, and the row shows "€40 · this account". **Use the
  group limit** returns "€250 · Finance". Someone removes her from
`finance` in the identity provider. Her next browser sign-in shows her
account's plan amount, not €250. If Finance was the only group setting the
limit table, her message and storage limits follow her account level
again. A local account on the same install, with Stripe configured, still
has **Add €100**.

Ten-second findability (U2): the admin finds a person's amount on the user
row in **People → Users**, and a directory group's amount on that group in
**People → Policies**. The person finds used, budget, remaining, and the
end date on the statistics budget card. A directory member also finds the
group name there. A person who can buy finds **Add €100** on that card. A
refusal still offers the purchase only when buying is the channel.

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
else on the plan, or nobody else in the group when the amount came from
one), what it changes (the monthly allowance before purchases, from the
next request), how to undo (**Use the plan**, or **Use the group limit**
when a directory group supplied the amount), where it came from ("this
account", the group name, or "plan").

### Copy

English source. All five locales in the same PR as the first paint.
Placeholders match English.

| Surface | Sentence |
| ------- | -------- |
| Row, inherited | `{amount} · plan` |
| Row, group | `{amount} · {group}` |
| Row, no limit from a group | `No limit · {group}` |
| Row, override | `{amount} · this account` |
| Save | "Only this account. Other {plan} accounts stay at {planAmount}. Takes effect on the next request. Spend already recorded is unchanged." |
| Clear | "This account again uses the {plan} budget of {planAmount}." |
| Clear, group | "This account again uses the {group} limit of {groupAmount}." |
| Exception over a group | "This replaces the {group} limit of {groupAmount} for this person only. Clear it and the group limit applies again." |
| Set by group | "Set by {group}." |
| Organization refusal | "Your organization sets this limit. The message was not sent." |
| Policies helper | "This group comes from the company login. The login says who is in it. It does not send this amount. The limit table above is messages and storage. It does not fill this amount in." |
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
| UB1 | backend-only | Column, formula, directory-group budget, admin read/write, audit, top-up session status, `period_end` on cost-budget refusals. No Vue. |
| UB2 | ota-candidate | People → Users row and panel, including a directory member's group and exception. J-UB-1, J-UB-4. |
| UB3 | ota-candidate | Statistics card, Checkout return and cancel, limit-modal until-date. Hidden for directory members. J-UB-2, J-UB-3. |
| UB4 | no-app-impact | One paragraph in `docs/BILLING_SELFHOST.md`. |
| UB5 | ota-candidate | People → Policies amount on a directory group, and the subscription page for a directory member. J-UB-5. |

UB2, UB3, and UB5 each add their new paths to
`.github/mobile-impact-policy.json` in the same pull request. None of
them touch native purchase or the store shell.

### UB1 — the number

Migration, Galera-safe raw SQL (`ADD COLUMN IF NOT EXISTS`), nullable,
no default other than null. Existing rows inherit the plan.

`checkCostBudget()` resolves `base_budget` by the order in the formula
above. The response gains `budget_source`: `plan`, `group`, or `account`.
When the source is `group` it also gains `budget_group_id` and
`budget_group_name` (the group that supplied the winning value). `GET
/api/v1/subscription/budget` passes them through. `directory_member` is
true when the person has any directory membership, so the clients can hide
payment without re-deriving it.

`GET /api/v1/admin/users/{id}/budget` (admin) returns plan amount, plan
name, override, source, group id and name, whether any directory group
set `unlimited`, top-ups this period, used, remaining, period end,
`gate_enabled`, `billing_enabled`, `directory_member`. The user list gains
`budget_base`, `budget_source`, and `budget_group_name` only — not a usage
sum per row.

The group amount is `BGROUPCONFIG` key `RATELIMITS.COST_BUDGET_MONTHLY`.
The reader loads directory memberships for this user and those rows. It
does not call `LayeredConfigResolver::resolve()`. Highest positive amount
wins. `unlimited` wins over amounts. Empty groups are absent, not zero.
A manual group's row is ignored. `PolicyAllowList` accepts the key.
`GroupPolicyService::validateStoredValue()` accepts a positive decimal
with at most two places, or `unlimited`, and rejects `0`. Saving the key
on a manual group is a 422. There is no instance lock.

`PUT /api/v1/admin/users/{id}/budget` body `{ "amount": "250.00" }` or
`{ "amount": null }`. Rejects negative, rejects `0`, rejects more than
two decimal places, rejects a non-admin. Allows an admin to edit their
own budget (unlike the level change). Audits `admin.user_budget_change`
with the previous value, the new value, and the plan amount. One
transaction.

Tests extend `RateLimitServiceCostBudgetTest`: null inherits the plan, a
positive account exception replaces both the plan and a group, top-ups
still add, `0` is not a stored account value, and a plan budget of `0`
with no exception and no group budget stays unlimited. Directory cases:
one group at €250, €250 winning over €80, `unlimited` winning over €250,
an account exception of €40 winning over `unlimited`, a manual group's
amount ignored, a directory member with no group amount falling through
to the plan, and `RATELIMITS.TIER=TEAM` leaving the euro base unchanged.
Controller test: non-admin 403, bad amount 400, clear back to null, audit
row written, manual-group policy save 422.

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
When `directory_member` is true the panel shows the group name and amount,
and the clear action is **Use the group limit**. Otherwise the clear
action is **Use the plan**. J-UB-5 walks the group branch after UB5.

Empty: a brand-new account with no spend shows "€0.00 spent this period"
and the plan amount, plus **Save** (U5). Error: the save failure is one
sentence and the field keeps what they typed (U8). Billing off: the
Stripe button is not on this panel; the gate sentence is the one in the
copy table (U11).

Exit:

1. J-UB-1 and J-UB-4 walked in the browser (U10).
2. The amount is on the user row (U2).
3. Save, **Use the plan**, and **Use the group limit** use the sentences in the copy table, in all five locales (U3).
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
per-account monthly budget, and may set one on a company-login group.
The website applies either only when the cost-budget gate is on. Coding
clients that use the operator key apply a positive amount even when
Stripe is not configured. A person who is not in a company-login group
buys more only when Stripe is configured. A person in one does not.
A purchase lasts the current period; the standing amount does not.

### UB5 — company login

**User-flow.** J-UB-5 above. Pointer: this file.

The Policies rate-limit section shows **Monthly usage budget** only when
`group.kind` is `directory`, with the helper from the copy table. Empty,
a positive amount, and **No monthly limit** are the three states. A manual
group has no field and no disabled field (U11). Save is the existing
Policies save. The subscription page for a directory member shows the
organization sentence, the current limit, and the group name. It does not
show prices or Checkout. A local account's subscription page is unchanged.

Exit:

1. J-UB-5 walked in the browser, including the second group, the account exception, the removal in the identity provider, and a local account that can still buy (U10).
2. The group amount is on that group's Policies section, and on the member's user row (U2).
3. The helper, the organization refusal, and the exception sentence are the copy table, in all five locales (U3).
4. A directory group with an empty budget, a manual group with no field, and a refused save on a manual group via the API are all present (U5, U8, U11).
5. Light, dark, design v2, 320 px, on Policies and on the directory member's subscription page (U9).

## Out of scope

- Named pay-as-you-go packages, or a step other than €100.
- Editing `BSUBSCRIPTIONS` amounts. That screen already exists.
- Turning `COST_BUDGET_GATE_ENABLED` on for the hosted service.
- A Stripe purchase inside the native app.
- A yearly per-account budget. The gate does not read the yearly plan column.
- Deleting operator-inserted top-up rows from a migration.
- A euro amount inside the OIDC token, or any claim other than the existing group claim.
- Editing directory membership inside Synaplan. That list stays read-only.
- A usage budget on a manual group. The field is not shown there.
- Letting `RATELIMITS.TIER` change the euro budget. The tier remains the message and storage tables, for directory members and for everyone else.
- Summing the budgets of several groups. The higher amount wins, and no limit wins over an amount.

## Operator follow-up

When UB1 is deployed, set the standing amount on any account that was
kept at a custom budget with future `manual-budget-250-*` rows, then
delete those future rows. Until they are deleted, that month's budget is
the override **plus** €210.

## Gate

UB1: `make -C backend lint`, `phpstan`, `deptrac`, `test`.

UB2, UB3, and UB5: that, plus `make -C frontend lint`, `npm run check:types`,
`make -C frontend test`, and `make test-e2e` for the People users flow,
the statistics purchase return, and the directory-group policy. OpenAPI
changes regenerate schemas before `vue-tsc`.
