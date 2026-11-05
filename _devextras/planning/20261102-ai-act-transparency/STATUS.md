# Status — AI Act transparency

Plan: [`00_master_plan.md`](./00_master_plan.md). A walk is a dated entry
here, not a chat message.

**Deadline:** AT1 and AT3 merged and deployed on the hosted platform
before 2026-12-02 (D1).

| Step | Issue | State |
| ---- | ----- | ----- |
| Plan | — | written 2026-11-02; D1 still open |
| AT0 | — | in this branch: `docs/AI_ACT.md`, linked from `docs/ADMIN.md` |
| AT1 | — | in this branch: mail, WhatsApp, Telegram, widget |
| AT2 | — | in this branch: provider-marked bytes stay identical |
| AT3 | — | in this branch: pinned c2patool 0.28.4, XMP fallback, key only on the sidecar |
| AT4 | — | in this branch: file provenance and the chat badge |
| AT5 | — | [synaplan-charts#106](https://github.com/metadist/synaplan-charts/pull/106). Off by default. Key only via Secret. |
| AT6 | — | in this branch: "Using AI well", optional confirmation, default off |
| AT7 | — | not in this branch. TrustMark stays due before 2027-02-02 and is not faked |

## Walks

- 2026-11-05 — System status, signed in as the seeded admin. The content-credentials service is off, with the profile hint `COMPOSE_PROFILES=c2pa make up`. The module is labelled "Content credentials (AI media marking)" and says "Generated media are not signed. Add the signer to mark images, audio and video as AI-generated."
- 2026-11-05 — `/help/using-ai-well`. Title "Using AI well". Ten sections, from "What a model is" through "Who to ask". The page button says the reader has finished.
- 2026-11-05 — People. The switch reads "Not yet" and the count reads "0 of 124 confirmed". The download action is on the page. The switch was left off.
- 2026-11-05 — Sidecar image, not the running app. `POST /sign` of a PNG returned a file c2patool can read, with `c2pa.created`, `trainedAlgorithmicMedia`, and the model name. The temporary CA key is not left in `/certs`. The leaf key is mode `0600`. uid 65532. No host port.
