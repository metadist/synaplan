# Federation v1.0 — status

Reviewed 2026-09-29. **Queued for the next sprint** as the knowledge
link only. The review and the cut are in [`01_review.md`](./01_review.md),
the binding simplification + orders in
[`03_verdict_and_orders.md`](./03_verdict_and_orders.md).
Portable sharing + client migration (prompts, widgets, data) is planned in
[`04_portable_sharing_migration.md`](./04_portable_sharing_migration.md).
No product code yet.

## Next sprint — live knowledge link (`03`, Orders 0–7)

| Step | State | Notes |
| ---- | ----- | ----- |
| Order 0 | queued | Name the first two operators + folders. No code |
| Order 1 | queued | Shrink the plan (docs-only) |
| Order 2 | queued | Sodium + well-known spike. No migration |
| Order 3 | queued | Link invite / accept / revoke. 1 table |
| Order 4 | queued | Knowledge query over one `RagScope`. 1 table |
| Order 5 | queued | Admin UI, after Order 4 is green |
| Order 6 | queued | Chat palette + source card, after Order 5 is green |
| Order 7 | queued | Harden, measure, freeze `protocol: 1` |

Old F-labels (F0/F1/F3–F6) map to Orders 2–6; see `03` for the mapping.

## Next after that — portable sharing + migration (`04`, P0–P7)

| Step | State | Notes |
| ---- | ----- | ----- |
| P0 | queued | Plan review + §2 decisions + EN copy before Vue |
| P1 | queued | `widgets` bundle section (backend-only) |
| P2 | queued | `knowledge` manifest + file archive + re-vectorize (backend-only) |
| P3 | queued | Prompt/agent folder rebinding in the same bundle (backend-only) |
| P4 | queued | Export/Import panel + morning-after report (J-PM-1..3). **May ship before federation P5** |
| P5 | queued | Federation share transport (needs `03` Orders 3–5 green) |
| P6 | queued | Share UI + incoming-shares inbox (J-PM-4) |
| P7 | queued | `docs/PORTABLE_SETUP.md` migration runbook |

## Parked until two installs can query each other

| Step | State | Notes |
| ---- | ----- | ----- |
| F2 | parked | Gossip. Two partners sync on accept |
| F7 | parked | Capacity market and `peer` key source in `MessagesGateway` |
| F8 | parked | Ledger and receipts |
| F9 | parked | Settlement. `StripeBillingModule` is not this seam |
| F10 | parked | Market UI and Cursor routing |
| F11 | parked | Denylists, anti-Sybil |
| F12 | parked | Directory browse and health view |
| F13 | parked | Third-party protocol doc beyond the knowledge link |

## Open before the first migration

- Federation link (`03`): schema for `federation_link` + `federation_publication`
  + instance identity (keypair in secret store, no table).
- `ext-sodium` in the backend image (check inside the container first).
- Portable sharing (`04` P1–P4): no schema expected (bundle sections only).
- Seeds stay empty. No money, no gossip, no jobs in v1.

## Data model

What is published, how updates spread, storage/bandwidth for a 15k-instance
network, the zero-trust model, and MCP/API-call sharing are specified in
[`02_federation_data_model.md`](./02_federation_data_model.md) (vision; mostly
parked by `03`). Portable sharing + migration (widgets, knowledge manifest +
file archive, federation share transport) is specified in
[`04_portable_sharing_migration.md`](./04_portable_sharing_migration.md).
