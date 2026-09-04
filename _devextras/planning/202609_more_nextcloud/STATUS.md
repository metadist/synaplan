# Status — More Nextcloud

Track 6 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03; awaiting technical plan review
before the first sprint starts.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S1 Core handshake | — | planned | |
| S2 Nextcloud app | — | planned | |
| S3 Parity & fallbacks | — | planned | |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 14 checklist rows accepted: `link` mode added beside `shared` and `provision`; auth-code style handshake; registered instances; `BEXTERNALIDENTITIES` rows; 1:n identities; email conflict → link offer; same key scopes; no merge; Linked platforms page; NC admin settings shrink. |
| 2026-09-03 | Open questions resolved: instance registration via admin key **and** pending-approval UI; signed-in account only on the confirm screen; auto-provision inside `link` mode is admin opt-in. |
| 2026-09-03 | Parity scope verified in code: **ownCloud Online yes** (same provisioning model, `UserAccountService.php`); **OpenCloud out** (RFC 8693 token exchange already gives per-user identity; regression check moves to IAM S4). `AddinConnectView` is generalized with Synamail `docs/AUTH_FLOW.md` as S1 acceptance. |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written. Next: technical plan review (roadmap §7 step 3).
