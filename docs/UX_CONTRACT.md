# UX contract — rules U1–U12

**Status:** Binding for every user-visible change since 2026-09-07; this is
the merge gate behind the AGENTS.md "Perfect UX & Stability" bar. Moved
here from `_devextras/planning/20260907_ux_user_flows.md` on 2026-10-15.
**Owner:** product owner, plus the track that owns the surface. A UI PR
that cannot walk its named journey is not done.

Section numbers follow the 2026-09-07 contract, so a plan that cites
"UX contract §6" or "§4.7" still means the same text. The parts that were
planning rather than rules — the verdict on the 2026-09 plans, the
journeys of the 2026-09 tracks, the IAM-UX sprint and the wireframes —
are frozen in
[`_devextras/planning/2026-archive/20260907_ux_user_flows/`](../_devextras/planning/2026-archive/20260907_ux_user_flows/20260907_ux_user_flows.md)
(§7 below lists them).

---

## 1. Why this contract exists

IAM sharing shipped as APIs plus a dialog. Recipients could not find a
chat shared with their group, and a follow-up
([#1717](https://github.com/metadist/synaplan/pull/1717)) had to invent
the real user flow after the fact. Listing a screen is not planning a
flow. What was missing, and what this contract demands of every plan:

- **The whole journey:** who starts, where the other person finds it, what
  they understand, how they undo it.
- **Findability.** "Add a filter chip on the existing list" is not a flow.
  If the everyday list does not surface the new thing, the plan names the
  inbox, badge or empty-state path *before* the first UI PR.
- **Kind-specific meaning.** "Can use" on a chat is not "Can use" on an
  assistant or a tool. Primary copy says what happens *here*.
- **Familiar interaction.** Professional share UIs (Drive, Notion, Slack)
  add a person and a permission in **one row** and explain the
  consequence in one sentence.
- **A merge gate** that is applied, not only written down.

**Rule taken from this:** a share (or publish, or approval, or link) that
the other person cannot find in ten seconds without a docs tab is not
shipped, even if the API is green.

---

## 2. Product stance

Synaplan is a **user-centric, easy application**. Power lives behind a
clean UI.

1. The rail is **Chats · Library · Assistants · Apps · Admin** — one home
   per job (UX overhaul, [#2431](https://github.com/metadist/synaplan/pull/2431)).
   Everyday work stays in Chats and Library.
2. What a user builds or decides (assistants, shortcuts, tasks, approvals,
   AI settings) lives under **Assistants**; everything connected from
   outside is an **App**.
3. Installation-wide controls live in **Admin**, administrators only.
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
| **U1** | **Journey before Vue.** The plan names the journey (§5) and a reviewer walks the ASCII/wireframe before the first `*.vue` for that surface. Copy in all five locales is in the same PR as the first paint. | The PR invents the screen while writing it |
| **U2** | **The other person finds it in ten seconds.** Owner path *and* recipient / later-self path are specified. If the existing list cannot surface it, the plan names the inbox, badge or empty-state CTA. | "Shared with me chip on the list" with no proof the list is the one people open |
| **U3** | **Kind-specific consequence.** Every permission, status and button says what happens *on this kind*, in one sentence the average user understands. | One generic "Can use" for chat, assistant, tool and task |
| **U4** | **Familiar pattern, house skin.** Share = one-row add + live list (Drive-class). Inbox = pending / done. Gallery = cards + chips. Connect = one confirm card. Chat results = a card, not a new page. | A stacked form that looks like an admin tool |
| **U5** | **Empty state is a next action.** One sentence + one primary button. Flag off ⇒ the surface is absent (no teaser, no dead control). | Blank card, or a control that 404s |
| **U6** | **Arrival signal without a new rail.** Red-dot, badge count, or a child entry under an existing section. Chats stay a work list, not a notification dump. | Silent grant, or a new top-level item |
| **U7** | **Five questions on the open surface.** Who owns this? Who else? What can they do? How do I stop it? Where did it come from / what will it touch? A sixth primary control means cut scope. | Dialog-only answers that vanish when the dialog closes |
| **U8** | **Failure copy is product.** One sentence, no stack trace, no HTTP code, named recovery. State what did *not* happen when a write was involved. | "Request failed" / raw API error |
| **U9** | **House visual rules.** Tokens only (`surface-card`, `txt-primary`, `btn-primary px-4 py-2.5` — the 12 px corner comes from the class, see AGENTS.md "Buttons"). Dark + V2 + 320 px checked. WCAG AA. One icon-map glyph per action — the same action never ships two icons. No ancestor-scoped ink that breaks overlays. | Tailwind palette colours, raw `<button class="btn-primary">`, download as tray on one page and arrow on another |
| **U10** | **Walk the flow before merge.** A reviewer (or the author in the browser) performs the named journey end to end: click, type, find, undo. A screenshot of the happy path is not the gate. | "Looks fine in Storybook" / one render |
| **U11** | **Flag off hides everything.** No nav child, no badge, no empty teaser, 404 on new routes. | Greyed menu item "coming soon" |
| **U12** | **Reuse, do not fork.** One Share dialog, one Approvals inbox, one gallery card, one connect card, one chat run/approval card, one file-row action set. A new kind passes `kind` + consequence copy, not a new modal. | `AssistantShareModal.vue` beside `ShareDialog.vue`, hand-rolled file-row buttons beside `FileRowActions.vue` |

U1–U12 are the merge gate for every `ota-candidate` step. They sit next
to the principle "explainable to a non-technical user" and do not replace
it.

---

## 4. Shared patterns (build once)

Menu names follow the rail in §2. Plans written before the UX overhaul
say Manage, Operate, Automations, Sources or Connections; the old → new
map is §3 of the archived
[UX overhaul plan](../_devextras/planning/2026-archive/20261009-ux-overhaul/00_master_plan.md).

### 4.1 Share

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
| Conversation | Open read-only | Continue as my copy | "They will find it under Incoming chats." | Incoming chats + the chat list's shared filter |
| Knowledge folder | See the folder | The AI may use these files | "It appears in their Library as shared." | Library, shared folders |
| Assistant | See the card, clone | Start a chat | "They will find it under Assistants → Shared with me." | Assistants, Shared chip |
| Task / template | See the card | Make their own copy | "They can use it as a template under Tasks." | Assistants → Tasks, shared |
| Widget | Open read-only | — | "They will find it under Chat Widgets." | Apps → Chat widgets, shared |
| Custom tool | — | The AI may call it (your credential stays yours) | "It appears in their tool list as shared." | Custom tools, Shared chip — no share entry point yet (`_devextras/planning/ROADMAP.md` row 12) |

Owner name stays on the open resource (banner / pill), not only inside
the dialog (U7). Changing permission is in-list, not a second submit.
Shipped as IAM-UX in [#1726](https://github.com/metadist/synaplan/pull/1726);
a new kind reuses this dialog, it does not invent a second share UI.

### 4.2 Something arrived

Pattern proven by Incoming chats + Library Incoming:

- The parent section gets a **red dot / count**.
- A **sibling page** lists only incoming items, with owner + via-group +
  what I may do on every row.
- Everyday lists (chats, Library, gallery) show a **Group / Shared**
  filter so the item is also findable in context.
- Opening the item answers U7 without opening Share.

Approvals reuse this: the badge sits on Assistants → Approvals, the inbox
is `/approvals`, and every item deep-links back to the chat or run.

### 4.3 Decide in context, recover in the inbox

Write-class actions: a card **in the chat** (Approve / Reject / Always
allow). Closing the tab must not lose the decision — same item in the
inbox. Unattended runs use the inbox as the primary surface, with a
deep link to the run.

### 4.4 Catalog / gallery

Cards, not tables, for things people *pick*: assistants, templates,
custom tools, apps. Chips: Mine / Shared with me / From plugins. Primary
action on the card is the verb the user came for (**Start chat**,
**Use template**, **Try it**), not **Edit**.

### 4.5 Admin infrastructure

One page, tabs, health pills, **Test** that shows a human result
("Docling read this table", "12 models will be added"). Sovereignty
badge on the adapter, not a lecture. Never expose chain keys or MIME
families as the first sentence.

### 4.6 Connect a platform

One confirm card after sign-in. Host, uid, scopes in plain words,
**Connect** / **Cancel**, "Not you? Sign out". An empty list of linked
platforms explains *where* to start (for example the Nextcloud settings),
not Synaplan jargon.

### 4.7 Chat-native results

Compute, document generation, media: a **card in the thread**. Status,
plain-language progress, result chips that open the existing preview.
No new page. Quota / refusal is a sentence on the card.

### 4.8 Triggers: event or schedule

Anything that starts the AI without a chat message uses **one**
vocabulary across assistants and tasks. A **trigger** is either an
**event** (something arrives — mail, WhatsApp, a website visitor, an app
or coding tool, a connected app, Synaplan Desktop, a web hook) or a
**schedule** (a point in time).

| Concept | en | de | es | fr | tr |
| ------- | -- | -- | -- | -- | -- |
| What starts the assistant | **Trigger** | Auslöser | Activador | Déclencheur | Tetikleyici |
| Something arrives | **Event** | Ereignis | Evento | Événement | Olay |
| A point in time | **Schedule** | Zeitplan | Programación | Planification | Zamanlama |
| Acts without the user present | **Runs on its own** | Läuft selbstständig | Se ejecuta por sí sola | S'exécute seul | Kendi başına çalışır |

Every trigger row is one generated sentence (what arrives / when, what it
does, **who it runs as**), one visible on/off, and a last-run status. A
task card and an assistant's trigger row must be indistinguishable in
wording. Who it runs as, in three words on the row:

| Trigger | Runs as |
| ------- | ------- |
| Someone starts a chat · widget visitor · WhatsApp · app / API · connected app · Desktop | **the person talking** ("Runs as the visitor", "Runs as the caller") |
| Mail rule · web hook · schedule | **the owner, on its own** ("Runs as you"); write actions follow the approval policy |

Never "unattended", never "owner context". `cron`, `binding`, `channel`,
`topic`, `task template` and `materialise` never appear in primary copy;
`webhook` and `MCP` only on their own row. The full wireframe is
`assistant-triggers.md` in the archived folder.

---

## 5. Journeys

Every track names its journeys in its own plan (for example J-LV-1 in
live voice, J-UX-1 in the UX overhaul, J-PF-1 in the prompting flow) and
walks them before merge (U10). A journey is an acceptance demo: who
starts, what they click and type, where the other person finds the
result, how they undo it. "API green" is not a journey.

The 2026-09 journeys (J-IAM, J-AB, J-PL, J-TL, J-CP, J-NC) are §5 of the
archived contract. Two groups are still open there: **J-CP-1 / J-CP-2**
(Secure Compute B4) and **J-NC-1…3** (walked by cloud file sharing,
step CF0.7).

---

## 6. Sprint-file contract

Every sprint or step file with an `ota-candidate` step adds a
**User-flow** block under the goal (pointer + named journeys) and extends
its exit criteria with:

1. Named journey(s) walked in the browser (U10).
2. Recipient / later-self findability in ten seconds (U2).
3. Kind-specific consequence copy in all five locales (U3).
4. Empty + error + flag-off states (U5, U8, U11).
5. Dark + V2 + 320 px (U9).

A UI step's first deliverable is the journey walkthrough (and a wireframe
if the surface is new), not the Vue file: *copy is reviewed before the
component is built*. Backend-only steps (registry refactors, migrations)
are exempt until they grow a UI step.

---

## 7. Where the 2026-09 planning parts went

Frozen in
[`_devextras/planning/2026-archive/20260907_ux_user_flows/`](../_devextras/planning/2026-archive/20260907_ux_user_flows/20260907_ux_user_flows.md):

| Section of the 2026-09-07 file | Content | State |
| ------------------------------ | ------- | ----- |
| §0, §1 | Verdict on the six 2026-09 master plans; the sharing lesson table | history (summarised in §1 here) |
| §5 | Journeys J-IAM, J-AB, J-PL, J-TL, J-CP, J-NC | J-CP and J-NC still open (§5 here) |
| §7 | IAM-UX — Share dialog professionalised | shipped, [#1726](https://github.com/metadist/synaplan/pull/1726) |
| §9 | Decision of 2026-09-07 that made the file binding | history |
| wireframes | `share-dialog-v2.md`, `assistant-publish.md`, `assistant-triggers.md`, `approval-and-inbox.md`, `connect-platform.md`, `compute-run-card.md` | reference |

---

## 8. Success criteria

1. *(2026-09, met.)* The 2026-09 journeys could be walked from their
   wireframes without asking "and then where does the other person go?"
2. *(2026-09, met.)* IAM-UX landed before any new share entry point
   ([#1726](https://github.com/metadist/synaplan/pull/1726)).
3. Every UI sprint file names its journeys and adds the five exit bullets
   in §6.
4. No track adds a top-level rail item without a decision row. Sibling
   inboxes follow Incoming chats / Approvals only.
5. Primary copy in all five locales stays free of the banned words of each
   plan, plus: ACL, tenant, DAG, node, sandbox, container, auth code,
   provisioning, stdout.
