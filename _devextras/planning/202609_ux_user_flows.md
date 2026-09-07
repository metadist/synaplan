# UX user-flows — 2026-09 roadmap

**Status:** Binding from 2026-09-07. Copy and journeys are reviewed **before**
the Vue is built, not after. This is the UX contract for every remaining
sprint in [`20260903_roadmap.md`](./20260903_roadmap.md).
**Lesson:** IAM sharing (S2/S3) shipped as APIs plus a dialog. Recipients
could not find a chat shared with their group. A follow-up
([#1717](https://github.com/metadist/synaplan/pull/1717)) had to invent the
real user-flow after the fact. That must not happen again.
**Owner:** product owner + the track that owns the surface. A UI PR that
cannot walk its named journey is not done.

Companion wireframes: [`202609_ux_user_flows/`](./202609_ux_user_flows/).

---

## 0. Verdict on the plans as they stood

The six master plans are strong on architecture, flags, schema and
vocabulary. They are **not** yet strong enough on *how a non-technical
person does the job*.

What they specified well:

- Three-sentence concepts and banned jargon.
- Lean nav (Work / Manage / Operate) and "no new rail item without a
  decision".
- A five-question check on IAM screens.
- Screen *lists* (Share dialog, gallery, Approvals inbox, AI
  infrastructure tabs, run card, Connect card).

What they did not specify — and sharing proved it:

- The **whole journey**: who starts, where the other person finds it, what
  they understand, how they undo it.
- **Findability.** "Add a filter chip on the existing list" is not a
  flow. If the everyday list does not surface the new thing, the plan
  must name the inbox, badge or empty-state path *before* the first UI
  PR.
- **Kind-specific meaning.** "Can use" on a chat is not "Can use" on an
  assistant or a tool. Primary copy must say what happens *here*.
- **Familiar interaction.** The shipped Share dialog is a stacked form
  (search → radio list → Share → list below). Professional share UIs
  (Drive, Notion, Slack) add a person and a permission in **one row**
  and explain the consequence in one sentence.
- **A merge gate.** The five-question check existed on paper and was
  not applied until the incoming-chats follow-up.

This document closes that gap for **coming** features. Shipped sharing
stays as-is in code; the Share dialog is professionalized in a planned
follow-up (**IAM-UX**, §7) **before** Agent Builder publish, custom
tools, or workflow templates reuse it.

---

## 1. The sharing lesson (do not repeat)

| What the S2 plan said | What shipped | What a professional flow needed |
| --------------------- | ------------ | ------------------------------- |
| One `ShareDialog` on the resource | Dialog exists; search + radios + confirm | Add-person-and-permission in one row; owner named; one sentence of what the recipient can do |
| "Shared with me" **filter chip** on existing lists | Chip landed on the statistics chat browser only | History, sidebar and Files must show incoming items; if they cannot, name a sibling inbox *in the plan* |
| No new top-level nav | Lean nav kept — and incoming chats were invisible | Lean nav is right; **Account red-dot + Incoming chats** (sibling of Files inbox) is the pattern when a list cannot surface the grant |
| Five-question check | Answered only in the #1717 follow-up | Who owns it, who else, what they can do, how I stop it, where it came from — on the **open** resource, not only in the dialog |
| Permission words only | "Can view / Can use" with no kind hint | "Can use — they can continue this chat as their own copy" |
| After Share, toast | Toast, then silence | Tell the owner *where* the other person will find it; tell the recipient something arrived |

**Rule taken from this:** a share (or publish, or approval, or link) that
the other person cannot find in ten seconds without a docs tab is not
shipped, even if the API is green.

---

## 2. Product stance

Synaplan is a **user-centric, easy application**. Power lives behind a
clean UI. The interface-streamlining contract still holds
([`20260828-interface-streamlining-sprint/README.md`](./20260828-interface-streamlining-sprint/README.md)):

1. Everyday work stays tiny: **Chat, History, Sources**.
2. Creation and publishing live in **Manage**.
3. Installation-wide controls live in **Operate**, operators only.
4. Personal settings stay in the account menu.
5. People see what their responsibility needs. Advanced controls appear
   **in context**, never as a second "pro mode".

New features add power, not chrome. A new rail item is a decision row,
not a default. A new **sibling inbox** (Incoming chats, Approvals) is
allowed when the everyday list cannot answer "something arrived for
you."

---

## 3. Twelve binding rules (U1–U12)

| # | Rule | Not done if… |
| - | ---- | ------------ |
| **U1** | **Journey before Vue.** The sprint names the journey (this file, §5) and a reviewer walks the ASCII/wireframe before the first `*.vue` for that surface. Copy in all five locales is in the same PR as the first paint. | The PR invents the screen while writing it |
| **U2** | **The other person finds it in ten seconds.** Owner path *and* recipient / later-self path are specified. If the existing list cannot surface it, the plan names the inbox, badge or empty-state CTA. | "Shared with me chip on the list" with no proof the list is the one people open |
| **U3** | **Kind-specific consequence.** Every permission, status and button says what happens *on this kind*, in one sentence the average user understands. | One generic "Can use" for chat, assistant, tool and task |
| **U4** | **Familiar pattern, house skin.** Share = one-row add + live list (Drive-class). Inbox = pending / done. Gallery = cards + chips. Connect = one confirm card. Chat results = a card, not a new page. | A stacked form that looks like an admin tool |
| **U5** | **Empty state is a next action.** One sentence + one primary button. Flag off ⇒ the surface is absent (no teaser, no dead control). | Blank card, or a control that 404s |
| **U6** | **Arrival signal without a new rail.** Red-dot, badge count, or Account child. History stays a work list, not a notification dump. | Silent grant, or a fourth top-level item |
| **U7** | **Five questions on the open surface.** Who owns this? Who else? What can they do? How do I stop it? Where did it come from / what will it touch? A sixth primary control means cut scope. | Dialog-only answers that vanish when the dialog closes |
| **U8** | **Failure copy is product.** One sentence, no stack trace, no HTTP code, named recovery. State what did *not* happen when a write was involved. | "Request failed" / raw API error |
| **U9** | **House visual rules.** Tokens only (`surface-card`, `txt-primary`, `btn-primary px-4 py-2.5 rounded-lg`). Dark + V2 + 320 px checked. WCAG AA. No ancestor-scoped ink that breaks overlays. | Tailwind palette colours, raw `<button class="btn-primary">` |
| **U10** | **Walk the flow before merge.** A reviewer (or the author in the browser) performs the named journey end to end: click, type, find, undo. A screenshot of the happy path is not the gate. | "Looks fine in Storybook" / one render |
| **U11** | **Flag off hides everything.** No nav child, no badge, no empty teaser, 404 on new routes. | Greyed menu item "coming soon" |
| **U12** | **Reuse, do not fork.** One Share dialog (after IAM-UX), one Approvals inbox, one gallery-card, one connect card, one chat run/approval card. A new kind passes `kind` + consequence copy, not a new modal. | `AssistantShareModal.vue` beside `ShareDialog.vue` |

These sit next to roadmap principle 6 ("explainable to a non-technical
user") and do not replace it. **U1–U12 are the merge gate for every
`ota-candidate` step from 2026-09-07 on.**

---

## 4. Shared patterns (build once)

### 4.1 Share (professionalize, then reuse)

Target interaction — see
[`share-dialog-v2.md`](./202609_ux_user_flows/share-dialog-v2.md):

```text
Share "Q3 playbook"                         [×]
Owner · Ada

[ Search a person or group…          ] [ Can use ▾ ] [ Share ]

  Can use — they can continue this chat as their own copy.
  They will find it under Incoming chats.

Shared with
  Sales (group)     Can use ▾     Remove
  Everyone…         Can view ▾    Remove

Public link (conversations / files only)
  Anyone with the link can view.   [ Manage link ]
```

Required on every kind that opens this dialog:

| Kind | "Can view" | "Can use" | After-share sentence (owner) | Recipient finds it |
| ---- | ---------- | --------- | ---------------------------- | ------------------ |
| Conversation | Open read-only | Continue as my copy | "They will find it under Incoming chats." | Account → Incoming chats + History Group filter |
| Knowledge folder | See the folder | The AI may use these files | "It appears in their Sources as shared." | Sources → Shared with me |
| Assistant | See the card, clone | Start a chat | "They will find it under Assistants → Shared with me." | Manage → Assistants, Shared chip |
| Saved task / template | See the card | Make their own copy | "They can use it as a template under Automations." | Automations → Shared with me |
| Widget | Open read-only | — | "They can open the widget settings." | Widgets → Shared with me |
| Custom tool | — | The AI may call it (your credential stays yours) | "It appears in their tool list as shared." | Connections → Custom tools, Shared chip |

Owner name stays on the open resource (banner / pill), not only inside
the dialog (U7). Changing permission is in-list, not a second submit.

**IAM-UX (§7) lands this dialog before track 2 S3 Publish.** Until then,
new kinds must not invent a second share UI.

### 4.2 Something arrived

Pattern already proven by Incoming chats + Files inbox:

- Account (or the parent Manage child) gets a **red dot / count**.
- A **sibling page** lists only incoming items, with owner + via-group +
  what I may do on every row.
- Everyday lists (History, Sources, gallery) show a **Group / Shared**
  filter so the item is also findable in context.
- Opening the item answers U7 without opening Share.

Approvals reuse this: badge on Automations, inbox at
`/channels/approvals`, deep link back to the chat or run.

### 4.3 Decide in context, recover in the inbox

Write-class actions: a card **in the chat** (Approve / Reject / Always
allow). Closing the tab must not lose the decision — same item in the
inbox. Unattended runs use the inbox as the primary surface, with a
deep link to the run.

### 4.4 Catalog / gallery

Cards, not tables, for things people *pick*: assistants, templates,
custom tools. Chips: Mine / Shared with me / From plugins. Primary
action on the card is the verb the user came for (**Start chat**,
**Use template**, **Try it**), not **Edit**.

### 4.5 Admin infrastructure

One page, tabs, health pills, **Test** that shows a human result
("Docling read this table", "12 models will be added"). Sovereignty
badge on the adapter, not a lecture. Never expose chain keys or MIME
families as the first sentence.

### 4.6 Connect a platform

One confirm card after sign-in. Host, uid, scopes in plain words,
**Connect** / **Cancel**, "Not you? Sign out". Empty Linked-platforms
list explains *where* to start (Nextcloud settings), not Synaplan
jargon.

### 4.7 Chat-native results

Compute, document generation, media: a **card in the thread**. Status,
plain-language progress, result chips that open the existing preview.
No new page. Quota / refusal is a sentence on the card.

---

## 5. Journeys the remaining sprints must walk

Each journey is an acceptance demo. Exit criteria in the sprint file
must name it. "API green" is not enough.

### 5.1 IAM remaining (S4, S5, IAM-UX)

**J-IAM-1 — Directory group, no surprise** (S4)
Admin opens People → Groups. "Support" shows **From your login** and
"managed by your login — changes happen at the next sign-in." A member
cannot rename it. A manual extra member shows a *manual* badge. Five
questions answered on the group detail without a docs tab.

**J-IAM-2 — Audit without content** (S4)
Admin opens People → Audit, filters "Share", sees "Ada shared Q3
playbook with Sales (Can use)" and every impersonation. Clicking a row
never opens the chat or the file. Empty state: "Nothing has been
recorded yet" + one sentence of what will appear.

**J-IAM-3 — Support may only use two models** (S5)
Admin opens People → Policies, picks Support, selects two models, saves.
A Support member opens Settings → Models: only those two appear; a
locked default shows "Set by your administrator" and cannot be saved
over (plain 409 copy, not a toast of the status code). A Sales member
is unaffected.

**J-IAM-4 — Share dialog professionalized** (IAM-UX, §7)
Owner shares a chat with Sales using the one-row pattern; the
consequence sentence names Incoming chats; a Sales member finds it
under Incoming chats *and* History → Group in ten seconds; opening it
names Ada, Sales, and Continue as my copy.

### 5.2 Agent Builder (track 2)

**J-AB-1 — First assistant without docs** (S2)
Manage → Assistants (empty: one sentence + **Create assistant**).
Basics → Instructions → one model → one file → **Test** in the side
panel (incognito). **Start chat** from the gallery. Composer pill
"Talking to Contract review". No "prompt topic" on screen.

**J-AB-2 — Publish to a group** (S3, after IAM-UX)
Owner clicks **Publish**, types a changelog in plain words, confirms.
**Share** opens the professional dialog; consequence: "They will find
it under Assistants → Shared with me." A Legal member opens Assistants,
chip Shared with me, sees owner + version, **Start chat**. A Sales
member does not see the card. Owner edits the draft, Legal still runs
v1; Publish v2 → Legal's *next* message is v2, no action required.

**J-AB-3 — Clone and leave the original alone** (S3)
Member with Can view opens Details, **Clone**. Lands on their own draft.
Changing the model does not change Legal's assistant. Lineage is
metadata, not primary copy ("Based on Contract review" is enough).

**J-AB-4 — Archived is not broken** (S3)
Owner archives. Gallery hides it for members; owner's Archived chip
still finds it. Existing chats keep the last version with an
**archived** badge; **Start chat** is disabled with one sentence why.

**J-AB-5 — Widget picks an assistant** (S5)
Widget setup: **Pick an assistant** (published only) as the default
path; topic binding stays as an advanced leftover. Saving the widget
does not change the assistant. Publishing v2 updates the widget on the
next visitor message.

**J-AB-6 — Move work to another instance** (S6)
Settings → **Export & import**: one file, one sentence of what is in
it. Import on instance B shows a checklist ("needs a model: chat",
"needs a key") and creates **drafts**, never shares. Empty checklist
⇒ **Import** is the only primary button.

### 5.3 AI Plugs (track 3)

**J-PL-1 — Better reading, no broken upload** (S2)
Operate → AI infrastructure → Extraction. Admin adds Docling to the
document chain, clicks **Test with a file**, sees "Docling read this
PDF — table kept." Uploads the same file in Sources; a question about
the table is answered. Stops the sidecar: upload still succeeds, test
shows "Docling unavailable — Tika used instead."

**J-PL-2 — Switch search, next chat uses it** (S3)
Web search tab: pick SearXNG, **Test query** shows three titles. Next
chat web search uses SearXNG (no restart). Per-user override is a
clear "Use my own search" in Settings, not a hidden flag.

**J-PL-3 — Import twelve models in a minute** (S5)
Models & keys → **Import models** on an endpoint. Preview table:
name, guessed tags (editable), already-there badge. Apply adds only
new rows. Re-import: "Nothing new." Probe checkbox is off by default
and named "Check what each model can do (uses a little credit)."

### 5.4 Tools, Approval & Workflows (track 4)

**J-TL-1 — Approve in the chat** (S2)
User asks the AI to create a calendar event. A card in the thread:
what will happen, when, **Approve** / **Reject**. Approve → the
answer continues in the same chat. Reject → one sentence that nothing
was created. A `destructive` action never shows Approve; it is
refused with one understandable sentence.

**J-TL-2 — Closed the tab** (S2)
Same write, user closes the browser. Account / Automations badge
shows 1. Automations → Approvals → Pending has the preview and
expiry. Approve there; the chat gains the follow-up message. Empty
inbox: "Nothing needs your approval" + one sentence of when a card
would appear.

**J-TL-3 — Monday morning, the task waited** (S3)
Scheduled task hits a write overnight. Owner opens Approvals (or the
email), sees "Create ticket in Helpdesk — from Weekly digest."
Approve. Run history shows the step **Waiting** then **Done**. Expiry:
the step failed with "Nobody approved in time"; the task does not
silently skip.

**J-TL-4 — Wire a helpdesk without code** (S4)
Connections → Custom tools (empty: one sentence + **Add a tool** or
**Import from a description**). Form in plain words: Reads data /
Changes something / Deletes something. **Try it** on a read tool
shows the result; on a write tool shows the request and does not
send. **Share** uses IAM-UX; consequence: "Support's assistants can
call this. Your login stays yours." A Support member sees the tool
as shared, cannot see the credential.

**J-TL-5 — Five steps, no canvas** (S5)
Saved Task card stays the default. **Steps** opens a numbered list:
pick a step, fill inputs or "from step 2", optional "Ask me before
this step." Save. Plain-language summary on the card still answers
the five Saved-Task questions
([`20260816-saved-task-workflows/08_ux_and_i18n.md`](./20260816-saved-task-workflows/08_ux_and_i18n.md)).
**Save as template** is a share, not a second object. Webhook URL
sits behind "Let another system start this" with a reveal + copy,
never as the first control.

### 5.5 Secure Compute (track 5)

**J-CP-1 — Chart from a CSV** (B1)
User attaches a CSV, asks for a bar chart. A card in the chat:
"Working with your file…" → result chip (PNG) → preview in the
existing file viewer. Logs stay collapsed ("Details"). Quota
exceeded: "You have used this week's file-work limit" on the card,
not a 500. Flag off: the planner never offers it; no teaser.

**J-CP-2 — Open what it left behind** (B3)
After a run, **Open workspace** reuses Files. Empty workspace: "Files
the AI creates for you will show up here." Egress off is the default
and is not a control on the card.

### 5.6 More Nextcloud (track 6)

**J-NC-1 — Connect my existing account** (S1+S2)
Nextcloud Settings → Synaplan → **Connect Synaplan**. Browser opens
Synaplan, user signs in, one card: "Connect Nextcloud at
files.example.org as jdoe? This lets Nextcloud use your Synaplan
account for chat, files and knowledge." **Connect**. Back in
Nextcloud: "Connected as Ada." Next Files action runs as Ada. No key
is typed or shown.

**J-NC-2 — Email already taken** (S2)
`link` mode, email exists: "An account with this email exists —
connect it" (not a hard fail). `provision`-only instances keep the
hard fail (C2).

**J-NC-3 — Disconnect from either side** (S1+S2)
Synaplan → Linked platforms → **Disconnect** (confirm). Next Nextcloud
action shows Connect again. Disconnect in Nextcloud revokes the key;
the Synaplan list loses the row. Empty list explains to start in
Nextcloud, not in this page.

---

## 6. Sprint-file contract

Every remaining sprint that has an `ota-candidate` step adds a
**User-flow** block under the Goal (pointer + named journeys) and
extends **Exit criteria** with:

1. Named journey(s) from §5 walked in the browser (U10).
2. Recipient / later-self findability in ten seconds (U2).
3. Kind-specific consequence copy in all five locales (U3).
4. Empty + error + flag-off states (U5, U8, U11).
5. Dark + V2 + 320 px (U9).

A UI step's first deliverable is the journey walkthrough (this file +
a wireframe if the surface is new), not the Vue file. The same
discipline Saved Tasks already used: *copy is reviewed before the
component is built*.

Backend-only sprints (registry refactors, migrations) are exempt until
they grow a UI step.

---

## 7. IAM-UX — Share dialog professionalization (planned follow-up)

**Not a new product feature.** The sharing *capability* is on `main`.
This is the missing professional flow, required before any later track
reuses `ShareDialog.vue`. Sprint file:
[`202609_iam/06_sprint_ux_share_dialog.md`](./202609_iam/06_sprint_ux_share_dialog.md).

| Step | Content | Class |
| ---- | ------- | ----- |
| `IAM-UX1` | One-row add (subject + permission + Share); in-list permission change; owner line; kind-specific consequence + "they will find it…" (table in §4.1) | ota-candidate |
| `IAM-UX2` | Open-resource U7: conversation banner, folder/assistant/task/widget pills already started in #1717 — extend the same words to every kind S3 added | ota-candidate |
| `IAM-UX3` | After-share toast names the recipient path; optional "Copy link to Incoming chats" is **not** required; do not add email in v1 | ota-candidate |
| `IAM-UX4` | Component tests for the one-row pattern, kind copy, empty list, flag-off; dark + V2 + 320 px walked | ota-candidate |

Depends on: S2/S3 on `main`. Unlocks: track 2 S3 Publish, track 4 S4
tool share, track 4 S5 templates. Cut anything else before cutting
this — a confusing share UI on assistants is worse than no publish.

Wireframe: [`share-dialog-v2.md`](./202609_ux_user_flows/share-dialog-v2.md).

---

## 8. Success criteria for this contract

1. A reviewer can walk J-AB-2, J-TL-1+2, J-NC-1 and J-CP-1 from the
   wireframes without asking "and then where does the other person
   go?"
2. IAM-UX lands before the first PR that opens `ShareDialog` for
   `assistant`, `tool` or `saved_task` as a *new* entry point (S3
   already opened those; new *publish* / *template* entry points wait).
3. Every remaining UI sprint file names its journeys and adds the
   five exit bullets in §6.
4. No remaining track adds a top-level rail item. Sibling inboxes
   follow Incoming chats / Approvals only.
5. Primary copy in all five locales stays free of the banned words
   already listed in each master plan, plus: ACL, tenant, DAG, node,
   sandbox, container, auth code, provisioning, stdout.

---

## 9. Decision (2026-09-07)

Product owner: the 2026-09 roadmap is user-centric. Sharing proved
that listing a dialog is not planning a flow. This file is now
**binding** for remaining work. Code is not changed in the same
change as this plan.
