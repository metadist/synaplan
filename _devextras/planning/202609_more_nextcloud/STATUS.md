# Status — More Nextcloud

Track 6 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md).

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S1 Core handshake (`NC1`–`NC7`) | `synaplan/` `feat/more-nextcloud-s1-handshake`; `Synamail/` docs only; local NC in `synaplan-nextcloud/` `feat/local-nextcloud-wsl` | implemented | Flag `PLATFORM_LINKS.ENABLED` default off. Outlook `client=outlook` is not flag-gated. Local Nextcloud on WSL: `make -C /wwwroot/synaplan-nextcloud dev-up` → http://localhost:8081 (admin/admin). Harness: `_devextras/testing/platform-links/fake-instance.sh`. |
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
rewrites to `/connect/platform?…&client=outlook`. Uncommitted until asked.
