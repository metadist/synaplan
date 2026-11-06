# Agent template gallery — start from a working agent instead of an empty form

Row 42. Umbrella: [`20261102-enterprise-readiness/`](../20261102-enterprise-readiness/00_master_plan.md) (C20).

Missing know-how is the first barrier to AI in German companies: 85 % in
Bitkom 2026. An empty "New agent" form asks for the very know-how the
customer does not have. Dify, Langdock and Copilot Studio open with a
gallery of templates. Synaplan already has:

- **the format:** `synaplan-bundle.v1` with export, preview and import (`/api/v1/bundle/*`);
- **an install path:** plugins install agent packs with `PluginAgentInstaller`;
- **the ten use cases:** written down with their acceptance utterances (use-case research §2, Tier B "pack it").

This track turns them into a gallery where a person installs a template,
learns what it still needs, and has a working agent within minutes.

## 1. Findings (checked 2026-11-02 against `main`)

| # | Prio | Finding | Evidence |
| - | ---- | ------- | -------- |
| F1 | P1 | Assistants → New starts empty (or from the AI Setup Assistant for widgets). There is no gallery | agent builder views; `Service/Agent/AgentService.php` |
| F2 | — | Bundles v1 carry agents and prompts with an envelope validator, preview and import | `Controller/BundleController.php:51-254`, `Bundle/BundleEnvelopeValidator.php` |
| F3 | — | Plugin packs install agents as the admin with `BSOURCE = plugin:<id>`, publish them and share them with everyone; a changed pack publishes a new version | `Service/Plugin/PluginAgentInstaller.php:28-37` |
| F4 | P2 | A template that needs a mail connection, an image model, a tool or a cloud folder fails at first use instead of saying so at install | — |
| F5 | P2 | The use-case research lists Tier B (cases 2, 4, 6) as "pack it" and Tier A as "showcase now", with utterance tests; none ships as a template | `20260913-use-case-research/README.md` §4 |
| F6 | P1 | On 2026-11-06 the Assistants list (v5.71) still has no cards. **Create assistant** opens the empty form | owner screenshots 2026-11-06 15:05 |

## 2. What is fine — do not "fix" it

- Bundle v1 as the only format. A template is a bundle with a `template` block in its envelope. It is not a second format.
- Plugin agent packs. Plugins keep installing their own agents; the gallery lists those too, marked "from plugin {name}".
- Agent publishing and review (row 41 GV5). A template installed for the organisation goes through review when the group has review on.

## 3. Target model

### 3.1 A template

A bundle v1 document with an extra envelope block:

```json
{
  "template": {
    "id": "offer-writer",
    "version": "2026.11.1",
    "titleKey": "templates.offerWriter.title",
    "category": "sales",
    "requires": [
      {"kind": "capability", "value": "CHAT"},
      {"kind": "knowledgeFolder", "hintKey": "templates.offerWriter.needsPriceList"},
      {"kind": "connection", "value": "mail", "optional": true}
    ],
    "tryIt": ["templates.offerWriter.try1", "templates.offerWriter.try2"],
    "acceptance": "tests/templates/offer-writer.yaml"
  },
  "sections": [ … agents, prompts, savedTasks, tools … ]
}
```

- Text is i18n keys, translated in all five locales. Prompts carry a language-neutral instruction plus "answer in the user's language".
- Models are **never named**. A template asks for a capability (`CHAT`, `PIC2TEXT`, `TOOLS`, …), and the install picks the person's or the group's default model for it (`ModelRepository`).
- `requires` drives the install checklist (§3.3).
- `acceptance` is a YAML file: utterance, expected behaviour (tool called, source cited, approval requested), run in CI with a mocked provider (D3).

### 3.2 Where templates come from

- **Built-in:** `backend/templates/agents/*.json`, shipped with the release and covered by tests.
- **Plugins:** packs that plugins already declare.
- **Catalog (D2):** a public repository `metadist/synaplan-templates` with a signed index fetched like the update manifest (`Service/Update/UpdateManifestClient.php`). This lets templates update between releases. Off when egress is `strict` or the operator turns it off.

### 3.3 Install flow

