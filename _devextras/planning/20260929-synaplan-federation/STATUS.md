# Federation v1.0 — status

Step log for [`00_master_plan.md`](./00_master_plan.md). All steps are
**planned** until the §0 decision checklist is ticked and the Ask-First items
(§14) are approved. No product code has landed yet.

| Step | Type | State | Notes |
| ---- | ---- | ----- | ----- |
| F0 | plan | in review | This plan; §0 open, `docs/FEDERATION_PROTOCOL.md` not yet written |
| F1 | backend | planned | Module, identity, well-known, signer, address parser, health engine |
| F2 | backend | planned | Directory store + migration, digest, gossip replication |
| F3 | backend | planned | Federation link invite/accept/revoke |
| F4 | backend | planned | Knowledge responder/client + excerpt sanitizer |
| F5 | ota-candidate | planned | Admin UI: membership + links + publications |
| F6 | ota-candidate | planned | Chat palette federation section + source card |
| F7 | backend | planned | Capacity market, quote, broker responder/client, `peer` key source |
| F8 | backend | planned | Ledger: receipts, balances, reconcile |
| F9 | backend | planned | Settlement provider + null + Stripe (Ask-First) |
| F10 | ota-candidate | planned | Capacity market UI + ledger view + transparent routing |
| F11 | backend | planned | Health hardening, anti-Sybil, denylists, reports |
| F12 | ota-candidate | planned | Directory browse + activity + peer-health view |
| F13 | docs | planned | Finalise protocol doc for third-party implementers |

## Open decisions (from §0)

All 13 rows open. Blocking Ask-First items: schema migration (#12), payments /
Stripe (#7), `ext-sodium` verification (#13), seeds (#4/§14), governance docs.
