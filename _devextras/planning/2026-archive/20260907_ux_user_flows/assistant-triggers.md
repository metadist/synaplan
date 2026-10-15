# Wireframe — Triggers of an assistant (track 2 S5, kinds added in S6)

Journeys **J-AB-5** and **J-AB-7**. Replaces the former *Tasks* and
*Channels* sections. One section answers one question: **when does this
assistant act?**

## Vocabulary (fixed 2026-09-07)

| Concept | en | de | es | fr | tr |
| ------- | -- | -- | -- | -- | -- |
| What starts the assistant | **Trigger** | Auslöser | Activador | Déclencheur | Tetikleyici |
| Something arrives | **Event** | Ereignis | Evento | Événement | Olay |
| A point in time | **Schedule** | Zeitplan | Programación | Planification | Zamanlama |
| Acts without the user present | **Runs on its own** | Läuft selbstständig | Se ejecuta por sí sola | S'exécute seul | Kendi başına çalışır |

Trigger, Schedule and Runs on its own are the Saved Tasks terms
(`20260816-saved-task-workflows/08_ux_and_i18n.md` §2); Event is new.
Banned in this section's primary copy: `cron`, `webhook` (except on the
webhook row itself), `binding`, `channel`, `topic`, `task template`,
`materialise`, `MCP` (except in the small print of its own row).

## The mental model in one sentence

> An assistant always answers when someone starts a chat with it. For
> everything else it is **triggered**: by an **event** (something
> arrives — a mail, a WhatsApp message, a visitor on the website, a call
> from an app) or by a **schedule** (a point in time).

## Section

```text
┌─ Triggers ─────────────────────────────────────────────────────────┐
│ When should Contract review act?                                   │
│                                                                    │
│ ● Someone starts a chat with it in Synaplan          always on     │
│                                                                    │
│ EVENTS — when something arrives                  [ + Add event ]   │
│ ┌────────────────────────────────────────────────────────────────┐ │
│ │ ✉  When mail arrives in Support mailbox                        │ │
│ │    from anyone at acme.com · containing “contract”, “NDA”      │ │
│ │    → reviews it and replies · Runs on its own (as you)  [on ●] │ │
│ │    Last: today 09:12 · ok                              [ ⋯ ]   │ │
│ ├────────────────────────────────────────────────────────────────┤ │
│ │ ▣  When a visitor writes in Website widget “Legal help”        │ │
│ │    → answers as this assistant · Runs as the visitor    [on ●] │ │
│ ├────────────────────────────────────────────────────────────────┤ │
│ │ ⌨  Apps and coding tools may pick this assistant               │ │
│ │    Model name: assistant:contract-review · Runs as the caller  │ │
│ └────────────────────────────────────────────────────────────────┘ │
│                                                                    │
│ SCHEDULE — at a point in time                  [ + Add schedule ]  │
│ ┌────────────────────────────────────────────────────────────────┐ │
│ │ ⏰ Every Monday at 08:00 (Europe/Berlin)                       │ │
│ │    Ask it to: “Summarise last week’s contract questions…”      │ │
│ │    → Runs on its own (as you) · next: Mon 14 Sep       [on ●] │ │
│ │    Saved under Automations → Saved tasks                [ ⋯ ]  │ │
│ └────────────────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────────┘
```

Every row is **one generated sentence** (the Saved Tasks card rule: what,
when, who runs it), a status, one visible on/off, and `⋯` (Edit, Remove,
Open where it lives). No table of kinds, no JSON.

### Empty state

"Right now this assistant only answers when someone starts a chat with
it. Add an event so it reacts when something arrives, or a schedule so
it runs at a fixed time." Two buttons, both secondary until one is used.

### Add event — picker

```text
┌─ Add event ────────────────────────────────────────────────────────┐
│ What should this assistant react to?                               │
│                                                                    │
│ ✉  Mail arrives            in one of your mailboxes                │
│ ▣  A visitor writes        in one of your website widgets          │
│ ☏  A WhatsApp message      on one of your numbers                  │
│ ⌨  An app or coding tool   calls it through your API key      S6   │
│ ⧉  A connected app         Desktop, Outlook, other MCP apps   S6   │
│ 🖥 Synaplan Desktop        starts it on the user’s computer    S6   │
│ ⇄  A web hook is called    from another system            track 4  │
│                                                                    │
│                                                        [ Cancel ]  │
└────────────────────────────────────────────────────────────────────┘
```

