# Status — IAM — groups, sharing, directory

Track 1 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03.**

S1–S3 are **merged to `main`**. Sharing works in the API, but after merge we
had to improve the UI: a group member could not find chats shared with them.
That follow-up lives on `feat/iam-incoming-chats` ([PR #1717](https://github.com/metadist/synaplan/pull/1717)).
S4 Directory & privacy and S5 Group policies start from that branch.

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S0 Concept & UI | — | done | Checklist ticked 2026-09-03; wireframes in S1/S2 |
| S1 Groups core | `synaplan/` `feat/iam-groups-core` | done | Merged to `main` as #1708 |
| S2 Sharing MVP | `synaplan/` `feat/iam-sharing-mvp` | done | Merged to `main` as #1713 |
| S3 More kinds | `synaplan/` `feat/iam-more-kinds` | done | Merged to `main` as #1714 |
| Incoming chats UI | `synaplan/` `feat/iam-incoming-chats` | in review | [PR #1717](https://github.com/metadist/synaplan/pull/1717). History pills/filters, Incoming chats page, red-dot notification, source banner when opening a shared chat |
| S4 Directory & privacy | `synaplan/` `feat/iam-directory-privacy` | in review | [PR #1718](https://github.com/metadist/synaplan/pull/1718). Stacked on #1717 |
| S5 Group policies | `synaplan/` `feat/iam-group-policies` | in review | [PR #1719](https://github.com/metadist/synaplan/pull/1719). Stacked on #1718 |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 18 checklist rows accepted (product-owner questionnaire). Row 1 extended: cross-instance portability = export/import bundle owned by track 2 (roadmap §8.1); groups and shares stay instance-local. |
| 2026-09-03 | Open questions resolved: any owner may share with `everyone` (`IAM.EVERYONE_SHARES` default `any_owner`); shared knowledge sources show the owner's name; conversation `use` copies file *references*; audit retention 365 days. |
| 2026-09-03 | S4 gains a regression check for OpenCloud token-exchanged users (same `BUSER`, therefore same groups and shares) — consequence of track 6 excluding OpenCloud. |
| 2026-09-03 | S0 closed except wireframes (People, ShareDialog), which are the first deliverable of S1. |
| 2026-09-05 | S1 shipped on `main`. S2 started on `feat/iam-sharing-mvp`. Public docs: `synaplan-docs` `feat/docs-people-and-groups`. |
| 2026-09-06 | S2 (#1713) and S3 (#1714) merged to `main`. The planned "Shared with me" filter had landed only on the statistics chat browser, so a group member could not find incoming chats. Extra UI sprint on `feat/iam-incoming-chats`: History sheet + ChatBrowser show incoming as group pills and own chats as private, with filter buttons; Account gets a red dot and **Incoming chats** (`/chats/incoming`, sibling of the Files inbox at `/files/incoming`); opening a shared chat names the owner and the group / everyone / person it came through. |
| 2026-09-06 | S5 will introduce `IAM.GROUP_POLICIES_ENABLED` (seeded `0`) as a fourth flag beyond master plan §0 row 11. Off ⇒ resolvers stay `[user, global]` (C1). Recorded here as required by [`05_sprint_5_group_policies.md`](./05_sprint_5_group_policies.md). |

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

**2026-09-06:** S3 merged. Copilot lite review on S3: Share dialog / subject
search catch API errors; conversation access no longer defaults to writable
while the check is in flight.

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

**2026-09-06 (incoming chats UI):** After S2/S3 a member of a group still
could not see a chat shared with that group. The plan said "no new top-level
nav item" and "filter chip on existing lists", but the only chip was on the
statistics browser. We kept the lean-nav contract and added:

- Kind pills + All / Private / Group filters on History and the detailed list.
- Account red dot + **Incoming chats** (not a new rail item).
- `/chats/incoming` as the chat inbox (Files → Incoming stayed at `/files/incoming`).
- Opening a shared chat now answers the five-question check: who owns it, which
  group (or everyone / person) it came through, what the viewer may do.

**2026-09-06 (S4 / S5):** S4 is [PR #1718](https://github.com/metadist/synaplan/pull/1718).
S5 Group policies is [PR #1719](https://github.com/metadist/synaplan/pull/1719):
`BGROUPCONFIG`, `BCONFIG.BLOCKED`, `LayeredConfigResolver`, People → **Policies**,
and locked / group-set defaults on the user model settings page. Flag
`IAM.GROUP_POLICIES_ENABLED` seeds off (C1). Merge order: #1717 → #1718 → #1719.
