# Assistants → Agents

**Status:** Implementing 2026-11-09 (owner go-ahead same day). Branch
`feat/assistants-are-agents`, one PR. Inventory counted on `b8d807064`:
EN 224 strings, DE 222, ES 199, FR 223, TR 200 mention the old word.
**Owner:** product owner.
**This is UP7.** The owner already chose it on 2026-10-23
([`20261023-ux-polish-and-naming`](../20261023-ux-polish-and-naming/00_master_plan.md)
§0 D1, glossary in [`02_naming.md`](../20261023-ux-polish-and-naming/02_naming.md)
§6). It never shipped. This file is the *current* work list against
today's repo, not a new product decision.

**Commit class:** `feat(ui): assistants are called agents` — this is a
visible product rename and bumps the **minor** release (AGENTS.md).
**Binding:** UX contract U1–U12. Copy in all five locales in the same PR
as the first paint.

Neighbour: N1 (Admin, not Operate) is [#3043](https://github.com/metadist/synaplan/pull/3043).
Do not mix. N2 (sentence case) waits until this rename is on `main`, or
the two copy passes fight over the same strings.

---

## 0. What “everywhere” means — and what it must not

The built object (`BAGENTS`, the gallery, the builder, the rail item) is
**Agent / Agents**. That is the website word, the Desktop rail word, the
API word and the code word. The web app is the hold-out.

| Keep as-is | Why |
| ---------- | --- |
| Database, PHP classes, `/api/v1/agents`, `AGENTS.ENABLED`, IAM keys, i18n *file* `assistants.json`, `data-testid` values (`btn-sidebar-v2-nav-assistants`) | D7. Renaming code is a second, much larger track. |
| OpenAI-compatible alias `assistant:<slug>` and MCP tool `list_assistants` | External contracts. Clients already call them. |
| Desktop HTTP `GET /v1/assistants` | Frozen protocol with the Tauri app (`docs/DESKTOP.md`). A path change is a Desktop release, not this PR. |
| Product name **AI Setup Assistant** (widget wizard, ~41 strings) | D3. It is a helper, not something people own. |
| HTTP “user agent” | Unrelated English. |
| Planning archives under `_devextras/planning/2026-archive/` | History. |

Three collisions **must** be fixed in the same slice, or “Agent” becomes
ambiguous on day one:

1. **Sharing list** today: `resourceKind.assistant` = “AI assistant” (that
   row is a *topic*, `BPROMPTS`) and `resourceKind.agent` = “Agent” (that
   row *is* the built object). After the rename the topic row must say
   **Topic**, the object row **Agent**.
2. **“Available Agents”** over the tool list in the topic editor
   (`config.taskPrompts.availableTools`, `widgets…availableTools`) is
   tools, not agents. Becomes **Tools**.
3. **Live support** “You are now connected with a support agent” is a
   *person*. Becomes **Team** / “a person from our team” (D4). Otherwise a
   visitor cannot tell a human from an AI agent.

The AI in general is not called “assistant” and not called “agent”: copy
says **the AI** / “{brand} AI” (D2). “Put an AI assistant on your website”
→ “Put an AI chat on your website”.

German: **Agent / Agenten** (already the AI-product word). Turkish: **ajan
/ ajanlar** (already on the sharing kind; native review before merge —
*ajan* can read as “spy”).

---

## 1. Where it still says Assistant today

Counted 2026-11-09 on `main`.

| Surface | Today | After |
| ------- | ----- | ----- |
| Rail, sidebar group, page title | Assistants (`nav.assistants`, `pageTitles.assistants`) | Agents |
| Builder / gallery (`assistants.json`) | “Create assistant”, “New assistant”, empty state, ~49 English keys | Create agent, New agent, … |
| Search palette | group “AI assistants”, kind `assistant` (code stays) | group “Agents”; keyword **assistant** kept as a synonym |
| Chat picker / “talking to” | Assistants | Agents |
| Getting-started checklist | create an assistant | create an agent |
| Tours | `id: 'assistants'` stays; visible copy changes | “Agents” |
| UX contract §2 | Chats · Library · **Assistants** · Apps · Admin | **Agents** |
| `docs/*.md` in this repo | “AI assistants”, Assistants → … | Agents; click-paths match the rail |
| docs.synaplan.com | `assistants.md` | `agents.md` + redirect (other repo, UP8) |
| Desktop app strings | mixed: rail Agents, body Assistant | one word (other repo, UP8) |
| Website EN | already Agents | no change |
| Website DE | still “Assistent” in several marketing strings | Agents / Agenten, in a website PR |

E2E helpers already key off test ids (`btn-sidebar-v2-nav-assistants`).
Those stay. Specs that assert visible text (“Assistants lists…”) change.

---

## 2. Steps (one topic, one PR)

Do not combine with N1, T12/T11 search, or T15 voice.

| ID | Commit | Repo | Notes |
| -- | ------ | ---- | ----- |
| A0 | `fix(copy): topics, tools and live-support people are not agents` | synaplan | UP6 leftovers. Sharing kinds, “Available Agents” → Tools, live-support “Team”. Can merge before A1. Small, unblocks the big rename. |
| A1 | `feat(ui): assistants are called agents` | synaplan | All five locales; rail; gallery/builder/chat/search labels; UX contract §2; `docs/*.md` click-paths; `/ai/agents` + redirect from `/ai/assistants*`; Ctrl+K synonym “assistant”; wording-guard: `assistants` namespace must not contain “assistant” except “AI Setup Assistant”. |
| A2 | `docs: assistants are called agents` | synaplan-docs (if still a separate repo) or the published docs pipeline | `assistants.md` → `agents.md` + redirect. |
| A3 | `fix(i18n): one word for agents` | synaplan-desktop | Remaining “Assistant” in the desktop UI. |
| A4 | website DE | synaplan-website | Marketing strings that still say Assistent for the product. EN already Agents. |

A1 is the product change. A0 is required so A1 does not ship a sharing
list that calls a topic “AI assistant” next to an “Agent”. A2–A4 can
follow the same week; they must not contradict A1.

**Out of A1 (ask if you want them in the same PR):** D8 rail regroup
(AI settings leaves the Agents group; Shortcuts stay; Tasks/Approvals
under Automations). That is information architecture, not the rename.
Default: **rename only**, keep today’s children under the rail item.

---

## 3. A1 — work list inside synaplan

1. **Copy, five locales.** `assistants.json` values; `core.json` nav and
   pageTitles; search groups/kinds/synonyms; `chat.json`, `tools.json`,
   `files.json`, `admin.json`, `auth.json`; tours. Generic “the assistant
   can…” → “the AI” or “this agent” according to whether it means the
   built object or any model.
2. **Router.** `path: '/ai/agents'`, name can stay or become `ai-agents`.
   Redirect `/ai/assistants` and `/ai/assistants/:id` for at least two
   releases. Flag-off still 404s the surface (U11). Getting-started link
   and `AssistantsView` hard-coded `/ai/assistants` follow.
3. **Nav.** Label Agents. Description: “Agents, shortcuts, tasks and AI
   settings” (until a later D8 PR). Test ids unchanged.
4. **Docs in this repo.** `docs/UX_CONTRACT.md` §2 and the share table;
   `docs/FEATURES.md`, `PLATFORM_REFERENCE.md`, `DESKTOP.md` (prose only —
   the `/v1/assistants` path stays), `FEATURE_FLAGS.md`. Do not rewrite
   `_devextras` archives.
5. **Tests.** Every Vitest/Playwright assertion on visible “Assistant(s)”
   / “Assistent(en)”. `wordingGuard` or `localeParity`: ban “assistant”
   in `assistants.*` values except the Setup Assistant allow-list.
6. **Release note** (changelog / GitHub release when this minor lands):
   one sentence that Assistants are now Agents; old bookmarks still work.

**Gate.** `make ci-local`, `make test-e2e`, `make -C frontend test-e2e-layout`.
Walk J-UP-1 (en + de, 320 px): rail Agents → Create agent → share with a
group → other account finds it under Agents → Shared with me in ten
seconds → `/ai/assistants` redirects.

**Mobile impact.** `ota-candidate` (copy + SPA redirect). Classify in
`.github/mobile-impact-policy.json` if the path set changes.

---

## 4. Decisions to tick before A1 starts

The 2026-10-23 ticks still stand unless the owner reverses them.

- [x] D1 — User word for `BAGENTS` is **agent** (2026-10-23).
- [x] D2 — Generic AI is “the AI”, not assistant.
- [x] D3 — **AI Setup Assistant** stays.
- [x] D4 — A human in live support is never “agent”.
- [x] D7 — Code, API, test ids, `assistant:<slug>`, `list_assistants` stay.
- [ ] **D-IA.** A1 is rename-only (recommended), or A1 also does D8
      (AI settings out of the Agents group). Tick before the first UI PR.
- [ ] **D-DE/TR.** Glossary row in `02_naming.md` §6 accepted for German
      *Agenten* and Turkish *Ajanlar*.

---

## 4b. Execution checklist (2026-11-09 inventory)

Semantics per key, decided before touching values:

- **Built object** (`assistants.json`, `templates.*`, `bundle.*`, chat
  picker, approvals, mail/WhatsApp/Telegram bindings, widget
  `useAssistant.*`, IAM banner/dialog for the object) → **agent**.
- **Generic AI** (`files.*`, `admin.config.fields.*` chat behaviour,
  `mcpServers.*`, widget AI that is not a bound object, transcript role)
  → **the AI** / die KI / la IA / l'IA / yapay zekâ.
- **External gateway clients** (`messagesGateway.*`) → **AI tools**
  (matches `nav.aiAgents` “Claude Code & coding tools”).
- **Widget visitor copy** → “an AI”, never “an agent” (no object there).
- **AI Setup Assistant** keeps its name everywhere.
- Synonyms in `core.json` keep the old word as a search synonym.

Concrete code moves:

- [x] Router: `/ai/agents`, `/ai/agents/:id` canonical;
      `/ai/assistants*` redirects to them (≥ 2 releases). Route names,
      testids, i18n file names unchanged.
- [x] Internal links: `useNavItems`, `GettingStartedChecklist`,
      `AssistantsView`, `ApprovalsInbox`, search registry if it lists
      the path.
- [x] A0 values: `people.groups.resourceKind.assistant` and
      `iam.kind.assistant` → Topic; `config.taskPrompts.availableTools`
      + `widgets…availableTools` → Tools; `takeoverNotice` (i18n ×2,
      `HumanTakeoverService`, `WidgetSessionsView` fallback) → a person
      from the team.
- [x] Backend user-visible: OpenAPI descriptions that name the object,
      `Agent*Exception` messages, `FeaturesTab` routing hint.
      Not: class names, `assistant:<slug>`, `list_assistants`,
      Desktop `/v1/assistants`, LLM few-shot prompts.
- [x] Docs: `docs/UX_CONTRACT.md` §2 + share table, `FEATURES.md`,
      `PLATFORM_REFERENCE.md`, `DESKTOP.md` prose, `FEATURE_FLAGS.md`.
- [x] Tests: `useNavItems`/`useNavSections` specs, `wordingGuard`
      (ban `\bassistants?\b` in nav/pageTitles/apps/tours; ban it in
      `assistants.*` values except the `assistant:{slug}` literal),
      `i18n-namespaces.spec.ts` (path + “Agenten”), any visible-text
      assertions. Placeholders and plural pipes untouched.
- [x] Gate (local, on the stale docs-branch stack): full Vitest
      3536/3537 (only pre-existing `useMidnightNow` failure, also red
      on `main`), ESLint clean, PHP lint clean. Browser walk: rail
      **Agents**, panel “Agents, shortcuts, tasks and AI settings”.
      Gallery page and full Playwright need the coherent
      origin/main stack — they run in CI on this PR.
- [x] Mobile impact: `ota-candidate` (copy + SPA redirect only).

Follow-ups outside this PR (own repos): docs.synaplan.com page move,
Desktop strings, website DE marketing strings.

## 5. What this is not

- Not a builder redesign (that is
  [`20261106-assistant-onboarding-ux`](../20261106-assistant-onboarding-ux/00_master_plan.md)).
- Not N1 Admin, not search Apps, not live-voice send-slot.
- Not renaming `/api/v1/agents` or `BAGENTS`.
- Not rewriting the website EN Agents pitch.
