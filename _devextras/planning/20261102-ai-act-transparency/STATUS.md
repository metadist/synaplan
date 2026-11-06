# Status — AI Act transparency

Plan: [`00_master_plan.md`](./00_master_plan.md). A walk is a dated entry
here, not a chat message.

**Deadline:** AT1 and AT3 merged and deployed on the hosted platform
before 2026-12-02. No counsel review (D1).

| Step | Issue | State |
| ---- | ----- | ----- |
| Plan | — | closed 2026-11-06. AT0–AT6 are on `main`. D1–D7 are decided. |
| AT0 | — | done. `docs/AI_ACT.md`, linked from `docs/ADMIN.md` |
| AT1 | — | done. Mail, WhatsApp, Telegram, widget |
| AT2 | — | done. Provider-marked bytes stay identical |
| AT3 | — | done. Signer runs inside the app container (uid 65532, localhost). XMP fallback remains. No second container. |
| AT4 | — | done. File provenance and the chat badge |
| AT5 | — | done. [synaplan-charts#107](https://github.com/metadist/synaplan-charts/pull/107). Off by default. Key only via Secret. |
| AT6 | — | done. "Using AI well", optional confirmation, default off |
| AT7 | — | will not do. No TrustMark. `/verify` keeps `"watermark": null` |

## Walks

- 2026-11-05 — System status, signed in as the seeded admin. The content-credentials service is off, with the profile hint `COMPOSE_PROFILES=c2pa make up`. The module is labelled "Content credentials (AI media marking)" and says "Generated media are not signed. Add the signer to mark images, audio and video as AI-generated."
- 2026-11-05 — `/help/using-ai-well`. Title "Using AI well". Ten sections, from "What a model is" through "Who to ask". The page button says the reader has finished.
- 2026-11-05 — People. The switch reads "Not yet" and the count reads "0 of 124 confirmed". The download action is on the page. The switch was left off.
- 2026-11-05 — Sidecar image, not the running app. `POST /sign` of a PNG returned a file c2patool can read, with `c2pa.created`, `trainedAlgorithmicMedia`, and the model name. The temporary CA key is not left in `/certs`. The leaf key is mode `0600`. uid 65532. No host port.
- 2026-11-05 — The extra container is removed. The same signer starts inside the backend, worker and scheduler, on 127.0.0.1, as uid 65532. The key stays a file under `/certs`. linux amd64 only.
- 2026-11-06 — D1 and D2 closed. No lawyer confirms the date or the role split. No public C2PA certificate; `signingCredential.untrusted` stays.
- 2026-11-06 — AT7 and D5 closed. No invisible watermark. `/verify` keeps `"watermark": null`. The plan is closed: AT0–AT6 are on `main`.
