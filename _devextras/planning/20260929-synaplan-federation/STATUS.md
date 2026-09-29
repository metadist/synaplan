# Federation v1.0 — status

Reviewed 2026-09-29. **Queued for the next sprint** as the knowledge
link only. The review and the cut are in [`01_review.md`](./01_review.md).
No product code yet.

## Next sprint

| Step | State | Notes |
| ---- | ----- | ----- |
| F0 | queued | Protocol doc: identity, link, query. No `/infer` |
| F1 | queued | Module, identity, signer, address parser. Check `ext-sodium` first |
| F3 | queued | Invite / accept / revoke. Peer record stored on accept |
| F4 | queued | Knowledge query over one `RagScope`, SSRF checks, excerpt fence |
| F5 | queued | Admin UI, after F4 is green |
| F6 | queued | Chat palette + source card, after F5 is green |

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

Schema for `federation_link` + `federation_topic` + instance identity.
`ext-sodium` in the backend image. Seeds may stay empty.

## Data model

What is published, how updates spread, storage/bandwidth for a 15k-instance
network, the zero-trust model, and MCP/API-call sharing are specified in
[`02_federation_data_model.md`](./02_federation_data_model.md).
