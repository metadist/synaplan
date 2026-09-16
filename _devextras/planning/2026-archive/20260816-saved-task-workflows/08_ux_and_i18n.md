# UX and four-language comprehension

**Status:** Draft 2026-08-15. Applies to every sprint. **Copy is reviewed before the component is built, not after.**

Saved Tasks is the first Synaplan feature where the product **acts without the user watching**. If people do not understand what they switched on, when it runs, what it will touch, and how to stop it, the feature is a liability regardless of how well the executor works. This document is the UX contract, and it treats German, Spanish and Turkish as first-class — not as a translation chore at the end of the sprint.

---

## 1. The five questions every screen must answer

A user looking at a Saved Task must be able to answer these without support, in their own language:

| # | Question | Where it is answered |
| - | -------- | -------------------- |
| 1 | **What will this do?** | One-sentence summary on the task card, generated from the trigger + prompt + action — not the raw graph |
| 2 | **When does it run?** | Schedule in plain words: "Every weekday at 07:00 (Europe/Berlin)", never a cron string in primary copy |
| 3 | **What does it touch?** | Named connection and destination: "Reads *Work mailbox*, saves to *Nextcloud / Synaplan/Documents*" |
| 4 | **Did it work?** | Runs list with plain-language status and last-run time |
| 5 | **How do I stop it?** | One visible toggle, always in the same place, effective immediately |

A design that cannot answer all five on one screen is not ready. **If a control is needed to answer a sixth question, cut scope instead of adding the control** (master plan UX principle 3).

---

## 2. Canonical terminology — all four locales

One term per concept, everywhere: UI, docs, error messages, release notes. **Proposed translations below are for native-speaker review (§6); do not treat them as final until row L1 of the checklist is ticked.**

| Concept | EN (canonical) | DE | ES | TR |
| ------- | -------------- | -- | -- | -- |
| The saved, repeatable job | **Saved Task** | Gespeicherte Aufgabe | Tarea guardada | Kayıtlı görev |
| The instruction it runs (existing) | Task Prompt / AI Instructions | KI-Anweisungen | Instrucciones de IA | AI talimatları |
| Execute once, now | **Run now** | Jetzt ausführen | Ejecutar ahora | Şimdi çalıştır |
| One execution | **Run** (noun) | Ausführung | Ejecución | Çalıştırma |
| History of executions | **Runs** | Ausführungen | Ejecuciones | Çalıştırmalar |
| What starts it | **Trigger** | Auslöser | Activador | Tetikleyici |
| Time-based trigger | **Schedule** | Zeitplan | Programación | Zamanlama |
| Temporarily not running | **Paused** | Pausiert | En pausa | Duraklatıldı |
| Stopped by us after repeated failures | **Paused automatically** | Automatisch pausiert | Pausada automáticamente | Otomatik olarak duraklatıldı |
| An external system the user linked | **Connection** | Verbindung | Conexión | Bağlantı |
| Verify the connection works | **Test connection** | Verbindung testen | Probar conexión | Bağlantıyı test et |
| Where results are delivered | **Destination** | Ziel | Destino | Hedef |
| Deliver a file to a destination | **Save to…** | Speichern in … | Guardar en… | …'a kaydet |
| Mailbox | **Mailbox** | Postfach | Buzón | Posta kutusu |
| Folder | **Folder** | Ordner | Carpeta | Klasör |
| Calendar file (.ics) | **Calendar file** | Kalenderdatei | Archivo de calendario | Takvim dosyası |
| Optional graph editor | **Advanced steps** | Erweiterte Schritte | Pasos avanzados | Gelişmiş adımlar |
| One box in the graph | **Step** | Schritt | Paso | Adım |
| Sign in again to the external system | **Reconnect needed** | Neu verbinden erforderlich | Es necesario reconectar | Yeniden bağlanma gerekli |
| Runs without the user present | **Runs on its own** | Läuft selbstständig | Se ejecuta por sí sola | Kendi başına çalışır |

### 2.1 Words that must never appear in primary UI copy

`DAG`, `graph` (as a noun for the feature), `node`, `cron`, `webhook` (in the task card — fine on the connection screen), `workflow`, `orchestration`, `n8n`, `capability`, `runner`, `executor`, `payload`, `topic id`, `MCP` (outside the MCP connection screen).

Secondary/technical surfaces (advanced editor, API docs, admin) may use precise technical terms. The task card and the schedule picker may not.

### 2.2 Terms that already exist — do not invent synonyms

`AI Instructions` stays the nav label (master plan checklist row 3). Saved Tasks live **inside** it. Never introduce a second name for the same screen in a different locale: if DE says *KI-Anweisungen* in the nav, every DE mention of that screen says *KI-Anweisungen*.

---

## 3. Screen-by-screen specification

### 3.1 Where it lives