1. Assistants → **New** opens the gallery first: categories, search, and **Start empty** as the last tile.
2. A template card shows the title, one sentence, what it does, what it needs, and "Used by 3 people here".
3. **Preview** shows the bundle preview (existing endpoint) in plain words: "Creates 1 agent and 2 prompts. Uses your default chat model. Needs a knowledge folder with your price list."
4. **Install** creates the items as the person (private). A checklist shows what is still missing. Each item has its action (**Choose folder**, **Connect mail**, **Ask your admin to add an image model**). The agent works for the parts already satisfied and says what it cannot do yet.
5. The **Try it** chips start a chat with the new agent.
6. Admins also get **Install for everyone**: shared like plugin packs, through review if the group has it.

An installed template remembers its `template.id` and version. When a new
version exists, the agent shows "A newer template version exists" with
**Show changes** (bundle diff) and **Update**. Updating never overwrites
the person's own edits without showing the diff (D4).

### 3.4 The first three cards (named 2026-11-06, not built)

These sit on the Assistants list, under **Create assistant**. **Create
assistant** stays the empty form. A card opens that same form already
filled. The rest of the gallery (categories, search, the full T1–T15
set) still follows §3.3.

| Card | Gaps the person fills | Already written into the form |
| ---- | --------------------- | ----------------------------- |
| These pages, every morning | the page addresses, the time, chat or email | only what is new; one sentence when nothing qualifies. Row 44 AX11 |
| These documents, one answer | the folder | the answer names the place it came from |
| This website, the frequent questions | the public address | the widget answers from those pages |

### 3.5 The first templates

Ten from the use-case research and five general ones. The order follows
the research tiers and the umbrella's buyer list.

| # | Template | Source | Needs |
| - | -------- | ------ | ----- |
| T1 | Regulation watch: report only material changes | Case 3 (Tier A) | URL watch, chat |
| T2 | Executive daily briefing from the mailbox | Case 7 (Tier A) | mail connection, Saved Task |
| T3 | Sovereign expert research | Case 10 (Tier A) | research on; local-only optional |
| T4 | Cited tender response workspace | Case 5 (Tier A matrix) | knowledge folder |
| T5 | Multilingual municipal service desk (widget) | Case 4 (Tier B) | widget, knowledge folder |
| T6 | Supplier invoices, exceptions only | Case 6 (Tier B) | folder watch or mail, approval, custom tool (ERP stub) |
| T7 | Market monitor with approved actions | Case 2 (Tier B) | custom HTTP tool (sandbox), approval |
| T8 | Translate every new document in a folder | Case 1 (Tier C, after folder watch) | cloud folder watch |
| T9 | Offer writer from the price list | general | knowledge folder |
| T10 | Meeting minutes and action items | general (openDesk meeting notes) | speech or upload |
| T11 | HR policy Q&A from the handbook | general | knowledge folder; masking recommended (row 41) |
| T12 | IT helpdesk first answer | general | knowledge folder; ticket handoff (row 38) optional |
| T13 | Website FAQ widget from your site | general (widget AI Setup Assistant) | widget, URL import |
| T14 | Social posts from a press release | general | chat |
| T15 | Contract summary (not legal advice) | general | upload; disclaimer copy in all five locales |

Cases 8 and 9 stay out: Tier D, partner or Desktop B.

## 4. Decisions

- [ ] **D1 (TG1).** The `template` envelope block in bundle v1 (§3.1), not a new schema version. The validator rejects unknown envelope keys today (`BundleEnvelopeValidator.php:15,44`), so TG1 adds `template` to `ENVELOPE_KEYS` as an optional key with its own validation. Bundles without it keep validating unchanged, and an older install rejects a template bundle with "unknown key", which is the honest answer.
- [ ] **D2 (TG4).** A remote catalog repository with a signed index, or built-in only. A remote catalog is an egress destination and is listed in `EgressBuiltinDestinations`.
- [ ] **D3 (TG2).** Every built-in template has an acceptance file run in CI with a mocked provider. A template without one does not ship.
- [ ] **D4 (TG3).** Updates show a diff and never overwrite local edits silently.
- [ ] **D5.** The first release ships T1–T5 and T9–T11. The rest follow as their primitives (folder watch, ticket handoff) land.

## 5. Journeys

