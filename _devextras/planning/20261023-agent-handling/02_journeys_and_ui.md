# 02 — Journeys, screens and copy

Binding: [`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) U1–U12. Every
UI step's first deliverable is the walk of its journey against the wireframe
below and the copy table in §4, reviewed before the first `.vue` (U1).

Rail stays **Chats · Library · Assistants · Apps · Admin**. Nothing in this
track adds a rail item. New surfaces are a **sibling page under Assistants**
(Runs, like Approvals), a **card in the chat** (U4.7), and **sections in the
existing builder**.

---

## 1. Personas

| Who | Role | Cares about |
| --- | ---- | ----------- |
| **Ada** | Team lead, not technical | Hand off a chore, find the result, never be surprised by what was sent |
| **Ben** | Office worker | Make an assistant without learning a form; know what it remembers about him |
| **Chris** | Power user, builds assistants for his department | Helpers, skills, quality, cost |
| **Olga** | Administrator | Limits, load, nobody locked out without a reason |
| **Dev** | Developer using omp or Hermes Agent | Point their client at Synaplan and get knowledge and models |

---

## 2. Journeys

Each journey lists: start, steps, where the result is found (U2), how it is
undone (U3, AGENTS.md "Undo is a click"), and the failure it must survive.

### J-AH-1 — A developer connects omp and Hermes Agent (AH2)

1. Dev opens docs → "Use Synaplan from agent clients".
2. Creates an API key under Apps → API (existing), copies it.
3. omp: adds a provider block to `~/.omp/agent/models.yml` with
   `baseUrl: https://<host>/v1` and `api: openai-completions` (exact block
   from AH1), runs
   `omp models synaplan`, sees the models, assigns `default` and `smol`.
4. Hermes Agent: sets a custom OpenAI-compatible endpoint and adds Synaplan's
   `/mcp` as an MCP server; asks "search my Synaplan knowledge for the Q3
   contract"; gets passages with file names.
5. Uses an assistant as a model: `assistant:tender-reader`.

**Found:** usage on Statistics (existing), the key's last-used time under Apps
→ API. **Undo:** revoke the key (one click, existing). **Must survive:** a
model without tool support answers `tools_not_supported` with the doc's
sentence on what to choose instead.

### J-AH-2 — Ada stops a running Saved Task (AH5)

1. Assistants → Tasks. "Monday supplier digest" shows **Running · step 2 of 4
   · Reading 6 mails** (live).
2. Ada clicks **Stop** → confirm dialog (`useDialog`, danger): "Stop this run?
   Steps already finished stay done, and no new step starts. A step that is
   running right now may still finish, including a message it is sending."
3. Card: **Stopped by you at 09:14. Steps 1–2 finished. The summary e-mail was
   not sent.** Link: **Open the run's chat**.