`/ai/instructions` — the existing Task Prompts screen, extended. Routes and nav come from two places that must be changed together: `frontend/src/router/index.ts` and `frontend/src/composables/useNavItems.ts` (single source for desktop rail **and** mobile bottom nav). **No new top-level nav item in v1.**

Connections get their own home under Channels (`/channels/connections`) because they are shared by more than Saved Tasks.

### 3.2 The task card (the whole feature, for most users)

Placed in the Task Prompt editor as one `surface-card` section titled **Saved Task**.

```
┌─ Saved Task ────────────────────────────────── [ On ● ] ─┐
│                                                           │
│  Runs every weekday at 07:00 (Europe/Berlin)              │
│  Reads: Work mailbox → Saves: calendar file               │
│                                                           │
│  [ Run now ]   Schedule: [ Every weekday ▾ ] [ 07:00 ]    │
│                                                           │
│  Last run: today 07:00 · Completed        View runs →     │
│                                                           │
│  ▸ Advanced steps                                         │
└───────────────────────────────────────────────────────────┘
```

Exactly three primary controls: **on/off**, **Run now**, **schedule picker**. Everything else is text or a link. `Advanced steps` is a collapsed disclosure that is empty for most users.

**Required states** — each needs copy in four locales, and each needs a story in the component test:

| State | Card shows |
| ----- | ---------- |
| Never saved as a task | Empty state: what a Saved Task is in one sentence + `Save as task` button |
| Saved, off | Toggle off, schedule visible but greyed, "Not running" |
| Saved, on, never run | "Scheduled — first run <when>" |
| Running now | Inline progress, `Run now` disabled, cancel if supported |
| Last run completed | Green status + timestamp + link |
| Last run failed | Plain-language reason + `Try again`; **never** a stack trace or HTTP code |
| Paused automatically (3 failures) | Prominent notice: why it paused, what to fix, `Resume` button |
| Connection broken | "Reconnect needed: *Work mailbox*" with a direct link to that connection |
| Feature flag off | Card not rendered at all (no teaser, no dead control) |

### 3.3 Schedule picker

Options in v1: **Off**, **Every hour**, **Every day**, **Every weekday**, **Every week**, plus a time and the user's timezone shown explicitly. Minimum interval 15 minutes (master plan §3.3). A cron expression field may exist **only** behind Advanced steps, and the plain-language sentence above the card must still describe it correctly.

Always show the resolved timezone. "07:00" without a timezone is a support ticket.

### 3.4 Runs list

A table is acceptable here — it is enumerable fact. Columns: when, trigger, status, duration, link into the task's conversation. Failed rows expand to the plain-language reason. Retention (50 runs / 90 days) is stated at the bottom so nobody thinks history was lost to a bug.

### 3.5 Connections screen (`/channels/connections`)

One list, one add flow, one detail panel — the F1/F5 foundations from [`07_connectors.md`](./07_connectors.md). Per connection: name, type, status pill, last checked, `Test connection`, `Disconnect`, and for cloud destinations a **sovereignty note** stating where data goes (row S7).

Disconnecting must warn when Saved Tasks depend on it, and say what will happen to them.

### 3.6 The "Advanced steps" editor (Sprint 2)

Reuses the widget canvas *interaction* (boxes + links), never its storage. Entering it is opt-in and reversible; a user who opens it and changes nothing must be able to leave with the simple card intact. If a graph exists, the card's plain-language summary is generated from it — the summary is the contract, the canvas is the detail.

---

## 4. Failure copy — the part that decides whether users trust this

Unattended features fail in front of nobody. The message is the only thing the user ever sees, so it is product surface, not developer output.

