# Phase M — Chat actions: M365 mail search, Outlook calendar write, multi-destination documents

**Status:** Planned 2026-08-18 (product decision recorded, no code yet). **Branch:** `feat/m365-flow`.
**Depends on:** everything merged via PR #1497 (foundations F1/F2/F3/F4/F5, Saved Tasks E1–E19, M365 mail-read connection, `GraphClient`) and PR #1502 (WebDAV/CalDAV delivery, `save_to_folder`, planner channels).
**Gates:** [`07_connectors.md` §7](./07_connectors.md#7-sign-off-gate-tick-before-any-connector-code) per-connector readiness rows for C3 (remainder), C4, C5/OneDrive, C11. S6 (mutating external actions) was decided 2026-08-18 with this phase; S5 (live test accounts, named owners) is **still open and blocks the live-verification steps**.

---

## 0. Product decision (2026-08-18)

The product owner locked the next acceptance bar: **the flagship utterances must work against the user's real systems**, not only as downloadable files. This revises master-plan checklist rows 5 and 10 (see the notes there). The honest-copy rule stays: copy says "Outlook" only for a connected Microsoft 365 account, and the `.ics` / `email_me` path remains the no-connection fallback forever.

Everything previously planned and shipped (Saved Tasks, WebDAV/CalDAV, M365 mail-read consent) is the floor this phase builds on. Nothing in this phase may regress it — the compatibility invariants C1–C6 and the characterization suite apply unchanged.

---

## 1. The four acceptance utterances

Each utterance is an acceptance test, an E2E test, and a documentation example. They are quoted verbatim in tests so wording drift is visible in review.

### U1 — Outlook calendar write (the headline)

> "Create a meeting reminder for tomorrow at 10am for 'Marketing Strategy' and put it into my Outlook."

**Expected:** a real event appears in the user's Office.com / Outlook calendar (Graph `POST /me/events`), duplicate-safe on re-run. The chat reply confirms with the event's web link; the `.ics` download stays attached as the artifact.

| Piece | State |
| ----- | ----- |
| `calendar_event` node (title/start/timezone resolution) | ✅ Shipped |
| CalDAV calendar delivery incl. S13 dedup (deterministic UID, query-before-write, create-only PUT) | ✅ Shipped — **the dedup design is reused, second backend** |
| Graph calendar **read** (`Calendars.Read`, duplicate check) | ❌ Step **M4** (= K4a) |
| Graph calendar **write** (`Calendars.ReadWrite`, mutating) | ❌ Step **M5** (= K4b) |
| Scope upgrade + re-consent for existing connections | ❌ Step **M2** |
| Planner: route "into my Outlook" to the m365 calendar channel | ❌ Step **M6** |

### U2 — Mail me the calendar entry (regression lock, not new work)

> "…and mail the calendar entry to me."

**Expected:** unchanged — `calendar_event` → `email_me` mails the `.ics` to the account owner. **Already works.** This phase adds it to the characterization/E2E suite as a named regression so U1's planner changes cannot break it (step **M1**).

### U3 — Mail search + summarize, IMAP *or* Microsoft 365

> "What is the latest mail of Oliver Braun regarding FPSenergy, summarize that for me."

**Expected:** `email_search` finds the newest matching message across **all** connected mail sources — IMAP handlers *and* M365 connections — then `summarize` works on the full body of the top hit.

| Piece | State |
| ----- | ----- |
| `email_search` over IMAP (`ImapMailboxSearcher`, read-only) | ✅ Shipped (flag `MULTITASK.EMAIL_SEARCH_ENABLED`, default **off**) |
| `MailboxSearcher` interface | ✅ Exists — the seam for the Graph implementation |
| Graph mail search (`GET /me/messages` with `$search`/`$filter`) | ❌ Step **M3a** |
| Graph message **body** fetch (search returns previews only; summarize needs the body) | ❌ Step **M3b** |
| `EmailSearchRunner` iterates M365 connections alongside IMAP handlers; availability note lists both | ❌ Step **M3c** |
| Flag default: seed `EMAIL_SEARCH_ENABLED = 1` (insert-if-missing, operator `0` survives) | ❌ Step **M3d** — without this the story is dead on arrival on every install |

### U4 — Generate a document and push it to a named target

> "Create a marketing plan document with a solid TOC for my company and put it into: (Outlook | Nextcloud | openCloud)."

**Expected:** a DOCX with a real table of contents is generated and delivered to the spoken target. Target recognition uses the existing planner-channel mechanism (`save_to_folder` + `params.channel`) — **never string-matching in a runner**.

| Target word | Maps to | State |
| ----------- | ------- | ----- |
| "Nextcloud" | `save_to_folder` → WebDAV provider (`nextcloud` channel) | ✅ Shipped |
| "openCloud" | OpenCloud write — mechanism decided by the **C11 spike** (WebDAV app token / CS3 upload / token exchange) | ❌ Step **M8** (spike first, then adapter) |
| "Outlook" | See decision **D1** below | ❌ Step **M7** |
| "solid TOC" | DOCX generation keeps headings but emits **no TOC field** today | ❌ Step **M0** |

**Decision D1 — what does "put a document into Outlook" mean?** Outlook is a mailbox, not a file store. Two honest interpretations:

1. **Recommended (v1 of this phase):** map it to `email_me` — the document arrives as an attachment in the user's Outlook inbox. Zero new scopes, works today, copy says "sent to your inbox".
2. **Later (own step, M9 = K5a):** OneDrive file drop via Graph (`Files.ReadWrite`), offered as a distinct channel named `onedrive` — never sold as "Outlook".

Proposed default is (1) now, (2) as a follow-up step in this phase if time allows. **Product owner confirms at the M6/M7 review.**

---

## 2. Architecture deltas

### 2.1 Incremental consent (scope tiers) — the one genuinely new mechanism

`MicrosoftOAuthConfig::SCOPES` is today a fixed list ending at `Mail.Read`. Calendar write needs `Calendars.ReadWrite` (and OneDrive later needs `Files.ReadWrite`). Design once, per the OAuth framework's spirit:

- **Scope tiers on the provider config:** `mail` (today's set), `calendar` (+`Calendars.ReadWrite`), `files` (+`Files.ReadWrite`). The consent URL requests the union of tiers the operator has enabled (new BCONFIG keys `M365 / SCOPE_CALENDAR`, `M365 / SCOPE_FILES`, default off ⇒ behavior identical to today).
- **Granted scopes are already stored** on the connection (`scopes` column). A capability that needs a scope the connection lacks does **not** fail at Graph: the runner returns a failed node with the shared-vocabulary reason `reauth needed` and the connection row shows an **"Upgrade access"** action that re-runs consent with the higher tier. Microsoft keeps prior grants; the refresh token after re-consent covers the union.
- **Admin side:** the Azure setup guide (`M365SetupGuide.vue`) gains the two optional permission rows with the same copyable-scope UX; docs state that adding scopes in Azure alone does nothing until the operator enables the tier and users re-consent.

### 2.2 Graph calendar (read + write) — second backend of one dedup design

- One shared idempotency contract with CalDAV (S13): deterministic event UID from task/message id, **read-before-write** (Graph: `GET /me/calendarView` for the time window filtered by our UID stored in `singleValueExtendedProperties` or `transactionId`), create-only semantics, "already exists" counts as delivered.
- Mutating invariant (S6, decided 2026-08-18): planner may propose, but the write runs only after **confirmation on interactive runs**; scheduled runs require the task's `allow_unattended`; every write leaves an audit record (who/when/connection/event id). Same machinery as CalDAV delivery — do not fork it.
- New `DestinationProvider` id `m365_calendar` on the F4 seam **and** a runner path so the interactive DAG (`calendar_event` → deliver) and `POST /files/{id}/send` both work.

### 2.3 Mail search backend abstraction

`EmailSearchRunner` currently iterates `InboundEmailHandler` accounts through the `MailboxSearcher` interface. The delta: introduce a small `MailSource` union (IMAP handler | m365 connection) resolved per user, with a `GraphMailboxSearcher` implementing the same search contract (query, from, since, limit) via `$search`, plus a per-source body fetch for the top hit(s). Keep the same caps (10 merged hits, per-source timeout) and the same dynamic-availability note so the planner only sees `email_search` when at least one source exists.

### 2.4 Planner channels and naming

- `PlannerChannelCatalog` already maps `m365 → KIND_MAIL`. Delta: an m365 connection whose scopes include the calendar tier **also** yields a `KIND_CALENDAR` channel; capability mapping extends `calendar_event` delivery to it.
- **Naming decision D2:** users say "Outlook", the slug today is `m365`. Recommendation: expose the calendar channel key as `outlook` (what a human says in chat), keep `m365` accepted as an alias in channel resolution. UI copy: "Outlook calendar". Whatever is decided, the key appears in `[CHANNELLIST]` and the Connections pill so the user can see the exact word to use — same rule as `nextcloud`.

### 2.5 Document TOC (M0)

`DocumentGeneratorService` converts markdown headings to styled headings but writes no TOC field. Add a real DOCX TOC (PhpWord `TOC` element + heading title styles) when the request asks for one (planner param `toc: true`, defaulted by phrasing like "with a table of contents"). Acceptance: the generated file opens in Word/LibreOffice with a working, updatable TOC — asserted by unpacking the OOXML in a unit test, not by eyeballing.

---

## 3. UX / UI contract (binding, per 08_ux_and_i18n.md)

1. **One confirmation pattern for every external write.** The Outlook event write uses the *same* confirm card as CalDAV delivery and future Jira/MCP writes: what will be created, where (account label), when — Confirm / Cancel. First interactive run always confirms; "don't ask again for this task" writes the task's `allow_unattended` flag, never a global setting.
2. **The reply proves it happened.** After a Graph write the reply carries the event's `webLink` ("Open in Outlook") — not just "done". After a folder delivery it names the connection and path. A claim without a link/location is a review reject.
3. **Upgrade-access is a first-class state.** A connection that lacks the needed scope shows one sentence ("Synaplan can read your mail but may not write to your calendar yet") and one button ("Allow calendar access") — reusing the `reauth_required` visual language, not a new pattern.
4. **Channel words are visible.** The Connections page shows each connection's channel pill (`outlook`, `nextcloud`, `opencloud`, `mailbox`); chat copy in docs uses exactly those words. No user should have to guess the magic word.
5. **Failure vocabulary only** — `unauthorized`, `not_found`, `quota_exceeded`, `too_large`, `unreachable`, `conflict`, `unsupported`, plus the existing reauth flow. A new provider adds **zero** new translation keys.
6. **Four locales in the same PR** (en/de/es/tr), locale-parity CI already enforces it. WCAG AA in both themes and V2 for every new surface (confirm card, upgrade button, channel pills).
7. **Honest copy until shipped:** the words "Outlook", "OneDrive", "openCloud" appear in UI copy only once the respective step is merged; before that the docs say "calendar file (.ics)".

---

## 4. Work breakdown (PR-sized, lowest first)

Follows the step-size rules of [`09_work_breakdown.md` §1](./09_work_breakdown.md#1-step-size-rules). Mapping to the old K ids is noted so the connector gate rows stay traceable.

| # | Step | Maps to | Size | Depends on | Acceptance (3 bullets max) |
| - | ---- | ------- | ---- | ---------- | -------------------------- |
| **M1** | Lock U2 + current U1/U3/U4 fallback behavior in characterization + E2E | — | S | — | The four utterances have recorded plans; `.ics`+`email_me` path green; snapshots reviewed |
| **M0** | DOCX table of contents in `DocumentGeneratorService` | K2 | S | — | `toc` param renders a real TOC field; OOXML unit-asserted; planner prompt mentions the param |
| **M2** | Scope tiers + incremental consent + "Upgrade access" UX | F3 ext. | M | — | Default install unchanged; enabling the calendar tier re-consents cleanly; missing scope → readable state, never a Graph 403 in the user's face |
| **M3a** | `GraphMailboxSearcher` (search, newest-first, caps, 429 discipline) | K3c | M | — | Fake-Graph unit tests; same result shape as IMAP searcher |
| **M3b** | Graph message body fetch for summarize | K3c | S | M3a | Top-hit body retrieved on demand; bodies never bulk-fetched |
| **M3c** | `EmailSearchRunner` merges IMAP + M365 sources | K3c | M | M3a | U3 works with either or both sources; availability note lists both; per-source failure degrades that source only |
| **M3d** | Seed `MULTITASK.EMAIL_SEARCH_ENABLED = 1` (insert-if-missing) | — | S | M3c | New installs get mail search on; explicit operator `0` survives deploys; docs updated |
| **M4** | Graph calendar **read** + shared dedup (`Calendars.Read`) | K4a | M | M2 | Time-window query finds our UID; duplicate check shares one implementation with CalDAV (two backends, one contract) |
| **M5** | Graph calendar **write** (mutating, confirm + `allow_unattended` + audit) | K4b | M | M4, S6 ✅ | U1 creates a real event with `webLink` in the reply; re-run creates nothing; unattended path tested without session |
| **M6** | Planner channel: m365-with-calendar-tier → `outlook` calendar channel | — | S | M4 | "into my Outlook" plans `calendar_event` + delivery to that channel; D2 naming decided in this PR |
| **M7** | U4 "into Outlook" = `email_me` mapping (per D1 option 1) + copy | — | S | M1 | Utterance delivers the DOCX to the inbox; copy says "sent to your inbox", not "saved to Outlook" |
| **M8** | OpenCloud write **spike**, then adapter on the F4 seam | K11/C11 | M | S5 owner | Spike result recorded in 07 §4.3; chosen mechanism ships as one more `DestinationProvider`; if token-exchange wins, the S11 security approval happens **before** code |
| **M9** | OneDrive file drop (`Files.ReadWrite`, channel `onedrive`) | K5a/C5 | M | M2, M5 | Optional in this phase; own channel word; US-cloud label (S7) |

**Merge order:** M1 → M0 → M2 → (M3a–M3d) → M4 → M5 → M6 → M7 → M8 → M9. M1 first is non-negotiable — it is the safety net under every planner change in this phase.

---

## 5. Testing

Per [`06_testing_and_documentation.md`](./06_testing_and_documentation.md) and the connector rules ([`07_connectors.md` §8](./07_connectors.md#8-testing-rules-for-connectors-all-of-them)). Phase-specific additions:

1. **Utterance suite.** U1–U4 verbatim as characterization inputs (planner snapshots) and as Playwright E2E flows against the dev stack with a fake Graph. Any wording-sensitive regression shows up as a snapshot diff.
2. **No live network in CI.** `GraphClient` additions tested against the existing fake/MockHttpClient pattern (incl. 429 + `Retry-After`, 401-refresh-once, revoked-consent → `reauth_required`).
3. **Idempotency proof per backend.** For CalDAV (exists) and Graph (new): create → re-run → assert exactly one event; the test runs in the cron shape (no session, no security context).
4. **Scope-gap path.** A connection with only the mail tier asked to write a calendar entry must produce the upgrade-access state — asserted in unit + E2E, all four locales present.
5. **Live manual matrix (evidence pasted into the PR body, S5 owners required):** M365 work tenant with admin consent, M365 personal account, IMAP-only user, user with both mail sources (U3 merge), Nextcloud, OpenCloud (post-spike). Negative: consent revoked mid-schedule, secret expired, calendar deleted.
6. **The unfiltered gate** before every commit — never a `--filter` run as the final check. Snapshots re-recorded and reviewed line by line when the planner prompt changes (M6 will change it).

---

## 6. Documentation deliverables

Two audiences, two repos — both updated **in the same PR as the behavior**, per the definition of done.

**In `synaplan/docs/` (operator/developer):**

| Doc | Change |
| --- | ------ |
| `docs/CONNECTIONS.md` | M365 section grows: scope tiers, upgrade access, calendar write semantics + dedup, what leaves the server; OpenCloud section after M8 |
| `docs/CONFIGURATION.md` | New `M365 / SCOPE_*` keys; `EMAIL_SEARCH_ENABLED` seeded default change |
| `docs/MULTITASK_DATA_NODES.md` | `email_search` gains the M365 source; first documented mutating exception (Graph calendar write) with its confirmation contract |

**In `synaplan-docs/` (public site, `docs/*.md`):**

| Page | Change |
| ---- | ------ |
| `docs/connections.md` (**new**) | User-facing: what you can connect (Microsoft 365, Nextcloud, openCloud, IMAP), what each unlocks in chat, the exact channel words, screenshots of consent + upgrade access |
| `docs/dag-routing.md` | Worked examples updated with U1 and U3 (real utterances, real task cards) once shipped — never before |
| `docs/faq.md` | "Why does Synaplan ask again for calendar access?" (incremental consent), "Is my mail stored?" (no — live search, read-only) |

Public-site copy follows the same honesty rule: a capability appears on the site in the release where it ships.

---

## 7. Open decisions in this phase

| # | Decision | Proposed default | Decide by |
| - | -------- | ---------------- | --------- |
| D1 | "Put a document into Outlook" = mail-to-inbox (now) vs OneDrive (M9) | Mail-to-inbox now, OneDrive later | M7 review |
| D2 | Calendar channel word: `outlook` (recommended) vs `m365` | `outlook`, alias `m365` | M6 PR |
| D3 | Does the calendar scope tier default **on** for new installs once M5 is stable? | Off until one release of field feedback | Post-M5 review |
| S5 | Named owners for live M365 tenant, Nextcloud, OpenCloud test accounts | **Still unowned — blocks M5/M8 live verification** | Before M4 starts |
