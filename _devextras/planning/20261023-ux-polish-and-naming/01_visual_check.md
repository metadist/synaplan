# Visual check — 2026-10-23

The walk this plan rests on. Dev stack on `main` at `a9f75713b`, signed in
as `admin@synaplan.com`, V2 design, English. Every finding below was seen
on screen and, where a number is given, measured with
[`walk/contrast-audit.js`](./walk/contrast-audit.js) in the page itself.

## 1. Method

- **Pages (13):** Chat (`/`), Library (`/files`), Assistants
  (`/ai/assistants`), AI settings (`/ai/models`, all three tabs and one
  topic opened), Tasks, Apps, Chat widgets, Memories, Statistics, Admin
  dashboard, People, Setup status, Settings → Profile.
- **Themes (3):** light, dark, OLED (`useTheme.ts`: `light | dark | oled |
  system`).
- **Widths:** 1440 px for the visual pass, 320 px for overflow.
- **Contrast:** every visible element with its own text, foreground
  blended with its own and its ancestors' opacity, background blended down
  to the first opaque ancestor. AA = 4.5:1, 3:1 for large text. Disabled
  controls are skipped (WCAG exempts them).
- **Headings:** computed `font-size` / `font-weight` of every `h1`–`h5`,
  plus the text directly under it.
- **Code census:** `rg` over `frontend/src` for the patterns behind the
  findings (counts in §4).

Two measurement traps, both fixed in the script before the numbers below
were taken: Tailwind v4 emits `oklch()` colours (the first run skipped
them), and the route transition fades the page in (a first dark-theme run
reported primary text at 3.2:1 because it measured mid-fade).

## 2. What is already good

Recorded so the next change does not break it.