**Rule:** every failure maps to the shared vocabulary from [`07_connectors.md` §5](./07_connectors.md#5-the-way-into-the-apps--one-contract-four-destinations). A new connector adds **zero** new translation keys.

| Vocabulary code | EN user-facing message | Recovery offered |
| --------------- | ---------------------- | ---------------- |
| `unauthorized` | "*{connection}* rejected the login. Reconnect it to continue." | Link to the connection |
| `not_found` | "The folder *{target}* no longer exists in *{connection}*." | Edit destination |
| `quota_exceeded` | "*{connection}* is out of space." | – |
| `too_large` | "The file is larger than *{connection}* accepts." | – |
| `unreachable` | "*{connection}* could not be reached. Synaplan will try again at the next run." | – |
| `conflict` | "A file with that name already exists; saved as *{newName}* instead." | – |
| `rate_limited` | "Your usage limit was reached, so this run was skipped." | Link to usage |
| `prompt_failed` | "The AI step could not complete. Nothing was sent or saved." | `Try again` |
| `no_input` | "Nothing to do — no new messages matched." | – (this is success, not failure) |

Each message: four locales, interpolated names (never a raw id), one sentence, no blame, and **a statement of what did *not* happen** when a mutating step was involved. "Nothing was sent or saved" prevents the worst support case — a user who does not know whether the email went out.

**Notification of failures** follows master plan row 12: visible in the Runs list always; a user-facing notification on scheduled failure; auto-pause after 3 consecutive failures with an explicit reason and a `Resume` action.

---

## 5. i18n engineering rules

### 5.1 Key structure

Namespace everything under `config.savedTasks.*` and `config.connections.*`, plus `pageTitles.*`. Mirror the existing `config.taskPrompts.*` convention. Files: `frontend/src/i18n/{en,de,es,tr}.json`; `supportedLanguages = ['de','en','es','tr']`, fallback `en`.

Reuse `common.*` for shared verbs (Save, Cancel, Delete). Do not create `savedTasks.save`.

### 5.2 Locale parity is a CI gate, not a convention

Today a missing key silently falls back to English, and (as far as the repo shows) nothing checks parity — only `resilientCompiler.spec.ts` and `searchSummaryPlural.spec.ts` exist under `frontend/tests/unit/i18n/`. **Verify this before Sprint 1 and, if there is no parity check, add one as the very first frontend step** ([`09_work_breakdown.md`](./09_work_breakdown.md) step F0).

The parity test must fail on:

1. A key present in `en.json` but missing in `de`/`es`/`tr` (and vice versa).
2. A key whose interpolation placeholders differ across locales (`{count}` in EN but `{anzahl}` in DE breaks at runtime).
3. A value that is byte-identical to the English string in `de`/`es`/`tr` **within the Saved Tasks namespace** — the usual sign of a forgotten translation. Allow an explicit opt-out list for genuine loanwords ("Webhook", "API").

This runs in the existing `Frontend (Vue/TypeScript)` CI job via `make -C frontend test`; no new job.

### 5.3 Writing rules

- **Interpolate names, never ids:** "Reads *Work mailbox*", not "Reads mailbox #7".
- **No string concatenation** to build sentences — German and Turkish word order will break it. One key per full sentence.
- **Plurals via vue-i18n pipe syntax**, tested. "3 runs" / "1 run" must both be correct in all four languages (Turkish plural rules differ from English).
- **Dates and times through the shared formatter** with the user's locale and explicit timezone. Never hand-format.
- **Length budget:** German runs ~30% longer than English. Any button or pill must be laid out to survive it; check the DE locale in the browser before calling a component done.

---

## 6. How we verify people actually understand it

Translation completeness is measurable; comprehension is not, unless we check. Before the feature is enabled for anyone:

| # | Check | Who | Pass condition |
| - | ----- | --- | -------------- |
| L1 | **Native-speaker review** of the terminology table (§2) and every Saved Tasks string | One reviewer per locale (DE, ES, TR), named in the PR | Reviewer signs off in the PR; corrections applied in the same change |
| L2 | **Five-question test** (§1) on the finished screen, per locale | Someone who did not build it | All five answered from the screen alone, no docs |
| L3 | **Failure walkthrough** — deliberately break a connection and read the resulting message in each locale | QA | Message is understandable, names the connection, offers a next step |
| L4 | **Jargon scan** — grep the Saved Tasks namespace for the §2.1 banned words | Automatable, part of the parity test | Zero hits in primary copy keys |
| L5 | **Dark mode + V2 + mobile width** for every new surface | Implementer | WCAG AA in both themes and V2; no clipped DE strings at 320px |
| L6 | **Docs match the UI** in all four languages where docs are localised | Implementer | Same canonical terms as §2 |

L1 is a blocking gate for GA, not a nice-to-have. Book the reviewers when Sprint 1 starts, not when it ends.

---

## 7. Accessibility and theming (non-negotiable, per AGENTS.md)

- Tokens from `style.css`; verify against `style-v2.css` (the V2 glass design overrides many tokens — a card that looks right in V1 can be unreadable in V2).
- Status pills carry a text label, not colour alone — colour-blind users must distinguish `failed` from `completed`.
- `useDialog()` / `useNotification()` only; never native `alert` / `confirm`.
- Every interactive control reachable by keyboard; the toggle announces its state.
- Contrast checked in light **and** dark for every new pill and status colour.

---

## 8. Deliverables per sprint

| Sprint | UX/i18n deliverable |
| ------ | ------------------- |
| 0 | Terminology table signed off (L1); i18n parity test in place (step F0); empty-state copy for "Save as task" |
| 1 | Task card in all states of §3.2; failure vocabulary keys ×4 locales; L2 on the card |
| 2 | Advanced-steps disclosure; plain-language summary generated from the graph; L2 re-run |
| 3 | Schedule picker copy incl. timezone; auto-pause notice; L3 failure walkthrough |
| 4 | Connections screen incl. sovereignty notes; destination picker; L1 re-run for new strings |

Every sprint: all four locales in the same commit, DE length check, dark/V2 check, and the parity test green in the unfiltered gate.