**Found:** the card and the task chat. **Undo:** **Run now** starts it again.
**Must survive:** Stop during a provider call ("The step that was running may
still be billed.").

### J-AH-3 — Ben creates an assistant from one sentence (AH7)

1. Assistants → **Create** → the first field is **"What should it do?"** with
   an example placeholder.
2. Ben types "Answers customer questions about our return policy, friendly,
   in German and English" → **Draft it**.
3. The form fills: name, description, instructions, three starter prompts,
   suggested actions and tools; a banner: "Draft written for you. Check it,
   then save."
4. Ben edits the name, opens the test panel, asks one question, **Save**,
   **Publish**.

**Found:** gallery → Mine. **Undo:** delete the draft, or archive (existing).
**Must survive:** the drafting model fails → "Could not write a draft right
now. You can fill the form yourself or try again." The form stays empty, not
half-filled.

### J-AH-4 — Ada talks to an assistant in Telegram (AH6)

1. Apps → Telegram (connected bot, existing) → new row **Answers with**
   [assistant ▾] → "Inbox helper".
2. In Telegram, Ada writes to her bot; the answer comes from Inbox helper.
3. In the builder of Inbox helper, Triggers shows the row "Someone writes to
   your Telegram bot · Runs as the person talking · on".

**Undo:** set **Answers with** back to "Synaplan (default)", or switch the
trigger row off. **Must survive:** the assistant is archived or unshared →
the bot answers with the default and the Telegram page says why.

### J-AH-5 — Ada lets an assistant work on a job (AH11, AH12)

1. Chat with "Purchasing helper" pinned. Ada: "Compare the three offers in
   the folder Offers Q4 and draft a reply to the cheapest supplier."
2. The planner proposes 7 steps; a chip appears: **Let it work** — "Purchasing
   helper works through this on its own and asks you before sending." Ada
   clicks it. (Or Tools → **Let it work** before sending.)
3. A **run card** replaces the step bubble (wireframe §3.1): live lines
   "Reading offer-acme.pdf", "Comparing prices", "Writing the reply".
4. Ada closes the tab. Later, Assistants shows a **badge 1**; Assistants →
   **Runs** lists the run under **Needs you**: "Approve the e-mail to
   ACME GmbH".
5. Ada opens it, reads the draft in the approval card, sends a **Hint**:
   "Mention our framework contract number". The run revises, asks again, she
   **Approves**.
6. Card: **Done in 4 min · 9 steps · €0.12. The reply to ACME GmbH was
   sent. The comparison table is in Offers Q4/comparison.xlsx.**

**Found:** the chat (card), Runs page, the Library file, the sent mail in the
mail log. **Undo:** Stop at any time; the file has revisions; a sent mail
cannot be unsent — the approval card said so before sending. **Must
survive:** a closed tab, a worker restart (run resumes or ends `interrupted`
with what was done), a budget hit ("Stopped after 30 steps. Here is what I
found; the reply was not written.").

### J-AH-6 — A scheduled run needs Ada (AH12, AH13)

1. Saved Task "Weekly tender scan" (step **Let an assistant work** with "Tender
   reader") runs Sunday 22:00 as Ada.
2. It wants to save a summary into a shared folder (write, approval needed);
   headless ⇒ it waits; Approvals and Runs get the item; Ada's notification
   setting sends the digest at 07:00.
3. Monday, Ada sees the badge, approves in the inbox, the run continues and
   finishes; the task card shows the outcome sentence.

**Must survive:** the approval expires (72 h) → "The summary was not saved:
the approval expired. Run it again from the task." The run ends `stopped`,
not `running`.

### J-AH-7 — Chris imports a skill (AH17)

1. Builder of "Tender reader" → **Skills** → **Add skill** → **From a link** →
   pastes a public GitHub folder URL.
2. Import card: name, description, licence, size, and **"Instructions only —
   this skill does not run programs here."** (or "It needs to run programs.
   This assistant may run them in Secure Compute." when allowed).
3. **Add** → the skill appears as a card; the folder appears in Library →
   Skills.
4. A run's timeline shows "Used skill: tender-matrix".
5. **Remove** on the card (consequence: "The assistant stops using it. The
   files stay in your Library.").

**Must survive:** invalid skill ("This folder is not a skill: SKILL.md is
missing a description."), too large, a link that is not public.

### J-AH-8 — The assistant proposes an improvement (AH18)

1. After J-AH-5, the run card ends with **"Purchasing helper suggests an
   improvement"** → opens the approval item of kind Improvement: "Add to the
   skill *offer-comparison*: always compare payment terms, not only price.
   Why: you asked for it in this run." with a diff.
2. Ada **Accepts**. The skill file gets a new revision.
3. Next run uses it (timeline "Used skill: offer-comparison").
4. Ada opens Library → Skills → offer-comparison → Revisions → **Restore**
   the previous version.

**Found:** the run card, the Approvals inbox (kind filter Improvement).
**Undo:** Reject, or restore the revision.

### J-AH-9 — Ben looks at notes (AH15)

1. Assistant page → **Notes** tab: two lists — **About working with you**
   (private: "Only you see these.") and **About this job** (shared: "Everyone
   who uses this assistant benefits. Only editors can change these.").
2. Ben deletes one personal note (one click, undo toast).
3. Memories page has a filter **From assistants** listing the same personal
   notes.

### J-AH-10 — Chris builds a team (AH20, AH21)

1. Builder of "Report writer" → **Helpers** → **Add helper** → picks
   "Researcher" (an assistant he may use) and writes its role in one line:
   "Finds sources and returns facts with links".
2. Switches on **Double-check** ("A second model checks the report before it
   is final").
3. In a run, the timeline shows "Asked Researcher: market size DACH" as a
   nested row with its own steps and cost, then "Double-check: one concern —
   the 2024 figure has no source", then "Fixed: removed the figure".

**Undo:** remove the helper; switch Double-check off. **Must survive:** the
helper is unshared ("Researcher is no longer available to you. The run
continued without it.").

### J-AH-11 — Olga sets limits (AH22)

1. Admin → AI → **Assistant runs**: on/off, steps, minutes, cost per run,
   active runs per person and per install, proposals on/off, plan-level runs
   per day; a live count "3 runs active now".
2. A person over the limit sees on the run card: **Queued — you already have
   3 runs working. It starts when one finishes.** with **Stop**.

---

## 3. Wireframes

### 3.1 Run card in the chat (AH11)

```text
┌ Purchasing helper is working on this ───────────────── Running · 3:12 ┐
│ ✓ Read 3 offers from Offers Q4                                        │
│ ✓ Compared prices and payment terms           (table saved)           │
│ ● Writing the reply to ACME GmbH                                       │
│   ↳ Asked Researcher: "ACME framework contract"  ✓ 0:40 · €0.01        │
│                                                                        │
│ Needs you: approve the e-mail to ACME GmbH          [ Review ]         │
│                                                                        │
│ [ Send a hint… ]                                    [ Stop ]  Details ▾│
└────────────────────────────────────────────────────────────────────────┘
 Details: steps · tools used · skills used · models used · cost · started by / runs as
```

Terminal states replace the header line and add the outcome sentence:

```text
┌ Done · 4 min · 9 steps · €0.12 ──────────────────────────────────────────┐
│ The reply to ACME GmbH was sent. The comparison table is in              │
│ Offers Q4/comparison.xlsx.                                               │
│ Purchasing helper suggests an improvement          [ Look at it ]        │
└──────────────────────────────────────────────────────────────────────────┘
```

### 3.2 Assistants → Runs (AH12)

```text
Runs                                         [ Running 2 ] [ Needs you 1 ] [ Done ] [ Stopped ] [ Failed ]
──────────────────────────────────────────────────────────────────────────────────────────────────────
● Needs you  Purchasing helper · "Compare the three offers…"  · started by you · 3 min   [ Review ]
● Running    Tender reader · Weekly tender scan · runs as you · step 6 · 2 min           [ Stop ]
● Running    Report writer · "Market report DACH" · started by you · step 3 · €0.04      [ Stop ]
✓ Done       Inbox helper · Morning triage · 07:02 · "12 mails sorted, 2 drafts waiting"   Open
──────────────────────────────────────────────────────────────────────────────────────────────────────
Empty: "No runs yet. Ask an assistant to work on something in a chat and choose Let it work."  [ Open a chat ]
```

Opening a row opens the run in its chat (the card above) — no second run
view. The five questions (U7) on the row and the card: **who owns it**
(assistant + "started by" / "runs as"), **who else** (shared assistant owner
shown as "by Chris"), **what it will touch** (Details: tools and folders),
**how to stop it** (Stop), **where it came from** (chat, task or trigger link).

### 3.3 Builder additions (AH17, AH20, AH22 limits per assistant)

```text
Helpers            Assistants this one may hand work to
  Researcher — "Finds sources and returns facts with links"        [ Remove ]
  [ + Add helper ]

Skills             Instructions this assistant can look up when a job needs them
  ┌ offer-comparison ─────────────┐ ┌ tender-matrix ──────────────┐
  │ Compare offers by price…      │ │ Build a requirement matrix… │
  │ Instructions only · yours     │ │ Instructions only · shared  │
  └───────────── [ Remove ] ──────┘ └─────────── [ Remove ] ──────┘
  [ + Add skill ▾ ]  Write one · From a file · From a link · From my Library

When it works on its own
  Up to [ 30 ] steps · [ 15 ] minutes · [ €1.00 ] per run
  [x] Double-check the result with a second model
  [ ] Also double-check every action before it asks for approval
  [x] Suggest improvements after a run (you decide)
```

---

## 4. Copy (five locales)

Final strings are reviewed by native speakers before AH11 merges. One term
per concept; the terms below are the only new ones (master §0 #3, #4).

| Concept | en | de | es | fr | tr |
| ------- | -- | -- | -- | -- | -- |
| One piece of work an assistant does on its own | **Run** | Lauf | Ejecución | Exécution | Çalışma |
| The page | **Runs** | Läufe | Ejecuciones | Exécutions | Çalışmalar |
| Start a run | **Let it work** | Selbst arbeiten lassen | Dejar que trabaje | Le laisser travailler | Çalışmasına izin ver |
| Message to a running run | **Send a hint** | Hinweis geben | Dar una indicación | Donner une indication | İpucu ver |
| Waiting for a person | **Needs you** | Wartet auf dich | Te necesita | Attend votre réponse | Seni bekliyor |
| Assistant another may use | **Helper** | Helfer | Ayudante | Aide | Yardımcı |
| What it wrote down | **Notes** | Notizen | Notas | Notes | Notlar |
| Open-format instructions | **Skill** | Skill | Habilidad | Compétence | Beceri |
| Planner capabilities (renamed, §0 #4) | **Actions** | Aktionen | Acciones | Actions | Eylemler |
| Second-model check | **Double-check** | Gegenprüfung | Doble verificación | Double vérification | Çift kontrol |
| Proposal kind in Approvals | **Improvement** | Verbesserung | Mejora | Amélioration | İyileştirme |

**Outcome sentences** (templates; placeholders identical in every locale):

| State | en |
| ----- | -- |
| completed | "Done in {duration}. {what_happened}" |
| completed with pending approvals | "Done. {what_happened} {count} actions still wait for your approval." |
| stopped by you | "Stopped by you at {time}. {done_part} {not_done_part}" |
| budget used | "Stopped after {limit}. Here is what was found. {not_done_part}" |
| interrupted | "The server restarted during this run. {done_part} Start it again to finish." |
| failed | "This run could not finish: {reason}. {done_part} {not_done_part}" |
| queued | "Queued — you already have {count} runs working. It starts when one finishes." |

`{not_done_part}` is assembled from the run's ledger of planned writes that
did not execute ("The e-mail to ACME GmbH was not sent.") — never from the
model's prose (master §7, first risk).

**Banned in primary copy:** agent, subagent, loop, orchestrator, delegate,
iteration, token, kanban, cron, node, sandbox, container, DAG; `MCP` only on
its own row (UX contract §8 adds ACL, tenant, auth code, provisioning,
stdout).

---

## 5. Empty, error and flag-off states

| Surface | Empty | Error | Flag off |
| ------- | ----- | ----- | -------- |
| Runs page | "No runs yet. Ask an assistant to work on something in a chat and choose Let it work." + **Open a chat** | "Runs could not be loaded. Try again in a moment." + **Try again** | Route 404, no nav child, no badge |
| Run card | — | Outcome sentence of state `failed` with a named recovery (**Start again**, **Open settings**) | No **Let it work** chip or menu entry; existing runs still open read-only |
| Helpers section | "No helpers. Add an assistant this one may hand work to." + **Add helper** | "Researcher is no longer available to you." | Section absent |
| Skills section | "No skills yet. Add instructions this assistant can look up." + **Add skill** | Validator sentence (J-AH-7) | Section absent |
| Notes tab | "Nothing noted yet. Notes appear after the assistant has worked with you." | "Notes could not be loaded." + **Try again** | Tab absent |
| Task card (AH5) | — | "Stopping could not be confirmed, so the run may keep going." + **Try again**. "It stops before its next step" is shown only after the server acknowledged the stop (cancellation flag set). | Stop is part of the card whenever Saved Tasks are on (no new flag) |
| Telegram row (AH6) | "Synaplan (default)" preselected | "This assistant can no longer answer here: {reason}." | Row absent when assistants are off |
| Help me write (AH7) | Placeholder example | J-AH-3 failure sentence | Field absent when assistants are off |

---

## 6. Exit bullets for every `ota-candidate` step (UX contract §6)

1. Named journey(s) walked in the browser (U10), entered in STATUS.
2. Recipient / later-self findability in ten seconds (U2): the Runs badge, the
   chat card, the Approvals inbox, the task card.
3. Kind-specific consequence copy in all five locales (U3).
4. Empty + error + flag-off states of §5 (U5, U8, U11).
5. Dark + V2 + 320 px (U9).
