# Community test round on 5.2.0 — triage, fixing order, issue drafts

**Status:** waves 1 and 2 are shipped (issues 01–15). In wave 3, the
assistant-pin bug (issue 20) is shipped. Issues 16–24 are implemented on
PR #2385 and are **not** on `main` until that PR merges — do not redo them.
An extra release (OLED black, the model-import filter, and the remaining
bugs that are not in #2385) is on branch `cursor/oled-bugs-filter-00d7`.
What that branch actually finishes is listed in §0. This folder remains the triage of record for the external
hands-on test of Synaplan 5.2.0 (tested 2026-10-05/06 against Open WebUI
0.11.4, same model and files in both apps). Two documents were delivered:
*Synaplan 5.2.0 — Test Findings* (F1–F49, grouped by impact) and
*Synaplan 5.2.0 vs Open WebUI 0.11.4 — Comparison*. Both are on file with
the product owner; this folder does not copy them because they name the
testers' infrastructure.
**Owner:** product owner.
**Code baseline:** `main` at `dbffbfd65` (2026-10-07), which includes
#2377, #2379, #2380, #2382, #2383, the triage update (#2384) and the 5.3.0
install note (#2373).
The testers ran 5.2.0. Shipped work is marked below; do not re-implement it.
**Binding UX:** [`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md)
(U1–U12) and the AGENTS.md Perfect-UX bar apply to every user-visible
issue in [`issues/`](./issues/). Each UI issue names its journey.

The testers' own verdict, kept here because the order below follows it:
the 5.2.0 rework is a big step forward; tool approvals with read / change /
delete classes, the folder sharing levels, the honest web page reader and
File work on a real CSV all worked. The gaps they named were concentrated
in four places: assistants cannot be grounded in Library files, large
uploads blow PHP memory, knowledge answers cannot be checked, and a
default self-host install dead-ends new accounts. Those four are the
work in waves 1 and 2, and they shipped on 2026-10-07.

## 0. Shipped since the triage was written

`create-issues.sh` skips any draft whose header says `<!-- status: shipped -->`.

| Issues | PR | What landed |
| ------ | -- | ----------- |
| 01–07 | [#2379](https://github.com/metadist/synaplan/pull/2379) `c97e79144` | Folder ids with spaces and non-ASCII save. An assistant searches its own files, and a greeting does not. Add a file from the Library. Large uploads chunk in windows and can be cancelled. Admins can add a user, mark verified, and resend. Sign-up says when mail is not configured. |
| 12 (core) | [#2377](https://github.com/metadist/synaplan/pull/2377) `a68637c33` | A reportable MCP failure keeps the answer step. A plain `NodeResult::failed` still skips dependents. |
| 08–14 | [#2380](https://github.com/metadist/synaplan/pull/2380) `c5b6f22b5` | File-work token from `COMPOSE_PROFILES` in `deploy/.env`, including `${VAR}`, without sourcing the file. Knowledge sources with the passage loaded on open. Expandable task steps. A follow-up mounts a named file from this chat. Approval cards show the request and who decided. PDF edits are offered to File work; the sandbox still cannot change PDF text in place, and the reply says so. No PDF library was added. |
| 15 | [#2382](https://github.com/metadist/synaplan/pull/2382) `eb02310a4` | OpenRouter import stores `pricing.prompt` / `pricing.completion` as USD per 1M tokens. A listing with no price stays selectable and says "Price unknown", not Free. A published 0 is still Free. `showWhenFree` stays on. Re-import does not overwrite a price an admin typed. `listModelIds()` stays id-only. Mail, WhatsApp, Telegram and the routing step keep the unknown-price flag. The EUR formatter is still issue 43. |
| 20 | [#2383](https://github.com/metadist/synaplan/pull/2383) `cc075dcfa` | New-chat entry points drop `?agentId=`. The banner button opens an empty chat first, then clears the pin, because messages on the old thread still name the assistant. The close icon is on that button. The model chip still lists models only; an Assistants section is issue 22. |
| 28, 36, 42, 43, 47, 49, 50, plus parts of 26, 29 and 35 | branch `cursor/oled-bugs-filter-00d7` | OLED black is a fourth appearance choice (System still resolves to Dark). The model import list filters by name or id. Ollama listing is skipped when the base URL is empty. Admin copy no longer shows column names; a member's role can change without remove and re-add; Routing opens task prompts on a route the assistants guard does not steal. The usage meter shows US dollars. The logo goes home. Generated file names drop the run id, and an image result is shown inline. An untouched new assistant is deleted on leave, and a draft can be shared. Try it shows the masked request and the response body. |

**Next open work** depends on #2385. If that PR is still open, do not start
16–24 again. If it has merged, the next run starts at the leftover bugs and
the open features below.

Still open after the OLED / remaining-bugs branch:

- **26** — Try it now shows the masked request and the response body, and OpenAPI import keeps `description` (a marked POST can stay `read`). The import wizard still does not let the admin change the risk class before import.
- **29** — Generated file names no longer include the run id, and an image result renders inline (preview, then download). A table is still plain text, stdout can still repeat the data, the fast classifier can still say "image request", and Library times are unchanged.
- **35** — The import dialog has a name/id filter, and Select all applies to the visible rows. New rows are still pre-selected. Probe results are unchanged.
- Features still open: 16–19 and 21–23 (only if #2385 has not merged), then 25, 27, 30–34, 37–41, 44–46, 48. Issue 24 is a bug inside #2385.

---

## 1. How the findings were sorted

Four questions, in this order, decide the tier of every finding:

1. **Does it block a core job on a default install?** (an assistant that
   cannot read its files, an upload that 500s, a sign-up nobody can finish,
   a documented setup that crash-loops). Those are tier 1 — `prio:1`.
2. **Does it make a correct-looking answer or run unverifiable?**
   (no citations, no tool input / output, a silent fallback, a "Free" badge
   on a paid model). Trust gaps are tier 2 — `prio:1` or `prio:2`.
3. **Is it an everyday action users coming from another AI chat app miss
   first?** (edit-and-rerun, export, preview, artifacts). Tier 3 — `prio:2`.
4. **Everything else by breadth × cheapness** — admin, models, setup,
   readability, polish. `prio:2` / `prio:3`.

Within a tier, cheaper and foundational first (a fix another issue builds
on moves up). Effort is an estimate from reading the code, not a promise:
**S** = one focused PR, **M** = one PR with backend + frontend + tests,
**L** = its own sprint file.

Items that already moved on `main` since 5.2.0 or that are only partly
reproducible are listed in §4, not turned into issues blindly.

---

## 2. Fixing order — six waves

Waves are a dependency order, not a calendar. Waves 1 and 2, including
issue 15, are on `main`. Wave 3 can start at issue 16.

| Wave | Theme | Issues (see [`issues/`](./issues/)) | Findings | Effort | State |
| ---- | ----- | ----------------------------------- | -------- | ------ | ----- |
| **1** | **Unblock the core jobs** — knowledge in assistants, big uploads, accounts, compute setup | 01 · 02 · 03 · 04 · 05 · 06 · 07 · 08 | F44 F46 F47 F13 F14 F24 | 01 S · 02 M · 03 M · 04 M · 05 S · 06 S · 07 S · 08 S | **Shipped** #2379 (01–07) and #2380 (08) |
| **2** | **Make answers and runs checkable** — citations, step detail, honest failures, real prices | 09 · 10 · 11 · 12 · 13 · 14 · 15 | F40 F9 F26 F43(4,6) F35 F48 F2 F16 | 09 M · 10 M · 11 M · 12 S · 13 M · 14 M · 15 S | **Shipped** (#2377, #2380, #2382) |
| **3** | **Everyday chat actions** — edit/rerun, artifacts, export, prompts, previews, composer context | 16 · 17 · 18 · 19 · 20 · 21 · 22 · 23 · 24 | F36 F37 F38 F45 F32 F7 F17 F31 F41(reload) | 16 M · 17 M · 18 M · 19 M · 20 S · 21 M · 22 S · 23 L · 24 S | **20 shipped** (#2383). **16–19 and 21–24 are in #2385, not on main** |
| **4** | **Builders, tools, files** — custom tool form and visibility, builder gaps, File work results, Library | 25 · 26 · 27 · 28 · 29 · 30 · 31 · 32 · 33 · 34 | F43(1–3,5) F41 F42(drafts) F25 F30 F27 F49 F39 | 25 M · 26 M · 27 S · 28 S · 29 M · 30 S · 31 M · 32 M · 33 M · 34 S | **28 shipped** on the OLED branch. **26 and 29 partly done** (see §0). The rest is open |
| **5** | **Admin, models, setup** — import dialog, embedding status, CA trust, impersonation lock, sharing for tools/MCP, policies | 35 · 36 · 37 · 38 · 39 · 40 · 41 · 42 · 43 · 44 · 45 | F4 F5 F18 F19 F28 F21 F29 F42 F22 F23 F12 F8 F20 F1 F46(noise) | 35 M · 36 S · 37 M · 38 M · 39 M · 40 M · 41 L · 42 S · 43 S · 44 M · 45 M | **36, 42, 43 shipped** on the OLED branch. **35 has the filter only** |
| **6** | **Readability, accessibility, polish** — UI scale, OLED theme, shortcuts, layout, docs | 46 · 47 · 48 · 49 · 50 | F34 F10 F33 F6 F15 F3 + OLED request | 46 M · 47 S · 48 S · 49 S · 50 S | **47, 49, 50 shipped** on the OLED branch. 46 and 48 stay open |

**Why this order and not the testers' list.** Their ten priorities are
kept almost one-to-one (their 1–3 are wave 1–2, 4 is wave 2, 5–6 are
waves 3 and 6, 7 is split across waves 2 and 4, 8 is wave 5, 10 is wave 5).
Two deliberate moves: **prices (F16) go up** to wave 2 because a "Free"
badge on a billed model is a money-correctness bug, not a polish item; and
**Open Terminal (their 9) is not an issue** — it is a product decision and
a sprint of its own, recorded in §6.

**Next PR:** if #2385 is still open, leave 16–24 alone. Otherwise start at
issue 25, then 27, 30–34. Do not reopen 26, 29 or 35 except for the leftovers
named in §0. Issue 23 stays inside #2385.

## 2.1 Guards for whoever implements these

Corrected on review. A coding session that follows the first draft
literally would ship one of these:

| Issue | Do not |
| ----- | ------ |
| 01 | Replace `FOLDER_PATTERN` with `^\d+:.+$`. Reject control characters and keys longer than `BGROUPKEY` (128). Existing valid ids must still load. |
| 02 | Run retrieval on every assistant turn. "Hello" stays a normal chat. Do not give the draft panel tool side effects. Do not strip `<think>` out of user text. |
| 04 | Call `processFile()` and call it a background job. It runs in the request and will OOM the same way. Do not content-hash-dedupe two different files. Do not drop chunk overlap. Partial indexing stays off unless an admin opts in. |
| 05 | Rebuild abort in the file picker; it already has an `AbortController`. Aborting the browser does not stop PHP. |
| 08 | `source deploy/.env`. Write `COMPUTE_TOKEN` into `deploy/.env` or `secrets.env`. Call `docker compose config --profiles` (it lists every declared profile, including `compute` when File work is off) or `docker compose config` from `ensure_compute_token` (the lifecycle contract records every docker invocation). The token file is the source of truth. |
| 09 | Store chunk bodies on the message and in the SSE event. Store ids; load the passage on open. |
| 11 | Mount every earlier file into every later sandbox run. Turn `useWorkspace` on by default — that folder is shared across chats. |
| 12 | Return `NodeResult::failed` for a tool error or an empty body. That skips the answer step. Use `reportableFailure`. Keep `failed` for a disabled tool, missing params, a disallowed topic, a hallucinated tool, and a mutating tool. |
| 15 | Clear `showWhenFree` on priceless imports. That hides Ollama rows from the model menu (#2110). Do not overwrite a price an admin typed. Do not change `listModelIds()`'s return type. A missing price is "Price unknown", not Free. A turn with any unpriced step must not show a partial cost as the whole cost. |
| 20 | Only delete `?agentId=` while the open thread's messages still name the assistant. That leaves the banner and the next send pinned. Open an empty chat first, then drop the query. The Assistants section on the model chip is issue 22. |
| 32 | Unpack zip/tar during indexing. Do not remove an extension that is allowed today. |
| 33 | Raise `MAX_TEXT_LENGTH` for every URL mention. Leave the private-address refusal alone. |
| 34 | Say "the planner adds a follow-up after search". The DAG is fixed before search runs. Evaluate quality inside the search runner, plan one optional retry up front, or add real replanning. Pick one before coding. |
| 38 | Install the extra CA into the OS trust store (that covers billing, OIDC, and mail). Do not add "skip TLS verification". Do not pass a private-only PEM as `cafile`: that replaces the public root bundle and breaks public endpoints. Build a combined bundle (public roots plus the extra CA) for the scoped clients only. |
| 41 | Default a new chat-action policy to off. Missing row = today's behavior. |
| 44 | Hide negative model ids whenever `APP_ENV !== 'dev'`. That also hides the test fixtures `ModelSeeder` inserts for E2E. Filter production only (`APP_ENV === 'prod'`). |
| 45 | Apply the MCP allowlist to the page reader. Check the IP at connect time, not only at save time. |

---

## 3. Finding → issue map

Every F-number from the findings document, plus the two requests from the
cover note, with its destination.

| Finding | Tier | Destination | Note |
| ------- | ---- | ----------- | ---- |
| F1 MCP networking (SSRF blocks same-network MCP) | 5 | [45](./issues/45-mcp-trusted-local-allowlist.md) | Already raised with Synaplan directly; pairs with CA trust (38) |
| F2 MCP error path drops the plan | 2 | [12](./issues/12-mcp-failed-step-hides-error.md) | Success path confirmed fixed by testers |
| F3 Docs path for Synaplan Desktop | 6 | [50](./issues/50-docs-desktop-path-and-rail-label.md) | Partly addressed on `main` by #2359 (5.2.1) — verify the label |
| F4 Import dialog: no search / filter / sort | 5 | [35](./issues/35-models-import-dialog-search-filter-nothing-preselected.md) | |
| F5 Capability probe results vanish | 5 | [35](./issues/35-models-import-dialog-search-filter-nothing-preselected.md) | |
| F6 Logo not clickable | 6 | [49](./issues/49-layout-logo-home-and-850px-clipping.md) | |
| F7 New-chat screen gives little context | 3 | [22](./issues/22-chat-new-chat-screen-context-and-promotions.md) | Partly addressed in 5.2.0 (model chip) |
| F8 "Yours today", EUR vs USD, locale | 5 | [43](./issues/43-billing-currency-independent-of-language.md) + [42](./issues/42-admin-tooltips-and-plain-labels-sweep.md) | Label in 42, currency logic in 43 |
| F9 Task cards hide tool input / output | 2 | [10](./issues/10-chat-expandable-task-steps-with-tool-io.md) | |
| F10 Small type on large displays | 6 | [46](./issues/46-settings-ui-scale-text-size-accessibility.md) | Gap is the missing scale setting |
| F11 Quick Model Setup clipping | — | none | Not found in 5.2.0 by the testers; close |
| F12 Env-locked settings cannot be changed | 5 | [42](./issues/42-admin-tooltips-and-plain-labels-sweep.md) | Explain where to unlock, not a UI override |
| F13 Sign-up with null mail transport | 1 | [07](./issues/07-auth-signup-dead-ends-with-null-mail-transport.md) | |
| F14 Admin cannot create / verify users | 1 | [06](./issues/06-admin-add-user-mark-verified-resend.md) | Endpoint exists, UI does not call it |
| F15 Clipping at ~850 px | 6 | [49](./issues/49-layout-logo-home-and-850px-clipping.md) | U9 |
| F16 OpenRouter models imported without prices | 2 | [15](./issues/15-models-openrouter-import-prices-zero-free-badge.md) | Shipped in #2382. EUR display is still issue 43. |
| F17 Promotional cards on new chat | 3 | [22](./issues/22-chat-new-chat-screen-context-and-promotions.md) | |
| F18 "Select all new" pre-ticked (Import 461) | 5 | [35](./issues/35-models-import-dialog-search-filter-nothing-preselected.md) | |
| F19 Three screens disagree on the embedding model | 5 | [37](./issues/37-models-embedding-status-card-and-setup-polish.md) | |
| F20 Model setup split across Admin and Assistants | 5 | [44](./issues/44-nav-models-area-and-stub-models.md) | Cross-ref nav consolidation |
| F21 Internal CA not trusted | 5 | [38](./issues/38-setup-extra-trusted-ca-certificates.md) | Also covers MCP, WebDAV, webhooks |
| F22 Group role only at add time; "Manual" pills | 5 | [42](./issues/42-admin-tooltips-and-plain-labels-sweep.md) | |
| F23 Tooltips, DB column names in Edit Models | 5 | [42](./issues/42-admin-tooltips-and-plain-labels-sweep.md) | |
| F24 Compute sidecar crash-loops after deploy/README | 1 | [08](./issues/08-deploy-compute-token-ignores-env-profiles.md) | Shipped in #2380. Token is `data/compute.token`; the env file is not sourced. |
| F25 File work results not inline, Preview downloads | 4 | [29](./issues/29-file-work-results-inline-preview-names.md) | |
| F26 PDF edit refused; step detail missing | 2 | [14](./issues/14-file-work-offer-for-attached-file-edits-pdf-library.md) + [10](./issues/10-chat-expandable-task-steps-with-tool-io.md) | |
| F27 Fixed allowed-extensions list and 128 MB | 4 | [32](./issues/32-files-admin-configurable-types-size-count.md) | |
| F28 Embedding setup rough edges | 5 | [37](./issues/37-models-embedding-status-card-and-setup-polish.md) | |
| F29 Impersonation cannot be locked | 5 | [39](./issues/39-admin-impersonation-env-lock-and-honest-dialog.md) | `envOverride` pattern exists for REGISTRATION_ENABLED |
| F30 Library polish | 4 | [31](./issues/31-library-polish-legend-times-columns-index-details.md) | |
| F31 Clarifying questions as plain text | 3 | [23](./issues/23-chat-ask-the-user-step.md) | L — own sprint file before coding |
| F32 No file preview in chat | 3 | [21](./issues/21-chat-file-chip-preview-download-reattach.md) | |
| F33 No shortcuts list | 6 | [48](./issues/48-chat-keyboard-shortcuts-sheet.md) | |
| F34 No accessibility / text-size setting | 6 | [46](./issues/46-settings-ui-scale-text-size-accessibility.md) | |
| F35 Approval card: summary only, reply sent early | 2 | [13](./issues/13-approvals-exact-request-hold-reply-who-when.md) | |
| F36 No edit-and-rerun, no version switch | 3 | [16](./issues/16-chat-edit-and-rerun-version-arrows.md) + [24](./issues/24-chat-small-fixes-reload-header-escape-enhance.md) | Escape bug in 24 |
| F37 No artifacts preview | 3 | [17](./issues/17-chat-artifacts-live-preview.md) | |
| F38 No export / archive / tags / prompt library | 3 | [18](./issues/18-chat-export-archive-tags.md) + [19](./issues/19-prompts-prompt-library-on-slash.md) + [24](./issues/24-chat-small-fixes-reload-header-escape-enhance.md) | Enhance feedback in 24 |
| F39 Web search: stale page, no follow-up search | 4 | [34](./issues/34-web-search-follow-up-and-current-date.md) | |
| F40 No citations; folder setup friction | 2 / 4 | [09](./issues/09-rag-citations-with-matched-passage.md) + [30](./issues/30-library-bulk-move-and-server-side-folders.md) + [22](./issues/22-chat-new-chat-screen-context-and-promotions.md) | Active-folder chip in 22 |
| F41 Builder gaps | 3 / 4 | [27](./issues/27-assistants-attach-custom-http-tools.md) + [28](./issues/28-assistants-draft-on-first-save-and-share-draft.md) + [24](./issues/24-chat-small-fixes-reload-header-escape-enhance.md) + [42](./issues/42-admin-tooltips-and-plain-labels-sweep.md) | Task Prompts link in 42; "Plugins page" needs a product answer (§6) |
| F42 Access control gaps | 4 / 5 | [40](./issues/40-iam-share-custom-tools-and-mcp-servers.md) + [41](./issues/41-iam-group-policies-for-chat-actions.md) + [28](./issues/28-assistants-draft-on-first-save-and-share-draft.md) + [19](./issues/19-prompts-prompt-library-on-slash.md) | Tool share is already roadmap row 1 |
| F43 Custom tool builder gaps | 2 / 4 | [25](./issues/25-custom-tools-arguments-credentials-template-hints.md) + [26](./issues/26-custom-tools-show-request-response-openapi-import.md) + [10](./issues/10-chat-expandable-task-steps-with-tool-io.md) | |
| F44 Assistants cannot use Library files | 1 | [01](./issues/01-assistants-knowledge-folders-save-fails.md) + [02](./issues/02-assistants-own-files-and-draft-panel-skip-retrieval.md) + [03](./issues/03-assistants-add-file-from-library.md) | |
| F45 Pinned assistant banner sticks | 3 | [20](./issues/20-chat-pinned-assistant-banner-sticks.md) | Pin and banner shipped in #2383. Assistants section on the model chip is issue 22. |
| F46 78 MB CSV exhausts PHP memory | 1 | [04](./issues/04-files-large-upload-oom-in-request.md) + [36](./issues/36-models-skip-ollama-listing-without-base-url.md) | Log noise split out |
| F47 Upload cannot be cancelled | 1 | [05](./issues/05-files-upload-cannot-be-cancelled.md) | |
| F48 File work follow-up cannot see earlier result | 2 | [11](./issues/11-file-work-follow-up-cannot-use-earlier-result.md) | |
| F49 Web page reader: PDF, JS, long pages | 4 | [33](./issues/33-web-page-reader-pdf-js-long-pages.md) | |
| Cover note: "true OLED black or dark grey mode" | 6 | [47](./issues/47-theme-oled-black-variant.md) | |
| Comparison: Open Terminal | — | §6 roadmap candidate | Product decision first |
| Comparison: pending-approval sign-up mode | 1 | [07](./issues/07-auth-signup-dead-ends-with-null-mail-transport.md) (follow-up note) | |

---

## 4. What `main` already changed since 5.2.0

Checked against `git log v5.2.0..HEAD` on 2026-10-07, after #2377, #2379,
#2380, #2382 and #2383. v5.2.1 and v5.3.0 were tagged 2026-10-05/06; the
finding fixes landed after those tags.

| Finding | Commit | Effect on the issue |
| ------- | ------ | ------------------- |
| F44, F46, F47, F13, F14 | #2379 | Issues 01–07. Shipped. |
| F2 | #2377, then #2380 | Issue 12. A reportable MCP failure keeps the answer. Shipped. |
| F24, F40, F9, F26, F43 (label and error), F35, F48 | #2380 | Issues 08–11, 13, 14. Shipped. PDF text still cannot be edited in place; the reply says so. PyMuPDF was not added. |
| F3 docs path | #2359 (5.2.1) | Docs now say **Manage → Channels → Synaplan Desktop**. Issue 50 is still a verification task, not a rewrite. |
| F16 prices | #2382 | Issue 15. Shipped. Unknown price is not Free. EUR display remains issue 43. |
| F45 pinned assistant | #2383 | Issue 20. A new chat drops the pin. The banner leaves the assistant thread before clearing it. The model chip's Assistants section remains issue 22. |
| F7 composer context | 5.2.0, plus the knowledge-folder chip in #2380 | Model chip shipped in 5.2.0. The active knowledge folder is now on the composer. Tools, assistant and promotions remain issue 22. |
| F11 | — | Not reproducible on 5.2.0 per the testers. No issue. |

Waves 3–6 are not all open. Issue 20 is on main. Issues 16–24 are in #2385.
Issues 28, 36, 42, 43, 47, 49 and 50 are on the OLED branch. Issues 26, 29
and 35 are only partly done there. The sentence above this table is the
historical snapshot; the wave table in §2 is the current state.

---

## 5. Verified in the code (2026-10-07, before waves 1 and 2)

Snapshot of the 5.2.0 / early-5.3.0 code the drafts were written against.
**Do not treat the F44, F46, F13, F14, F16, F24 and F45 rows as the current code.**
Those bugs shipped in #2379, #2380, #2382 and #2383. The rows stay so the next reader
can see what was wrong. Each open issue still repeats the pointers it needs.

| Finding | What the code shows | File |
| ------- | ------------------- | ---- |
| F44 (1) | Folder ids are validated against `/^\d+:[A-Za-z0-9:_.@+-]+$/`. The frontend emits `${ownerId}:${group.name}`; a folder name with a space, umlaut or other character outside that class fails with exactly the reported message. `KnowledgeFolderKind::parseId()` itself accepts any non-empty group key. | `backend/src/Service/Agent/Definition/AgentDefinitionValidator.php` (`FOLDER_PATTERN`, `stringList()`), `frontend/src/components/assistants/BuilderKnowledge.vue` (`loadFolderOptions`) |
| F44 (2) | `BuilderKnowledge.vue` `onUpload` posts a browser `File` to `promptsApi.uploadPromptFile`; there is no Library picker path. | `frontend/src/components/assistants/BuilderKnowledge.vue` |
| F44 (3) | `ChatHandler::agentRagScopes()` adds `new RagScope($viewerId, null)` when `includeUserFiles` is on, so the scope is built. Whether retrieval runs at all for the turn is decided upstream (classifier / fast path); the 3 s, 625-token answer says it did not. | `backend/src/Service/Message/Handler/ChatHandler.php`, `backend/src/Service/Agent/AgentRuntimeResolver.php` |
| F46 | Library uploads use `process_level=vectorize` (`frontend/src/views/FilesView.vue`), so chunking and embedding run inside that PHP request. `TextChunker::chunk()` holds every line and every chunk. `processFile()` is not a worker: `POST /api/v1/files/{id}/process` calls it in the request. The picker uses `process_level=store` and does not OOM this way. | `frontend/src/views/FilesView.vue`, `backend/src/Controller/FileController.php`, `backend/src/Service/File/FileUploadService.php`, `backend/src/Service/File/TextChunker.php` |
| F27 | `FileStorageService::MAX_FILE_SIZE = 128 MB` and `ALLOWED_EXTENSIONS` are constants. | `backend/src/Service/File/FileStorageService.php` |
| F13 | `MailerConfig::isConfigured()` already knows `null://null` means nothing is delivered and is used by `SetupController`, `ConfigController` and `PlatformCapabilityInventory` — but not by registration, and `InternalEmailService` logs "Verification email sent" regardless. | `backend/src/Service/MailerConfig.php`, `backend/src/Service/InternalEmailService.php`, `backend/src/Controller/AuthController.php` |
| F14 | `POST /api/v1/admin/users` exists (`AdminUserProvisioningController`); `frontend/src/services/api/adminApi.ts` only calls search, list, level and delete. | `backend/src/Controller/AdminUserProvisioningController.php`, `frontend/src/services/api/adminApi.ts` |
| F24 | `ensure_compute_token()` returns early unless `,${COMPOSE_PROFILES:-},` contains `compute`; it never sources `deploy/.env`. | `deploy/scripts/lib.sh` |
| F29 | `IAM_ADMIN_IMPERSONATION` is a database-backed select (`audited` / `disabled`). The env-lock pattern already exists: `SystemConfigService` marks `REGISTRATION_ENABLED` and `GUEST_CHAT_ENABLED` with `envOverride` when the env sets them. | `backend/src/Service/Admin/SystemConfigService.php` |
| F21 | No `cafile`, `capath`, `EXTRA_CA_*` or per-endpoint TLS option anywhere in `backend/src`, `backend/config`, `deploy/compose.yaml` or `deploy/selfhost.env.example`. | — |
| F13 default | `deploy/selfhost.env.example` ships `MAILER_DSN=null://null` and `APP_SENDER_EMAIL=`. | `deploy/selfhost.env.example` |

Not verified in code when this triage was written (hands-on only): F39
and F49. F16 was checked while shipping #2382. F2, F35, F40, F45 and F48
were checked while shipping #2377, #2380 and #2383. Those are closed.

---

## 6. Not turned into an issue — needs a product answer first

| Topic | From | Why it waits |
| ----- | ---- | ------------ |
| **Open Terminal** (terminal server connections per group, docked terminal, inline previews of agent-made files) | Comparison §"Open Terminal", testers' priority 9 | Large; overlaps File work B4 and the desktop client. Decide whether a terminal mode is built on File work governance (isolation tiers, caps, ask-first, approved egress) or stays out of scope. If yes, it gets a sprint file under `_devextras/planning/`, not an issue. |
| **Pending-approval sign-up mode** (no mail: admin approves instead) | Comparison §"Account creation" | Issues 06 + 07 remove the dead end. Whether an "admin approves" mode is wanted in addition is a policy choice; recorded as a follow-up note in issue 07. |
| **Docling on by default** for document-heavy installs | Findings §"Questions" | Resource and image-size trade-off; belongs in the deploy profile discussion, not a bug. |
| **A Plugins page** ("From plugins" tab on Assistants, no page to manage plugins) | F41 | Needs the plugin story first; the tab alone is not enough to specify a page. |
| **More web search providers / extraction engines** | Comparison §"Pluggable infrastructure" | Six adapters + Tika + Docling cover the tested cases; quality (issue 34) matters more than count. |
| **Chat folders** | Comparison §"Chat experience" | Tags + archive (issue 18) first; folders only if tags prove insufficient. |

### Draft answers to the testers' open questions

Short, honest, for the product owner to adapt:

- *knowledge.folders error known? Should assistants use Library files directly?* — Fixed in #2379. Folder names with spaces and non-ASCII save (issue 01). Picking Library files directly shipped with issue 03.
- *Is indexing meant to run inside the upload request?* — Library uploads no longer hold the whole file in one request (#2379, issue 04). An upload can be cancelled (issue 05).
- *Should File work see files generated earlier in the chat?* — Yes. #2380 mounts a file this chat already saved when the person or the planner names it (issue 11). It does not mount every earlier file, and it does not turn the shared workspace on by default.
- *Page reader: Library extraction for PDF links, Firecrawl for JS, length limit?* — Issue 33 asks exactly that; the limit will be shown in the step.
- *Custom HTTP tools: response visibility, credentials, template syntax docs?* — Issues 25 and 26.
- *Failed MCP steps keep the answer step and show the error?* — Shipped. #2377 keeps the answer on a reportable failure. #2380 logs server, tool and status (issue 12).
- *Trusted-local MCP allowlist or STDIO?* — Issue 45; pairs with CA trust (issue 38).
- *USD in README vs EUR in UI?* — Issue 43 has to answer how the EUR figure is derived before changing display. OpenRouter prices themselves shipped in #2382 as USD per 1M tokens.
- *Expandable tool input / output / timing / errors on task cards?* — Shipped in #2380 (issue 10). The expanded step shows the resolved inputs, a capped output, status and duration.
- *File work on attached files; PyMuPDF in the sandbox?* — Routing shipped in #2380 (issue 14). The sandbox still cannot change PDF text in place, and the reply says so. PyMuPDF was not added; that remains an ask-first dependency.
- *Installer writes COMPUTE_URL / COMPUTE_TOKEN?* — Shipped in #2380 (issue 08). The token is written to `data/compute.token`, not into `.env`. `${VAR}` in `COMPOSE_PROFILES` is expanded. The file is not sourced. `docker compose config --profiles` is the wrong detector: it lists every declared profile, including `compute`, even when File work is off.
- *Extra trusted CA?* — Issue 38, smallest form first (`EXTRA_CA_CERTS` PEM path + compose mount).
- *Import dialog search, cached probes, prices?* — Prices shipped in #2382 (issue 15). Search, filter and cached probes are still issue 35.
- *Docling default?* — Open (§6).
- *What is planned for later parts of the UI rework?* — Point them at [`../20260925_roadmap.md`](../20260925_roadmap.md) §1 and [`../20260914-navigation-consolidation/`](../20260914-navigation-consolidation/).

---

## 7. Creating the issues

Each file in [`issues/`](./issues/) is one GitHub issue in the repository's
template shape (`.github/ISSUE_TEMPLATE/bug.md` / `feature.md`), with
title, type and labels in the header HTML comments, plus
`<!-- status: shipped -->` once the work has merged. Labels used:
`prio:1` / `prio:2` / `prio:3` and the existing `area:*` set; nothing new
has to be created.

```bash
# prints what would be created; shipped drafts are listed and skipped
_devextras/planning/20261007-community-test-findings-5.2.0/create-issues.sh

# creates every open issue that does not already exist (matched by exact title)
_devextras/planning/20261007-community-test-findings-5.2.0/create-issues.sh --create

# wave 3 only (issues 01–14 are shipped and skipped even if the glob matches)
_devextras/planning/20261007-community-test-findings-5.2.0/create-issues.sh --create --only '1[6-9]'
```

The script needs a `gh` login with write access to issues; the read-only
token used by cloud agents cannot create them, which is why the drafts
live here. A draft with `<!-- status: shipped -->` in its header is never
created. After creation, add the issue numbers to the table in §3 so the
next test round can reference them.
