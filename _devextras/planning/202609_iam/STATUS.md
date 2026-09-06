# Status — IAM — groups, sharing, directory

Track 1 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03.**

S1 Groups core is **merged to `main`** (`feat/iam-groups-core`, PR #1708).
S2 Sharing MVP is on `feat/iam-sharing-mvp` (IAM11–IAM20; PR #1713 green).
S3 More kinds is on `feat/iam-more-kinds` (IAM21–IAM28; [draft PR #1714](https://github.com/metadist/synaplan/pull/1714) stacked on S2, targeting `main` so CI runs).

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S0 Concept & UI | — | done | Checklist ticked 2026-09-03; wireframes in S1/S2 |
| S1 Groups core | `synaplan/` `feat/iam-groups-core` | done | Merged to `main` as #1708 (`8e8ad71ef`) |
| S2 Sharing MVP | `synaplan/` `feat/iam-sharing-mvp` | in review | IAM11–IAM20; PR #1713 All Checks Passed |
| S3 More kinds | `synaplan/` `feat/iam-more-kinds` | draft PR | IAM21–IAM28; [PR #1714](https://github.com/metadist/synaplan/pull/1714) stacked on #1713, opened against `main` for CI. Public docs: [synaplan-docs#14](https://github.com/metadist/synaplan-docs/pull/14) |
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
| 2026-09-05 | S1 shipped on `main`. S2 started on `feat/iam-sharing-mvp`. Public docs: `synaplan-docs` `feat/docs-people-and-groups`. |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written.

**2026-09-05:** S1 merged. Sharing MVP implementation started.

**2026-09-05 (S2):** BSHARES, share API, AccessGate, RagScope, continue-as-copy,
ShareDialog, Shared with me. Public docs cover groups + sharing (flag-off by
default). Apply `Version20260905140000` to both the app DB and `synaplan_test`.

**2026-09-05 (S3):** Assistant, saved task, and widget kinds on the S2 rails.
Shared assistants enter lists via `PromptRepository` (classifier untouched).
Saved tasks copy as the member's own run. Widgets support read and co-edit;
embed and sessions stay owner-only. Plugin manifests may declare
`provides.resourceKinds`.

**2026-09-06:** PR #1714 converted back to draft. Copilot lite review on S3:
Share dialog / subject search catch API errors; conversation access no longer
defaults to writable while the check is in flight.

**2026-09-06 (pre-merge security + house-rules review of S1–S3):** findings
fixed on `feat/iam-sharing-mvp` (S2, merged into S3):

- `shared_file_ref` no longer outlives the share — RAG scopes and file reads
  follow the *live* conversation/folder share only (C2 acceptance "revoke ⇒
  next search returns no shared source" now actually holds).
- `GET /chats/{id}` returns the public `shareToken` only to the owner.
- `AccessGate` denies stale `BSHARES` rows whose resource is gone (id reuse).
- Sharing with yourself → 400; "Everyone" is only offered when
  `IAM.EVERYONE_SHARES` allows the actor; error text without internal ids.
- `BSHARES.BGRANTEDBY` index; RAG file ids bound as parameters; complete
  401/403/400 OpenAPI responses; share client on generated Zod schemas.
- People/Share UI: theme tokens instead of raw Tailwind colours; users-tab
  load errors via i18n (all five locales).

On `feat/iam-more-kinds` (S3): shares on plugin-declared kinds are removed
when their owner is deleted (`ShareRepository::deleteByPluginDataIds`);
widget visitor stats only for the owner; OpenAPI for prompt list IAM fields,
widget get, saved-task copy; runtime-config `iamSharing` description.

By design and left as is: `manage` grantees may re-share (master plan row 3);
knowledge-folder `edit` is accepted by the API but no file mutation honours
it yet (MVP scope, see docs/ADMIN.md); subject search lists any user by
name/email (standard share-picker behaviour, instance = organization).
Follow-ups (not blocking): `UsersTab.vue` is 410 lines (moved from
`AdminView`, split later); `PromptController::list` still builds queries
inline (pre-existing).
