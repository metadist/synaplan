# Synaplan Partners (federation) — status

**Start here: [`05_partners.md`](./05_partners.md).** It is the binding plan:
open to partners, invite links, the Synaplan Directory on `web.synaplan.com`,
topics, assistants, copies. Product decisions are recorded in `05` §12
(answered 2026-09-29). No product code yet.

Reading order for the rest of this folder:

| File | Role now |
| ---- | -------- |
| [`05_partners.md`](./05_partners.md) | **Binding plan and build order (M0–M6)** |
| [`04_portable_sharing_migration.md`](./04_portable_sharing_migration.md) | Binding for the portable format: `widgets` + `knowledge` bundle sections, client migration. Feeds M4 |
| [`03_verdict_and_orders.md`](./03_verdict_and_orders.md) | Safety findings (§3) still binding; its Orders are replaced by `05` §9 (differences in `05` §11) |
| [`01_review.md`](./01_review.md) | First review; findings 1–13 still true |
| [`00_master_plan.md`](./00_master_plan.md), [`02_federation_data_model.md`](./02_federation_data_model.md) | Vision and history. Token market, payments and gossip are parked |

## Build order — `05` §9

| Step | State | Notes |
| ---- | ----- | ----- |
| M0 | queued | §12 is decided. Left: confirm §1 translations, EN copy + wireframes for J-P1–J-P8, name two design partners. Docs only |
| M1 | in progress | Open to partners, invite link, connect, pause, disconnect. `ext-sodium` is present in the backend image |
| M2 | queued | Share a topic (*Can ask*), Sources → From partners, consent line, answer card |
| M3 | queued | Share an assistant to chat (*Can chat*), limits, Assistants → From partners |
| M4 | queued | Share a copy (*Can copy*) for assistants, prompts, widgets. Needs `04` P1–P3 |
| M5 | queued | Synaplan Directory + `synaplan-platform` rollout. May start after M1; goes live after M2 |
| M6 | queued | Harden, two weeks of real use, freeze `protocol: 1`, admin docs |

## Portable format + migration — `04` §8

| Step | State | Notes |
| ---- | ----- | ----- |
| P0 | queued | Plan review, EN copy before Vue |
| P1 | queued | `widgets` bundle section |
| P2 | queued | `knowledge` manifest + file archive + re-vectorize |
| P3 | queued | Folder rebinding for prompts/assistants in the same bundle |
| P4 | queued | Export/Import panel + morning-after report. **Independent of M1–M6; may ship any time** |
| P5–P6 | replaced | Sending a bundle to a partner is now *Can copy* in `05` M4 |
| P7 | queued | `docs/PORTABLE_SETUP.md` |

## Parked (re-entry rules in `03` §7)

Token market and `peer` key source, ledger and settlement, gossip directory,
remote tool calls (MCP bridge), transitive trust, `@*` fan-out, the Discord
bot. Business accounts on shared servers are **later**, not parked: `05` §10
item 1, separate plan.

## Decided 2026-09-29 (`05` §12)

Partners, on public https servers, closed until an admin opens it. Partners
are whole servers. Invite links first; Directory on `web.synaplan.com` goes
live after topics work, listing opt-in. Share dialog with `BSHARES` subject
`partner`. Hosted chats store counts only. Limits 500 / 200 / €20. Admins
only may share. Build order M1 → M2 → M3 → M4, then M5 live.

## Still open before code

- Confirm the §1 translations, and the EN copy plus wireframes for J-P1–J-P8.
- Name two design partners (needed before the M6 two-week run, not before M1).
- `ext-sodium` in the backend image. M1 checks this and stops if it is missing.
