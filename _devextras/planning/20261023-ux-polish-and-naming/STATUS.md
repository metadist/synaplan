# UX polish and naming — status

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk, a count
and a CI run are entries here, not chat messages.

## Steps

| Date | Step | Pull request | State | Notes |
| ---- | ---- | ------------ | ----- | ----- |
| 2026-10-23 | UP0 | — | written, not committed | plan, visual check, naming map |
| 2026-10-23 | §0 | — | ticked | owner interview: D1–D15 all as proposed (D1 option A — "agent"); UP1 and UP6 start now, beside rows 21 and 22 |

## Baselines

Taken on `main` at `a9f75713b`. Commands in
[`01_visual_check.md`](./01_visual_check.md) §4.

| Date | What | Value |
| ---- | ---- | ----- |
| 2026-10-23 | `text-[10px]` / `text-[9px]` / `text-[11px]` in `*.vue` | 129 in 51 files / 2 / 79 |
| 2026-10-23 | Raw Tailwind status colours in `*.vue` | 740 in 125 files |
| 2026-10-23 | `--status-*-text` token uses | 65 |
| 2026-10-23 | `text-red-600` without a `dark:` variant | 7 |
| 2026-10-23 | Distinct sizes of `h1` / `h2` / `h3` | 6 / 6 / 6 |
| 2026-10-23 | English labels in Title Case | ~755 |
| 2026-10-23 | English strings with "assistant(s)" / "agent(s)" | 189 / 26 |
| 2026-10-23 | Contrast failures after settle, 13 pages | light 8, dark 4, OLED 2 (all chips, badges, the budget bar, one key badge) |

## Walks

### 2026-10-23 — visual check (UP0)

Dev stack on `main` at `a9f75713b`, `admin@synaplan.com`, V2 design,
English, Cursor browser with a 1440 × 900 viewport and 320 × 700 for
overflow. The audit is [`walk/contrast-audit.js`](./walk/contrast-audit.js),
run in the page through CDP `Runtime.evaluate`.

1. Signed in with **Continue as administrator**. Chat opened on the last
   thread.
2. Light theme, 13 pages through the router (`/`, `/files`,
   `/ai/assistants`, `/ai/models`, `/tasks`, `/apps`, `/channels/widgets`,
   `/memories`, `/statistics`, `/admin`, `/admin/people`, `/setup/status`,
   `/settings`). Failures (after the oklch fix): Library 1 (`TASKPROMPT:`
   badge 4.20), Chat widgets 5 (stat chips 2.52–4.16), Statistics 2
   ("No limit" 2.22, "Active" 3.20). Others 0.
3. On `/ai/assistants` the **Tasks** tour ("Create a task · 1 of 2") was
   open, pointing at an empty box. It had started on `/tasks` during
   step 2. Navigated to `/files` and `/apps`: still open, page still dimmed
   (`driver-active driver-fade`). Closed with ×. → V11.
4. Dark theme (`localStorage.theme = 'dark'`, reload). The first run
   reported primary text at 3.2:1 on most pages; a screenshot showed white
   text and `getComputedStyle` gave `rgb(255,255,255)` with no opacity in
   the chain — the run had measured mid route-transition. Re-run after
   waiting for animations: Library 1 (3.58), Chat widgets 1 ("0 Internal"
   4.47), Statistics 2 ("No limit" 2.22, "No" 3.49). Others 0.
5. OLED theme: Statistics 2 ("No limit" 2.22, "No" 4.16); all other pages 0
   after settle. Visually the bird watermark is clearly visible through the
   Library cards. → V5.
6. Headings measured on Apps ("Website" `h2` 14 px vs card title 16 px),
   Library ("Storage Quota" `h3` 14 px, file names 12 px), Admin (`h2` and
   `h3` both 18 px), AI settings (cost pills 10 px). → V1, V2.
7. AI settings → Topics → "(custom) smoke-rag-2cc0c3e2": label **"Available
   Agents"** over the tool toggles; "1 chunks · Currently linked to:
   TASKPROMPT:smoke-rag-2cc0c3e2"; "Used by the AI sorter". → V8, `02` §3 b.
8. 320 px on 7 pages: no page wider than the screen; People search ends at
   329 px (`min-w-[300px]`). → V12.
9. Not walked: other locales on screen, the widget visitor side, mobile
   shell, Outlook add-in (`01` §5).

Undo: theme set back to light; no data was created or changed.
