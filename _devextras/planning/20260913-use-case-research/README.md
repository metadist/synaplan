# Ten realistic Synaplan use cases — the base for the next development plan

**Status:** Research 2026-09-13, verified against `main` at 4.8
(#1827 turned the wave flags on by default; #1821 shipped the Steps editor
and webhook trigger; Office Phase T/A/B on `main` via
[#1685](https://github.com/metadist/synaplan/pull/1685); IAM-UX Share
dialog on `main` via
[#1726](https://github.com/metadist/synaplan/pull/1726)). Track STATUS
ledgers for those two were still labelled pre-merge and are corrected in
this change.
**Purpose:** decide whether Synaplan is the right solution for a job, and
turn that answer into work. Every case is written so a non-technical reader
can recognise their own situation.
**Companion:** [`../202609_ux_user_flows.md`](../202609_ux_user_flows.md)
(U1–U12, binding) and [`../20260910_roadmap_update.md`](../20260910_roadmap_update.md)
(live wave plan). This file does not replace either; it feeds them.

---

## 0. How to read this

Five cases are **chat-led** (a person talks to Synaplan), five are
**automation-led** (Synaplan acts on a trigger and a person decides). Each
case has:

| Field | Meaning |
| ----- | ------- |
| **Fit today** | **Strong** = the building blocks are on `main`; **Conditional** = one named primitive or connector is missing; **Partner** = valuable, but the execution system is the customer's, not ours |
| **Acceptance utterance** | The sentence a user types or the trigger that fires. It becomes a characterization / E2E scenario, exactly as Phase M did with its four utterances. A case without an executable utterance is prose, not scope |
| **Perfect UX** | What the person sees when it works — one paragraph. This is the merge gate, not a wish |
| **Gap** | The one or two things that stop the utterance from passing today |

**Tiering (§4) is by distance from `main`, not by market size.**

---

## 1. Perfect UX — the notion that binds every case

Synaplan is good only if a normal person finishes the job **without a docs
tab, without a stack trace, and without wondering where the result went**.
Usability and stability are not a polish pass after the feature; they are
the feature. Concretely, every case below is done only when:

1. **First run without help.** A new user reaches the result of the
   acceptance utterance from an empty install in under ten minutes, guided
   only by the screen (empty states are next actions, U5).
2. **Ten-second findability.** Whatever the run produced — file, mail,
   calendar entry, pending approval, run history — the person finds it in
   ten seconds from where they normally work (U2, U6).
3. **Five questions on the open surface.** Who owns this, who else sees it,
   what will it touch, how do I stop it, where did it come from (U7).
4. **Honest outcome copy.** Every run ends in a terminal state with one
   sentence a user understands; when a write was involved the copy says
   what did *and did not* happen (U8; roadmap §7.2 "recoverable jobs").
5. **Undo is a click.** Turn the trigger off, revoke the share, reject the
   approval — from the same row, without a docs lookup.
6. **Stability is part of UX.** No stuck "running", no silent skip, no
   3 a.m. mail in Markdown. A flaky feature is a broken feature; the full
   pre-commit gate plus the named journey walk (U10) is the proof.
7. **Both themes, V2, 320 px, five locales** — always (U9, i18n parity).

Anything that fails one of these seven is not shipped, even if the API is
green. This is the same lesson sharing taught (#1717) applied to every
case here.

---

## 2. The ten cases

### Case 1 — Translate every new document in a cloud folder

**Interface:** automation · **Fit today:** Conditional
**Situation:** An international company drops contracts and manuals into a
Nextcloud / ownCloud / WebDAV folder and wants each new file translated and
filed next to the original, with people reviewing only low-confidence
results.

**Acceptance utterance (trigger):** *A new `.docx` lands in
`/Shared/Incoming-DE`. Within the next scheduled run, `/Shared/Incoming-EN`
holds `<name>.en.docx` and the owner's Automations row reads "3 translated ·
1 needs review".*

**Perfect UX:** One Saved Task card: *Watch folder* → *Translate to
English* → *Save next to original*. The card shows the last run, the
count, and a "needs review" chip that opens the files. Nothing to configure
beyond picking two folders and a language.

**Gap:** There is **no file-created trigger anywhere**; Nextcloud
integration is pull-only from the user's side. Layout is not preserved —
`officemaker` regenerates from Markdown. Honest v1 = *scheduled folder
scan* (URL-watch pattern: seen-state per file) + translated text as a new
DOCX. OpenCloud write still needs the M8 spike.

### Case 2 — Monitor markets and approve a proposed trade

**Interface:** automation · **Fit today:** Conditional (internal probe, not
a marketing case)
**Situation:** An investor tracks a watchlist plus trusted news and wants a
*proposed* order when a rule fires — never an unattended execution.

**Acceptance utterance (trigger):** *Schedule every 15 min: price via a
custom HTTP tool, news via web search. When rule X holds, a card appears in
Approvals: "Buy 10 × ACME at limit 42.10 — fees 1.20 — account Demo.
Nothing has been sent." Approve → the broker tool runs once; the run shows
the order id.*

**Perfect UX:** The approval card *is* the product: quantity, limit, fees,
account, evidence links, expiry. Reject explains that nothing was sent.
The same card reachable from the chat, the inbox and the mail.

**Gap:** Primitives are on `main` (Saved Tasks, custom tools, durable
approvals, web search). Missing: idempotent external writes with a run
ledger, per-run spend / tool-call limits (roadmap §7.2), broker sandbox.
Synaplan is **not** for latency-critical or unattended trading; say so in
the docs.

### Case 3 — Watch regulations and report only material changes

**Interface:** automation · **Fit today:** Strong
**Situation:** A compliance team tracks regulator pages and circulars in
several countries and must not reread unchanged text.

**Acceptance utterance (trigger):** *Weekly, Monday 07:00: five watched
URLs. Two changed. The owner receives one HTML mail "2 of 5 sources
changed" with cited diffs and impact per department; the briefing is filed
in the Compliance knowledge folder.*

**Perfect UX:** Add a URL, pick a schedule, done. The Automations row says
"Last: Mon 07:02 · 2 changed" and opens the diff. The mail is readable on a
phone.

**Gap:** Follow linked PDFs from a watched page and diff them; a visible
version history per URL. Everything else (URL watch, RAG, schedule,
`email_me`, WebDAV delivery, HTML mail since #1787) is shipped. **First
showcase candidate.**

### Case 4 — Multilingual municipal service desk

**Interface:** chat · **Fit today:** Strong (connector is the customer's)
**Situation:** A city wants one assistant on its website and by mail that
answers from approved statutes and starts a service request — without
exposing case data.

**Acceptance utterance:** *Visitor, in Turkish: "Taşınmayı nasıl
bildiririm?" → sourced answer + "Start the request" → a Synaform collects
the fields → card "Send to the citizen office? Nothing has been sent" →
Approve → custom tool creates the case; the visitor gets the reference
number.*

**Perfect UX:** The visitor never sees Synaplan vocabulary. The assistant
is published to the *Bürgerbüro* group, the widget picks it by name, and a
human can take over live. Admins see usage, never the conversation.

**Gap:** Channel-consistent identity across widget and mail, accessibility
audit (BITV/WCAG) of the widget, retention rules, the municipal specialist
connector (custom HTTP tool — customer side). WhatsApp is legally contested
for German public bodies; lead with widget + mail.

### Case 5 — Cited tender-response workspace

**Interface:** chat · **Fit today:** Strong (editing is Conditional)
**Situation:** A bid team must understand a 300-page tender, split the
work, and answer from controlled company knowledge.

**Acceptance utterance:** *"Read these tender documents and give me a
compliance matrix as Excel: requirement, source page, our answer, owner,
status." → XLSX with one row per requirement and a citation per row.*
Second utterance: *"Draft section 4 in Word from our reference projects."*

**Perfect UX:** Upload, ask, download — the matrix opens in the inline
preview with a thumbnail. The tender assistant and its knowledge folder are
shared with the bid group and appear under *Shared with me* in ten seconds.

**Gap:** Table extraction quality on scanned PDFs (Docling chain, shipped,
needs a tender corpus in the eval set); requirement-to-source traceability
in the generated file; review states. Structured DOCX editing is on `main`
(Phase B) but new — the second utterance needs a real walk.

### Case 6 — Supplier invoices with exception-only review

**Interface:** automation · **Fit today:** Conditional
**Situation:** Finance receives invoices by mail or folder; routine ones
should be prepared automatically, exceptions reviewed by a person.

**Acceptance utterance (trigger):** *Mail from `@supplier.com` with a PDF
arrives → fields extracted → PO match via custom tool → card "Book 1 240,00
EUR to PO 4711? Bank details unchanged" → Approve → record created;
mismatch or new IBAN → "Needs review" with the reason.*

**Perfect UX:** One Automations row, one inbox. The card shows the extracted
fields next to the PDF thumbnail; the reason for an exception is one
sentence ("IBAN differs from last invoice").

**Gap:** Mail trigger with sender filter, extraction, Synaform / FastBill,
custom tools and approvals are on `main`. Missing: deterministic validation
(duplicate, IBAN change, tax rules), folder intake (Case 1 primitive), and
an ERP tool template. Mature AP products exist — Synaplan wins only when
combined with the knowledge and chat side.

### Case 7 — Executive daily briefing from the mailbox

**Interface:** chat · **Fit today:** Strong
**Situation:** An executive wants a morning briefing and a way to act on it
without giving the AI free rein over the mailbox.

**Acceptance utterance (trigger + chat):** *Weekdays 07:00: "Decisions /
Risks / Follow-ups" mail from the connected M365 mailbox. In chat: "Reply
to Meier that Thursday works and put it in my calendar" → approval card →
Approve → the reply is in Sent, the event has a `webLink`, both linked from
the answer.*

**Perfect UX:** The briefing mail is HTML on a phone. Approving in the chat
**continues the conversation** with the result; approving later from the
inbox produces the same follow-up message in the chat.

**Gap:** Mail search, calendar / mail write, schedule, approvals are
shipped. **Approve does not yet continue the turn** (Tools S2 deferral —
see §3). No reminders (`VALARM` / Graph `isReminderOn`). Prompt-injection
hardening for mail bodies is unaddressed.

### Case 8 — Insurance claims triage before a human decision

**Interface:** automation · **Fit today:** Partner
**Situation:** An insurer receives forms, photos, reports and invoices and
wants a complete, prioritised case file — not an automated coverage
decision.

**Acceptance utterance (trigger):** *Claim mail with 6 attachments →
extracted parties, dates, damage, amounts, missing evidence → compared with
policy wording → cited summary + priority → "Create claim in system?" card
→ Approve → case created.*

**Perfect UX:** Same inbox, same card, same honest copy as Case 6. The
summary cites the page of the policy it relied on.

**Gap:** Structurally Case 6 with multimodal input. Regulated (EU AI Act
Annex III territory); explainability, sensitive-data controls and a claims
connector are the customer's release gates. Keep as a partner vertical;
do not build claim logic in core.

### Case 9 — Field-service report from local evidence

**Interface:** chat · **Fit today:** Conditional (Desktop beta)
**Situation:** A technician has photos, voice notes and measurements on a
laptop and must hand in a standard report before leaving the site.

**Acceptance utterance:** *In Synaplan Desktop, job folder allowed: "Make
the service report from today's photos and my voice note, use the standard
template" → report skill runs locally → DOCX in the out-box → "Upload to
the project folder" → file appears in Nextcloud.*

**Perfect UX:** Pair once with a code; the allowed folder is shown as a
native path; the skill asks before it writes; the result opens with one
click. Works offline for transcription and vision when a local model is
configured.

**Gap:** Desktop skills runtime and signed installers (Phase B2–B6), no
mobile → desktop handoff, customer report templates as skills. The most
differentiated story in the list, and the furthest out.

### Case 10 — Sovereign expert-research assistant

**Interface:** chat · **Fit today:** Strong
**Situation:** A law firm, hospital or engineering office needs a research
assistant whose sources, models, permissions and hosting it controls.

**Acceptance utterance:** *"Which of our past opinions dealt with §X and
what did we conclude? Cite them." → answer with retrievable passages; a
"Local only" assistant never falls back to a cloud model; the admin sees
usage metadata, not the chats.*

**Perfect UX:** The role-specific assistant is under *Assistants → Shared
with me*; the answer shows where processing happened; when evidence is
thin the assistant says so instead of guessing.

**Gap:** Domain eval set, citation fidelity, abstention copy, and
**sovereignty as a job-wide setting** (roadmap §7.2 — today a local
assistant can still fall back to the user's default cloud model). This is
the DE/CH positioning; it deserves the first eval report.

---

## 3. What the ten cases have in common

Ten cases collapse into four primitives. Three are on `main` since 4.8;
one is missing.

| Primitive | Cases | State on `main` (4.8) |
| --------- | ----- | --------------------- |
| Durable approvals (card + inbox + pause/resume) | 2, 4, 6, 7, 8 | Shipped, **seeded on**. Resume binds approval to tool + arguments (#1821). Open: approve does not continue the chat turn (Q1) |
| Custom HTTP / OpenAPI tools | 2, 4, 6, 8 | Shipped, seeded on. Open: tool templates (ERP, helpdesk, broker sandbox) |
| Event triggers beyond mail | 1, 6, 8 | Mail filter + webhook shipped. **Folder events do not exist** |
| Provenance on results (source, version, model, who approved) | 3, 5, 8, 10 | Audit log + citations exist; not one visible concept on the result |

**Decision proposed:** add a **folder watch** companion (same shape as URL
watch — scheduled scan, seen-state per file, fires a Saved Task per new
file, `webdav` / Nextcloud / Dropbox connections). No event bus, no
Nextcloud Flow dependency. Unlocks Case 1 and the intake half of Case 6.

---

## 4. Tiers — by distance from `main`

| Tier | Cases | What to do | Owner surface |
| ---- | ----- | ---------- | ------------- |
| **A — showcase now** | 3, 7, 10, 5 (matrix only) | Write the acceptance utterance as a test, walk the Perfect-UX paragraph in the browser, fix what the walk finds, record a demo, publish a docs walkthrough | Automations, Chat, Assistants |
| **B — pack it** | 4, 6, 2 | Primitives are live. Ship each as an **assistant pack / workflow template** (bundle v1) with a sample tool definition and the approval copy already written; the customer plugs in their connector | Assistants, Connections → Custom tools |
| **C — one primitive away** | 1 | Folder watch companion (§3) then the same pack treatment | Automations |
| **D — partner / later** | 8, 9 | 8: vertical with a partner, core stays generic. 9: Desktop Phase B2–B6, then revisit | Desktop, partner repos |

Do not build vertical connectors (ERP, claims, broker, municipal
Fachverfahren) in core. They enter through custom tools or MCP.

---

## 5. Already planned, small, and releasable now

Items that are decided, scoped, mostly built — and that these use cases
make urgent. Each is one PR unless noted.

| # | Item | Why now | Where it is planned |
| - | ---- | ------- | ------------------- |
| Q1 | **Approve continues the chat turn.** Today `onChatApprovalApproved` shows a toast; the tool runs, the conversation stays silent (`ChatView.vue`). J-TL-1 says "the answer continues in the same chat". Approvals are seeded **on** since 4.8, so this is visible in every install | Cases 2, 4, 6, 7, 8 | Tools STATUS 2026-09-10 review: "deferred to Wave 5" |
| Q2 | **Reminders**: `VALARM` in generated `.ics`, `isReminderOn` on Graph events | Case 7 | Channels planning §4.1 — "not on any roadmap yet" |
| Q3 | **Honest copy for "into Outlook" documents** ("sent to your inbox") | Case 7 | Saved-tasks Phase M step M7 |
| Q4 | **Publish ≠ activated** — after Publish show what actually came up ("website active · Monday schedule needs attention") with a targeted retry | Cases 4, 10 | Roadmap §7.2; Agent Builder STATUS |
| Q5 | **"For you" landing** — "Two reports finished. One action needs approval. One connection needs reconnecting." Persistence exists; this is a card over three existing lists | All automation cases | Roadmap §7.3 |
| Q6 | **AB14 "Help me write"** in the assistant builder (reuse the widget AI Setup Assistant) | Cases 4, 5, 10 | Agent Builder S2 deferral |
| Q7 | **Approval bound to arguments + tool version; permission re-check before execute** | Cases 2, 6, 8 | **Partial** in #1821 (tool + arguments + permission re-check). Binding the grant to a tool *version* is follow-up |
| Q8 | **Sovereignty as a job-wide setting** ("Local only" never falls back to cloud; show where processing happened) | Case 10 | Roadmap §7.2 |
| Q9 | **PL37** `model_preferences` bundle section | Tier B packs | AI Plugs leftover |
| Q10 | **Unmerged small UX branches to rebase or close:** `feat/file-preview-media-aware` (1 commit, 2026-09-01), `fix/files-list-searchable-status-ux` (1, 08-31), `fix/1499-consistent-file-previews` (2, 08-30), `feat/chat-history-panel` (4, 09-02), `feat/first-run-setup-wizard` (5, 08-26 — first-run matters for §1 rule 1) | Cases 3, 5, 10 | Branch list on `origin` |
| Q11 | **Feature-modules S4** — flip module gates so unconfigured features disappear instead of showing dead cards | §1 rule 6 | **Done** (#1843, #1856): all 12 gates on for new installs |

Q1 and Q4 are the two that most change how the product *feels*; do them
first. Q10 is hygiene: a branch older than two weeks either ships this
week or is closed with a note.

---

## 6. What this list is not

- Not a marketing page. Case 2 stays internal; Cases 6 and 8 merge into
  one public story ("documents in, checked record out, human approves").
- Not a connector backlog. Every system of record is the customer's tool.
- Not a substitute for the wave plan. Tier A feeds docs and eval now; Tier
  B and Q1–Q11 are proposals for the Wave 5 ticks in the owning track
  `STATUS.md`; Tier C is the one new primitive this research asks for.

## 7. Next steps

1. Product owner ticks the tiers and the folder-watch decision (§3, §4).
2. **Coding order** follows the live roadmap §1: finish Tools S5
   (TL41, TL45–TL47, J-TL-5), then Compute A3 + B1. Q1 (approve
   continues the chat turn) is a Tools Wave 5 row, not a substitute
   for closing S5.
3. Q4 goes on Agent Builder STATUS as a Wave 5 row before it is coded.
4. Tier A: one branch per case — utterance test + browser walk + docs page.
5. The Perfect-UX rules in §1 are lifted into `AGENTS.md` (done on this
   branch) so every PR, not only the ones from this list, is measured
   against them.