| Id | Journey | Profile |
| -- | ------- | ------- |
| J-TG-1 | A new user opens Assistants → New, picks "Offer writer", reads that it needs a price-list folder, installs, chooses the folder from the checklist, presses the first **Try it** chip and gets an offer draft citing the price list | User |
| J-TG-2 | A user installs "Executive daily briefing" without a mail connection; the checklist says "Connect your mailbox so the briefing can read it" with **Connect mail**; after connecting, the Saved Task runs the next morning | User |
| J-TG-3 | An admin installs "HR policy Q&A" for everyone; the HR group has review on, so it waits in Approvals; after approval everyone sees it in their agent list marked "from template" | Admin |
| J-TG-4 | A new template version arrives; the agent shows the notice; **Show changes** lists the changed prompt line; **Update** keeps the person's renamed title | User |

## 6. Steps

| Step | Commit subject | Release class | Depends on | Gate |
| ---- | -------------- | ------------- | ---------- | ---- |
| TG1 | `feat(agents): templates as bundles with requirements` — `template` envelope block (D1), requirement kinds and a checker (capability, knowledge folder, connection, tool, feature flag, watch), capability-based model binding, `GET /api/v1/agent-templates` (OpenAPI → generate-schemas) | backend-only | D1 | `make ci-local` |
| TG2 | `test(agents): acceptance runs for templates` — YAML format, runner on the mocked provider, a CI step in the existing PHPUnit stage (no new workflow) | backend-only | D3, TG1 | `make ci-local` |
| TG3 | `feat(agents): start a new agent from a template` — gallery on Assistants → New, card, plain-words preview, install as the person, checklist with actions, **Try it** chips, **Install for everyone** (through review), update notice with diff (D4); J-TG-1, J-TG-2, J-TG-4 | ota-candidate + backend-only | D4, TG1 | `make ci-local && make test-e2e` |
| TG4 | `feat(agents): template catalog with signed index` — remote index client, signature check, cache, egress destination, admin switch | backend-only | D2, TG1 | `make ci-local` |
| TG5 | `feat(templates): first eight templates` — T1–T5, T9–T11 with five-locale copy and acceptance files; J-TG-3 | backend-only + ota-candidate (i18n) | D5, TG2, TG3 | `make ci-local && make test-e2e` |
| TG6 | `feat(templates): invoices, market monitor, folder translation, helpdesk, FAQ, posts, minutes, contracts` — T6–T8, T12–T15 as their primitives land (folder watch, row 38 SD5) | backend-only + ota-candidate | TG5 and each primitive | `make ci-local && make test-e2e` |

TG3 and TG5 carry the five exit bullets of `docs/UX_CONTRACT.md` §6
before their first `.vue`:

- **Journeys walked:** J-TG-1 to J-TG-4.
- **Ten-second findability:** the gallery is the first screen of Assistants → New; installed templates sit in the agent list with a "from template" mark.
- **Consequence copy in five locales:** "Install for everyone shares this agent with all 40 people here. They can use it but not change it."
- **Empty, error and flag-off states:** a template whose requirement cannot be met on this install (no image model at all) shows "Needs an image model, which this server does not have." and **Install** stays possible for the rest; with the catalog off, only built-in templates show, without a "coming soon" tile (U11).
- **Dark, V2 and 320 px:** cards in one column at 320 px.

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| Templates rot when the product changes | Acceptance runs in CI (D3); a failing template fails the build |
| Templates name models or use features an install does not have | Capability binding; requirement checker; no model names in templates (AGENTS.md rule) |
| A remote catalog becomes a supply-chain risk | Signed index (key in the image), bundle validator, preview before install, egress policy |
| Contract or HR templates are read as advice or as an HR decision tool | Disclaimer copy; HR template answers policy questions only (no candidate evaluation — AI Act high-risk area, umbrella non-goal) |

## 8. Non-goals

- A public marketplace with third-party authors, ratings or payments.
- Vertical connectors inside templates; they reference custom tools or MCP servers the customer adds.

## 9. Change log

| Date | Change |
| ---- | ------ |
| 2026-11-02 | Plan written from the use-case research tiers, the bundle and plugin-pack code on `main`, and the gallery patterns of Dify, Langdock and Copilot Studio. No code changed. |
