# Office and mail — Outlook, Word, Excel, Thunderbird, OX

**Status:** Plan 2026-09-17. Outlook is shipped. Everything else is
planned; this file does not reopen Synamail’s auth flow.

---

## 1. What we already have

**Synamail** (`/wwwroot/Synamail`) is the Outlook add-in: Vue 3,
Office.js, `/addin/connect` handshake, Zod from Synaplan OpenAPI.
That auth path is the hardest piece and is already paid for. Read
`Synamail/docs/AUTH_FLOW.md` before touching any Office client.

**Thunderbird** is a planned second host in the same repo
(`docs/THUNDERBIRD_INTEGRATION.md`): MailExtension, same Vue UI,
host adapter. Not started.

**Synaoffice** (Word + PowerPoint, Excel to follow) is estimated in
`Synamail/docs/OFFICE-EXPANSION.md` (~6–8 weeks to a dual-host MVP).
Not started. New repo; copy the shared core, do not extract an npm
package yet.

**OX App Suite** is openDesk’s mail / calendar. No adapter. Catalog
row only until someone ticks a sprint.

---

## 2. Word and Excel (BI8)

Reuse Synamail’s:

- Dialog login + `state` nonce + `/addin/connect`
- `synaplan-client.ts` + generated schemas
- Tokens, i18n set, `ci-local` gate

New work:

| Host | v1 verbs | Office.js |
| ---- | -------- | --------- |
| Word | Rewrite / translate / summarize selection; insert RAG passage with a citation sentence | `Word.run` + `context.sync` |
| Excel | Explain this range; “chart this”; pull a RAG number into a cell **after** preview | `Excel.run` |
| PowerPoint | Outline → slides; speaker notes; summarize deck | `PowerPoint.run` |

**Settings:** `roamingSettings` is Outlook-only. Word / Excel store
the key in `Office.context.document.settings` is **wrong** (it follows
the file). Use `localStorage` (partitioned) or a Synaplan-side device
row. Decide in the Synaoffice §0 checklist; default =
`OfficeRuntime.storage` where supported, else `localStorage`.

**Manifest:** one listing “Synaoffice” targeting Document +
Workbook + Presentation if Excel joins the same repo. Excel in the
same repo is allowed; do not start Excel before Word MVP answers
J-OF-1.

**Journeys**

| Id | Journey |
| -- | ------- |
| J-OF-1 | Word: select a paragraph → Synaplan pane → Translate to German → replacement in the document. Disconnect from the pane. |
| J-OF-2 | Excel: select a table → “Bar chart via Synaplan” → preview image or native chart; nothing written until Confirm. |
| J-OF-3 | Same Synaplan account as Outlook. One Connections row. Revoke once, both add-ins ask to connect again. |

Excel must **never** write cells unattended. Preview + Confirm
(write-class / approve). That is the Tools lesson applied to a grid.

---

## 3. Thunderbird

Stays in Synamail as a second build target. Wave 6 only adds a
catalog card and a docs snippet. Implementation follows the
Synamail plan, not this folder.

---

## 4. OX App Suite (openDesk mail)

Later. When it starts:

- SSO via Nubus / Keycloak (same as other openDesk apps).
- Filepicker already talks to Nextcloud; a Synaplan action is
  “summarize this attachment” / “file the Jitsi transcript next to
  the invite”.
- Do not build an OX-native AI stack. Point OX at Synaplan’s OpenAI
  face or embed the same Connect snippet.

Not v1 of the transcriber. The transcriber writes the file to
Nextcloud and a line to Element; OX can link that file later.