- **Body and secondary text pass AA in all three themes** on all 13 pages
  once the page has settled. The higher-contrast themes (#2310) did their
  job; the failures below are confined to chips, badges and one bar.
- **One page title.** `PageHeader.vue` gives every page the same `h1`
  (24 px / 600) with a 14 px subtitle; 34 views use it.
- **Chat answers.** `assets/markdown.css` sets `h1` 24 px, `h2` 20 px,
  `h3` 18 px over 16 px body; the answer reads well.
- **320 px.** No page scrolls sideways; wide tables (Statistics, People)
  scroll inside their card.
- **Files table.** The long-name overflow is fixed
  ([#2649](https://github.com/metadist/synaplan/pull/2649), PQ6).

## 3. Findings

Ids are cited by the master plan's step table.

### Headings and text sizes

| Id | Where | Seen / measured | Why it matters |
| -- | ----- | --------------- | -------------- |
| V1 | Apps | Category heading "Website" is `h2` **14 px**/600; the card titles under it ("Mailbox automation") are **16 px**/500 | The heading is smaller than what it heads. The eye reads the cards as the top level and the categories as captions. |
| V1 | Library | "Storage Quota" `h3` 14 px/500 — same as body text; "Your Files" `h2` 20 px; **file names 12 px**/500 | The thing a person looks for (the file name) is the smallest text on the page. |
| V1 | Admin dashboard | "Needs attention" `h2` 18 px and "System Info" `h3` 18 px | Two levels, one size. |
| V1 | AI settings | `h1` "AI settings" 24 px → tab bar → `h3` "Default Model Configuration" 18 px; on Topics an extra `h2` "Topics" 18 px repeats the tab name | A tab bar, then a heading that repeats it. |
| V1 | Sidebar | Group labels ("ASSISTANTS", "AUTOMATIONS", "PLUGINS") 11 px uppercase | Below the 12 px floor for readable text. |
| V1 | Code | `h1` appears in 6 sizes (`text-base` … `text-4xl`), `h2` in 6, `h3` in 6 (`text-lg` 79×, `text-sm` 40×, `text-base` 16×, `text-xl` 12×) | There is a page-title rule (PageHeader) and nothing for section or card headings; every author picks a size. |
| V2 | AI settings, Library, widgets | "High Cost" / "Mid Cost" pills **10 px**; the `TASKPROMPT:…` file badge 10 px; counters 10 px | 129 uses of `text-[10px]` in 51 files, 2 of `text-[9px]`, 79 of `text-[11px]`. |

### Contrast

| Id | Where | Theme | Measured | Source |
| -- | ----- | ----- | -------- | ------ |
| V3 | Chat widgets, stat chips "0 Human", "0 Waiting", "Active", "0 AI", "0 Internal" | light | 2.52, 2.53, 2.91, 3.99, 4.16 : 1 | `text-green-600` / `text-amber-600` / `text-blue-600` / `text-purple-600` on their own tint |
| V3 | Chat widgets, "0 Internal" | dark | 4.47 : 1 | `dark:text-purple-400` on its tint |
| V3 | Statistics, "Active" | light | 3.20 : 1 | `text-green-600` |
| V4 | Statistics, cost budget bar "No limit" | **all three** | **2.22 : 1** | `UsageStatistics.vue:97` — `bg-green-500 … text-white` |
| V4 | Statistics, "No" | dark / OLED | 3.49 / 4.16 : 1 | `text-red-600` with no `dark:` variant (7 such places in the code) |
| V3 | Code | — | 740 uses of raw Tailwind status colours (`text-{green,red,amber,blue,purple,yellow,emerald,orange}-{400…700}`) in 125 files; 65 uses of the `--status-*-text` tokens | AGENTS.md: "never Tailwind colors directly". The tokens exist (`success`, `warning`, `error`, `neutral` — no `info`) and are barely used. |
| V5 | Every page, behind cards | dark, **OLED** | The brand bird watermark is clearly visible through translucent cards and over text | `AmbientBackground.vue:60` — the layer sits **above** content (`z-[1]`) at `opacity-[0.035]`, `dark:opacity-[0.06]`; on OLED black 6 % white reads as a grey shape. |

### Copy that shows the machinery

| Id | Where | Seen |
| -- | ----- | ---- |
| V8 | Library | A folder named `TASKPROMPT:sm…` and a file badge `TASKPROMPT:smoke-rag-2cc0c3e2` (`FilesTable.vue:141`, `FilesView.vue:931` print `file.group_key`). The file list API has no readable label for it (`FileController.php:1114`). |
| V8 | AI settings → Topics → a topic | Title "(custom) smoke-rag-2cc0c3e2 - Smoke R…"; "Link vectorized files for retrieval-augmented context"; "1 chunks · Currently linked to: TASKPROMPT:smoke-rag-2cc0c3e2"; "Used by the AI sorter"; "Danger" as a tab name |
| V8 | AI settings → Default models | "Message Sorting", "Embedding / Vectorization", "Text Analytics" as purpose labels; placeholder "-- Select Model --" |
| V9 | Library subtitle | "Files you upload become searchable knowledge for your chats. Add files to your sources…" — the last sentence is `files.introCta`, a call to action glued on as plain text (`FilesTabs.vue:38`). It reads as cut-off text and does nothing. |
| V10 | Apps | Category "E-mail" next to the card "Email to Synaplan" |
| V7 | Everywhere | Title Case and sentence case side by side: "Default Model Configuration" over "Default models"; "Select Files" next to "New folder"; "Sign In" / "Sign Up" next to "Forgot password?". A rough count finds **~755** English labels in Title Case (some are product names and stay). |

### Stability

| Id | Where | Seen |
| -- | ----- | ---- |
| V11 | Guided tours | The Tasks tour ("Create a task · 1 of 2") opened on `/tasks`, then **stayed open** on Assistants, Library and Apps: page dimmed (`driver-active driver-fade` on `<html>`), the popover pointing at an empty white box. `useTour.ts` `startTour()` destroys the driver instance only on close, Escape or the last step; nothing ends it on a route change. |
| V12 | People at 320 px | The search field has `min-w-[300px]` (`UsersTab.vue:5`) and ends 9 px past the screen edge. `WidgetList.vue:38` has the same pattern. |

### Structure (feeds the naming decision)

| Id | Where | Seen |
| -- | ----- | ---- |
| V13 | Rail → Assistants | The rail item, the sidebar head, the first group label and the page are all "Assistants". The section also holds **AI settings** (workspace-wide model defaults), Shortcuts, Tasks and Approvals. With `AGENTS.ENABLED` off the "Assistants" section contains no Assistants page. |
| V13 | Apps | "assistant" means three things on one screen: the widget's AI ("Put an AI assistant on your website"), Synaplan in general ("your assistant in Outlook"), and the built objects ("Use your assistants inside Nextcloud"). |

The naming findings in full are in [`02_naming.md`](./02_naming.md).

## 4. Census (baselines for the ratchets)

Taken on `main` at `a9f75713b`, `frontend/src`, `*.vue` unless noted.

| What | Count | Command |
| ---- | ----- | ------- |
| `text-[10px]` | 129 in 51 files | `rg -o 'text-\[10px\]' -g '*.vue' \| wc -l` |
| `text-[9px]` | 2 | same pattern |
| `text-[11px]` | 79 | same pattern |
| Raw status colours | 740 in 125 files | `rg -o 'text-(green\|red\|amber\|blue\|purple\|yellow\|emerald\|orange)-[4-7]00' -g '*.vue'` |
| `--status-*-text` token uses | 65 | `rg -o 'status-(success\|warning\|danger\|neutral\|info)-text'` |
| `text-red-600` without a `dark:` variant | 7 | `rg 'text-red-600' -g '*.vue' \| rg -v 'dark:text-red'` |
| `h1` sizes / `h2` sizes / `h3` sizes | 6 / 6 / 6 | class census of `<h1 class=…>` etc. |
| `PageHeader` users | 34 | `rg -l '<PageHeader'` |
| English labels in Title Case (2–6 words, no final punctuation) | ~755 | script in STATUS.md, 2026-10-23 |
| English strings with "assistant(s)" / "agent(s)" | 189 / 26 | `rg -i -c` over `i18n/locales/en/` |

## 5. What the walk did not cover

German, Spanish, French and Turkish were not walked on screen (the naming
map in `02` reads their files). The widget (visitor side), the mobile app
shell and the Outlook add-in were not opened. Every step that changes copy
walks its surface in German as well (the longest labels).
