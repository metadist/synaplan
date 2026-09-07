# Status — More Nextcloud

Track 6 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md).

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S1 Core handshake (`NC1`–`NC7`) | `synaplan/` `feat/more-nextcloud-s1-handshake` → [#1745](https://github.com/metadist/synaplan/pull/1745); `Synamail/` docs only (`docs/AUTH_FLOW.md`); local NC in `synaplan-nextcloud/` `feat/local-nextcloud-wsl` | reviewed, in PR | Flag `PLATFORM_LINKS.ENABLED` default off. Outlook `client=outlook` is not flag-gated and now goes through `POST /api/v1/addin/connect`. Local Nextcloud on WSL: `make -C /wwwroot/synaplan-nextcloud dev-up` → http://localhost:8081 (admin/admin). Harness: `_devextras/testing/platform-links/fake-instance.sh`. User docs: `synaplan-docs/docs/platform-links.md`. |
| S2 Nextcloud app | — | planned | Wave 3 — `link` mode in the Nextcloud app |
| S3 Parity & fallbacks | — | planned | |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 14 checklist rows accepted: `link` mode added beside `shared` and `provision`; auth-code style handshake; registered instances; `BEXTERNALIDENTITIES` rows; 1:n identities; email conflict → link offer; same key scopes; no merge; Linked platforms page; NC admin settings shrink. |
| 2026-09-03 | Open questions resolved: instance registration via admin key **and** pending-approval UI; signed-in account only on the confirm screen; auto-provision inside `link` mode is admin opt-in. |
| 2026-09-03 | Parity scope verified in code: **ownCloud Online yes** (same provisioning model, `UserAccountService.php`); **OpenCloud out** (RFC 8693 token exchange already gives per-user identity; regression check moves to IAM S4). `AddinConnectView` is generalized with Synamail `docs/AUTH_FLOW.md` as S1 acceptance. |
| 2026-09-07 | **UX contract.** J-NC-1…3: one confirm card, no typed key, disconnect from either side, email-conflict offer in `link` mode. |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written. Next: technical plan review (roadmap §7 step 3).

**2026-09-07 (UX contract):** keep the handshake as a flow, not a
settings form. See
[`../202609_ux_user_flows.md`](../202609_ux_user_flows.md) §5.6.

**2026-09-07 (S1 implementation):** Wave 2 S1 landed on
`feat/more-nextcloud-s1-handshake` — `BPLATFORMINSTANCES`, instance
register/approve/revoke, link-code + exchange, `PlatformConnectView`,
Linked platforms pages, Synamail `AUTH_FLOW.md`, `fake-instance.sh`.
S2 (`link` mode in the Nextcloud app) stays Wave 3.

**2026-09-07 (Wave 2 check):** `fake-instance.sh --flag-off` 4/4;
`fake-instance.sh` 12/12 (register as seeded admin, code as demo).
Local Nextcloud 31.0.14 on `:8081` (admin/admin), Synaplan Integration
settings page loads. Synaplan UI: Linked platforms empty state;
Operate → People → Linked platforms lists pending/active; `/addin/connect`
rewrites to `/connect/platform?…&client=outlook`.

**2026-09-07 (code review of #1745):** findings and fixes, all in the PR:

| # | Finding | Fix |
| - | ------- | --- |
| 1 | `PlatformConnectView` posted the fresh add-in key to `window.opener` with target origin `*` (CodeQL cross-window leak; a phishing popup on the bridge URL would receive the key). | Opener channel removed. Fallback is Office `messageParent` only, which Office restricts to the add-in that opened the dialog. |
| 2 | The `outlook-builtin` allow-list (NC1: "today's `isSafeRedirect()` moved server-side") was dead data: `RedirectUriPolicy` could not match a candidate against a `*` instance host, and the bridge still carried its own client-side copy (CodeQL client-side redirect + XSS). | New `POST /api/v1/addin/connect` (`OutlookConnectService`, not flag-gated) mints the key and builds the relay URL on the server; the bridge follows `response.redirect`. Client allow-list deleted. |
| 3 | `prefixMatches()` never matched a registered root URI (`https://host` → path `/` → `str_starts_with($path, '//')`). | Root prefix accepts every path on that origin; test added. |
| 4 | `normalizeHost()` accepted `*`, which would let a partner register an any-host instance once the wildcard branch is relaxed. | Wildcard hosts rejected at registration; only the seeded built-in row carries `*`. |
| 5 | `LinkCodeService::consume()` did GET then DEL — two concurrent exchanges could both mint a key (C5). | `RedisService::getAndDelete()` (GETDEL, Redis 7.4); test asserts GET/DEL are never used. |
| 6 | `external_id` / `state` unbounded → `BEXTERNALIDENTITIES.BEXTERNALID VARCHAR(191)` would 500 at exchange. | Capped at 191 / 512 with a 400 at code issue. |
| 7 | `AddinConnectView.vue` was dead code (route is a redirect) and carried the same three CodeQL alerts that are open on `main`. | Deleted with its i18n namespace; `/addin/connect` redirect and its spec stay. |
| 8 | Port matching was strict for `https://localhost` in the built-in list while the Synamail dev server runs on `:3000`. | Port-less prefixes on local-dev hosts accept any port; every other host stays strict (corpus test unchanged). |

Second round (Copilot + Bugbot), same PR:

| # | Finding | Fix |
| - | ------- | --- |
| 9 | `officeReady` was set `true` even when `loadOfficeJs()` failed, so its guard never blocked anything — the key was minted and only then failed to deliver. | `officeChannelAvailable` (true only when `Office.context.ui.messageParent` exists). With no relay **and** no Office channel the view errors before calling the server. The guard also no longer blocks relay connects, which never need Office.js. |
| 10 | `ApiKeyController::list` resolved `linked_platform` with two queries per key. | `linkedPlatformsForKeys()` — two queries for the whole list. |
| 11 | Connect and retry buttons carried a bare `btn-primary` (no padding/text size). | Full house chain on both. |
| 12 | CI-only failure: `doctrine:fixtures:load` runs after the migrations and purges `BPLATFORMINSTANCES`, dropping the seeded `outlook-builtin` row. | `PlatformLinksConfigSeeder` re-asserts the row insert-if-missing; repository test covers delete → seed → present → idempotent. |
| 13 | **`upsert()` only set `userId` on insert.** Exchanging the same `(client, instance, external_id)` for a different Synaplan user updated the key but left the row with the previous owner, who could then disconnect it and revoke the new owner's key — while the new owner never saw the link. | The row is keyed by the external identity, so it is re-owned on update; the move is audited under both accounts as `platform_link.reassigned`. Functional test asserts the old owner loses the link and gets 404 on disconnect (it fails without the one-line fix). |

Not changed, noted for S2/S3: anonymous registration has no per-host dedupe,
only the 10/h IP limit.

Gate after the fixes: `make lint` ✓, `make -C backend phpstan` ✓ (0 errors),
`make test` ✓ (PHPUnit 5569, Vitest 223 files), `vue-tsc` ✓,
`fake-instance.sh --flag-off` 4/4, `fake-instance.sh` 12/12. CI on #1745 green
including CodeQL. Synamail `docs/AUTH_FLOW.md` step 5 and invariant 6 rewritten
for the server-side list (metadist/Synamail#70).