Rows whose mechanism has not shipped are **absent**, not greyed (U11).
The `S6` / `track 4` marks are for the planner, never rendered.

### Mail event — form (the one with filters)

```text
┌─ Mail arrives ─────────────────────────────────────────────────────┐
│ Mailbox        [ Support mailbox (support@example.com)        ▾ ]  │
│                                                                    │
│ Which mails?                                                       │
│ (•) Mails matching a rule                                          │
│     From         [ @acme.com  ×] [ legal@partner.io  ×] [ + ]      │
│     Containing   [ contract  ×] [ NDA  ×] [ + ]      any ▾ of them │
│ ( ) Mails the mailbox sorts into a department   [ Legal      ▾ ]   │
│                                                                    │
│ Then                                                               │
│ [ Review the mail against our contract checklist and reply.     ]  │
│                                                                    │
│ ⓘ This runs on its own, as you. Actions that change something     │
│   outside Synaplan wait for your approval.                         │
│                                                                    │
│                                       [ Cancel ]  [ Add event ]    │
└────────────────────────────────────────────────────────────────────┘
```

Rules: From accepts addresses or `@domain`; Containing matches subject
or body, case-insensitive; empty rule = every mail in that mailbox (the
form says so in one sentence before the user can save it). The
department option appears only when the mailbox has departments.

### Schedule — form

```text
┌─ Add schedule ─────────────────────────────────────────────────────┐
│ Run    [ every week ▾ ]  on [ Monday ▾ ]  at [ 08:00 ]              │
│        Time zone: Europe/Berlin (yours)                             │
│                                                                    │
│ Ask it to                                                          │
│ [ Summarise last week’s contract questions and mail me the list. ] │
│                                                                    │
│ ⓘ Runs on its own, as you. Shortest interval: 15 minutes.         │
│ ▸ Advanced (cron expression)                                       │
│                                       [ Cancel ]  [ Add schedule ] │
└────────────────────────────────────────────────────────────────────┘
```

"Ask it to" is **required** for a schedule (nothing arrives, so the
assistant needs the job) and **optional** for an event (the arriving
message is the input).

## Who runs it — the rule the copy must make visible

| Trigger | Runs as | Why |
| ------- | ------- | --- |
| Someone starts a chat · widget visitor · WhatsApp · app / API · connected app · Desktop | **the person talking** | master plan decision 7: budget, memories, files are theirs; knowledge is the owner's shared folders |
| Mail rule · web hook · schedule | **the owner, on its own** | it is a Saved Task run (`SavedTaskRunner` runs as the owner); write actions follow the approval policy (track 4) |

The row says it in three words ("Runs as you", "Runs as the visitor",
"Runs as the caller"). Never "unattended", never "owner context".

## Publish — the consequence names the triggers

```text
Publish v3?
Contract review v3 will be used by:
 · Website widget “Legal help”
 · Support mailbox rule (acme.com, contract / NDA)
 · Schedule: Every Monday at 08:00
 · 12 people in Legal (Shared with me)
                                   [ Cancel ]  [ Publish v3 ]
```

## Where else the same rows appear

The rows are the truth, not a mirror. Adding a widget event here and
"Use an assistant" inside the widget editor create the **same row**;
removing it from either place removes it from both. The mail rule and
the schedule also appear under **Automations → Saved tasks** with the
assistant's icon and "From assistant Contract review" — editable there
too; the Triggers section shows *Saved under Automations → Saved tasks*
so nobody wonders where the run history went.

## Flag-off and error states

- Saved Tasks off ⇒ the Schedule group and the mail-rule / web-hook
  events are absent; the section still shows chat + widget + WhatsApp.
- Widget deleted / mailbox removed ⇒ row stays with a warning pill
  "Website widget was deleted" and one action **Remove**.
- Owner lost `use` on a shared assistant a widget points at ⇒ the widget
  falls back to its own instructions; the row shows "Not running — you
  no longer have access to this assistant."
- Auto-paused schedule (three failures) ⇒ "Paused automatically after 3
  failed runs" + **Resume** — the Saved Tasks wording, unchanged.
