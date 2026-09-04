# Status — IAM — groups, sharing, directory

Track 1 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03; awaiting technical plan review
before the first sprint starts.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S0 Concept & UI | — | done | Checklist ticked 2026-09-03; wireframes move to S1 |
| S1 Groups core | — | planned | |
| S2 Sharing MVP | — | planned | |
| S3 More kinds | — | planned | |
| S4 Directory & privacy | — | planned | |
| S5 Group policies | — | planned | |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 18 checklist rows accepted (product-owner questionnaire). Row 1 extended: cross-instance portability = export/import bundle owned by track 2 (roadmap §8.1); groups and shares stay instance-local. |
| 2026-09-03 | Open questions resolved: any owner may share with `everyone` (`IAM.EVERYONE_SHARES` default `any_owner`); shared knowledge sources show the owner's name; conversation `use` copies file *references*; audit retention 365 days. |
| 2026-09-03 | S4 gains a regression check for OpenCloud token-exchanged users (same `BUSER`, therefore same groups and shares) — consequence of track 6 excluding OpenCloud. |
| 2026-09-03 | S0 closed except wireframes (People, ShareDialog), which are the first deliverable of S1. |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written. Next: technical plan review (roadmap §7 step 3).
