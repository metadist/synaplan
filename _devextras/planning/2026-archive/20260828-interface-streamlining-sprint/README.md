# Interface streamlining research and sprint proposal

**Status:** Implemented on `feat/ux-changes` (sprints 1–4)  
**Date:** 2026-08-28  
**Scope:** Synaplan web interface; implementation landed with this branch

## Executive recommendation

Synaplan does not mainly have a styling problem. It has a product-scope and information-architecture
problem: personal work, assistant creation, workspace administration, installation operation, and
commercial hosting are presented in one navigation model.

The recommended direction is:

1. Keep everyday work extremely small: **Chat, History, Sources**.
2. Put assistant and channel creation in a separate **Manage** context.
3. Put installation-wide configuration in an **Operate** context visible only to instance
   operators.
4. Keep personal settings in the account menu.
5. Add a hoster console only when Synaplan has real tenant/customer roles and permissions. Do not
   imply that an optional company name in a billing profile is a managed organization.

This is not a request for multiple visual themes or a user-selectable "simple/advanced mode." The
interface should be capability-driven: people see the areas required for their responsibilities,
while advanced controls appear in context when needed.

**Execution plan (menu, wording, structure only):**
[01_execution_sprints.md](./01_execution_sprints.md) — four shippable sprints, each ending in
tests. No new APIs or roles. The assistant-editor and hoster-console work stays out.

**Compatibility contract:** [02_compatibility_contract.md](./02_compatibility_contract.md) —
binding rules protecting the embedded chat widget on customer sites (which bundles the app's
i18n and `style.css`), daily plugin users, live-support operators, the Outlook add-in, shared
chat links, and mobile OTA delivery; plus the per-sprint regression matrix and the reserved
workspace seam.

**Playwright test plan:** [03_playwright_test_plan.md](./03_playwright_test_plan.md) — complete
E2E inventory with per-sprint rewrite/update/guard classification, selector-alias process,
visual-snapshot re-baseline policy, new specs to add, and effort estimates.

## Research inputs

This proposal is based on:

- A route and navigation inventory of the current frontend.
- Inspection of the running desktop interface as an administrator.
- Review of chat, sources, channels, widgets, AI model setup, administration, system
  configuration, settings, profile, onboarding, and setup-wizard flows.
- Review of the archived June 2026 navigation cleanup and the active Release 4.0 onboarding and
  guest UX plans.
- Review of hosting-partner requirements in `_devextras/planning`.

Relevant implementation surfaces include:

- `frontend/src/router/index.ts`
- `frontend/src/composables/useNavItems.ts`
- `frontend/src/components/SidebarV2.vue`
- `frontend/src/components/MobileNav.vue`
- `frontend/src/views/ChatView.vue`
- `frontend/src/views/FilesView.vue`
- `frontend/src/views/ChannelsView.vue`
- `frontend/src/views/WidgetsView.vue`
- `frontend/src/views/AdminView.vue`
- `frontend/src/views/AdminConfigView.vue`
- `frontend/src/views/SettingsView.vue`
- `frontend/src/views/ProfileView.vue`
- `frontend/src/components/config/AIModelsConfiguration.vue`
- `frontend/src/components/widgets/AdvancedWidgetConfig.vue`

The router currently contains roughly 70 explicit path declarations. Not all are simultaneous menu
items, but this is an indication of how many product concepts the shell must organize.

## What the earlier navigation cleanup already solved

`_devextras/planning/archive/20260611-navigation-ia-cleanup.md` correctly identified overloaded
labels, inconsistent routes, mobile problems, and duplicated navigation definitions. Much of the
current shell is cleaner as a result:

- Desktop and mobile navigation share `useNavItems`.
- "Sources," "Channels," and "AI Setup & Tools" are clearer than the former labels.
- The primary rail is visually compact.
- Guest feature gates and mobile navigation are more consistent.

The remaining problem is deeper than naming. A cleaner flyout still mixes unrelated levels of
responsibility. The next sprint should separate product contexts rather than perform another label
shuffle.

## Main findings

### 1. The navigation mixes three scopes

The administrator rail presents everyday actions beside installation operations:

- New chat, history, and personal sources are user work.
- Widgets, inbound channels, connections, and API access are assistant/workspace management.
- Provider keys, model catalogues, user administration, moderation, system health, and global
  configuration are instance operations.

These scopes have different audiences, risk levels, and frequencies. Giving them equal weight makes
the product feel larger than it is and makes destructive or technical controls appear ordinary.

### 2. Subscription levels are doing some of the work of roles

The frontend principally distinguishes an administrator from a user and also checks subscription
levels such as PRO, TEAM, and BUSINESS. That is not enough to represent:

- a person who only chats;
- a knowledge editor;
- an AI assistant owner;
- a company administrator;
- an installation operator;
- a hosting partner operating customer installations.

Plan level answers "what was purchased," not "what this person is responsible for." Navigation
should eventually be based on explicit capabilities. Until those capabilities exist, the sprint
must use conservative existing checks and avoid claiming that unsupported organization roles exist.

### 3. "Company" is billing metadata, not a product scope

The profile contains optional company name and VAT fields. The inspected backend/frontend surfaces
do not expose a true organization, tenant, workspace owner, or reseller model.

This matters because a hoster console cannot be designed as navigation alone. Customer boundaries,
delegated administration, token pools, auditability, and permissions need a domain model first.
The shell can reserve a future place for this context, but the initial streamlining sprint should
not fabricate it.

### 4. The chat start page asks users to understand infrastructure

For an administrator, the empty chat page includes setup/readiness material, multiple AI provider
choices, a token meter, incognito controls, and chat actions. This is useful information, but it
competes with the primary task: ask a question.

The system should choose a safe default and let a simple user start. Provider readiness belongs in
Operate. A user-level model choice can remain available as a secondary control when more than one
approved model is selectable.

### 5. AI configuration is split across several destinations

Provider and model operations appear across:

- the chat empty state;
- AI model configuration;
- provider setup;
- model/provider status;
- system configuration;
- widget prompt/model controls.

`AIModelsConfiguration.vue` combines friendly model choice with operator-oriented catalogue editing
and vector-run information. The user-facing choices include technical internal purposes such as
sorting, memory extraction, query improvement, summaries, and vectorization.

A simple user should not need to know which model performs internal routing. An operator should have
one place to manage the approved catalogue, credentials, defaults, and health.

### 6. Broad menu categories hide heterogeneous destinations

"AI Setup & Tools" currently covers model selection, instructions, routing, tasks, AI agents,
summaries, and email generation. "Channels" covers inbound handlers, website widgets, personal
connections, MCP, and API access.

These names are not technically wrong, but users must open them and interpret a mixed list before
they can act. The categories reflect implementation capabilities more than user goals.

### 7. The widget flow starts well and then becomes an expert console

The new-widget empty state and four-step creation wizard are clear. They use progressive disclosure
and explain required inputs.

After creation, advanced widget configuration contains seven sub-areas condensed into three
dropdown groups because the tabs no longer fit. The component covers appearance, behavior, custom
fields, security, privacy, AI assistance, prompts, models, and many detailed limits. This is strong
evidence that the editor needs a resource-level information architecture, not denser tab controls.

The better mental model is an **AI assistant** with one or more deployments. A website widget is a
deployment channel, not the container for all assistant knowledge and behavior.

### 8. Sources contains user and operator concepts

The Sources view combines browsing, incoming items, generated items, semantic search, and vector
management. Browsing and search are user goals. Vector runs and indexing diagnostics are operator
goals. Incoming/generated provenance may be useful, but should be presented as filters or source
types unless a user routinely manages those queues.

### 9. Administration is fragmented

The Admin dashboard includes system information, users, prompts, usage, subscriptions, and
moderation. Installation configuration is a separate destination. Provider setup and model health
are elsewhere again.

An operator cannot answer "Is this installation ready, healthy, private, and within budget?" from a
single overview.

### 10. Account and preferences overlap

The account menu leads to profile, memories, statistics, feedback, subscription, and preferences.
The profile itself combines personal data, optional company billing data, invoice data, language,
timezone, memories, password, app security, and account deletion. `SettingsView` also contains
language and theme controls.

Even if device language and account language have different persistence semantics, the interface
does not make the distinction useful to an end-user. Personal settings need one predictable home.

### 11. Sovereignty is a deployment property but is not summarized as one

Self-hosting is visible during native onboarding, and the system supports local providers and local
infrastructure. However, the operational UI presents individual configuration fields rather than a
clear sovereignty posture.

A sovereign operator needs immediate answers:

- Which AI providers may receive data?
- Are any cloud fallbacks enabled?
- Where are files, vectors, memories, and logs stored?
- Which external services are connected?
- Is authentication local or delegated?
- Are backups, retention, and updates healthy?

Raw environment-style settings are necessary for experts, but they should sit behind a readable
status summary.

### 12. Menu ownership and route ownership disagree

The canonical URL intent is `/channels/*` for conversation entry points and `/ai/*` for AI
machinery. The current AI Setup menu nevertheless links to saved tasks, AI agents, and the email
handler under `/channels/*`. Active-state logic has to special-case this disagreement.

Users should not have to understand a technical URL taxonomy, but the mismatch is evidence that
the product categories are unstable. Put tasks and action-oriented agents under Automations; put
email and other delivery endpoints under Channels; keep models, instructions, and routing under AI
assistant setup or operator infrastructure according to ownership.

### 13. Removing Easy Mode left no replacement disclosure model

Release 4.0 intentionally removed Easy Mode, and the shared navigation now exposes the full
advanced product structure. Removing a second mode was the right maintenance decision, but it also
removed the only mechanism that reduced first-session choices.

Do not restore two global modes. Replace them with capability-aware navigation, sensible defaults,
resource-local Advanced sections, and first-success milestones that progressively reveal the next
task.

### 14. Some secondary paths are stale or misleading

The audit found examples that should be included in the implementation inventory:

- A disabled tool can direct users to `/settings?tab=features`, although Preferences has no
  features tab.
- Live support and some provider detail pages are reachable only through secondary flows.
- Legacy `/tools/*` and `/config/*` redirects remain registered.
- Account destinations such as feedback and statistics are available but have weak placement.

The sprint should classify every route as canonical, redirect, contextual detail, or removal
candidate. A route should never be left discoverable only by accident.

### 15. Accessibility quality is uneven across otherwise strong foundations

The current interface has useful accessibility work, including labeled controls, mobile target
sizes, reduced-motion handling, and inert drawer content. Important gaps remain:

- the shared dialog behavior does not consistently trap and restore focus;
- some labels and loading text remain hardcoded rather than translated;
- no skip-to-content behavior was found in the main shell;
- automated axe checks are currently report-only rather than blocking on primary journeys.

These are not separate polish items. A new hierarchy is unsuccessful if keyboard and screen-reader
users cannot traverse it predictably.

## Personas and minimum interfaces

### Chat user

Primary goals:

- Ask questions and continue conversations.
- Find or add allowed knowledge.
- Manage personal preferences and privacy.

Default navigation:

- Chat
- History
- Sources
- Account

Do not show provider credentials, internal model purposes, vector jobs, system configuration, or
global usage.

### Knowledge editor

Primary goals:

- Maintain approved source material.
- Review incoming/generated material where applicable.
- See indexing state and fix content-level errors.

Add:

- Source management actions
- Content status and ownership

Do not show infrastructure diagnostics unless the person is also an operator.

### AI assistant owner

Primary goals:

- Configure an assistant's purpose, instructions, knowledge, channels, actions, and guardrails.
- Test it before publishing.
- Review conversations and outcomes.

Add a **Manage** context containing assistants and their deployments. Avoid sending this person into
global provider or system settings.

### Company administrator

Primary goals:

- Manage people, access, approved assistants, shared knowledge, usage, and billing for the managed
  scope.

This requires an explicit organization/workspace concept if one is not already present in the
backend contract. It must not be inferred from a billing profile.

### Instance operator / sovereign administrator

Primary goals:

- Configure providers, identity, storage, messaging, security, retention, and updates.
- Diagnose system health.
- Enforce which external services are allowed.

Show an **Operate** context. Keep it separate from daily chat and assistant authoring.

### Hosting partner / reseller

Primary goals:

- Provision and manage customers.
- Allocate token pools and plans.
- Monitor usage, margins, limits, and service health.
- Delegate customer administration and apply branding.

This should become a separate **Hoster Console**, not another section in the existing Admin page.
It is a future product epic dependent on tenancy, delegated RBAC, and billing-domain decisions.

## Target mental model

Use four product nouns consistently:

1. **Chat** — a person's conversations.
2. **Sources** — knowledge available to people and assistants.
3. **AI assistants** — configured behavior, instructions, knowledge, and guardrails.
4. **Channels** — places where an assistant is deployed: website widget, email, messaging, API,
   MCP, or another integration.

Use **Automations** for scheduled tasks and action-oriented agents. Do not use "AI agent" as a broad
synonym for every assistant.

System concepts such as providers, model catalogues, embeddings, vector runs, authentication, and
retention belong to **Operate**.

## Proposed information architecture

### Work context

Visible to every signed-in user, subject to capabilities:

- **Chat**
- **History**
- **Sources**

The account control remains pinned separately.

### Manage context

Visible to assistant owners and workspace/company administrators:

- **Overview**
- **AI assistants**
- **Sources**
- **Channels**
- **Automations**
- **People & access** when a managed multi-user scope exists
- **Usage & billing** when the user owns that scope

Selecting an AI assistant opens a stable detail navigation:

- Overview
- Instructions
- Knowledge
- Channels
- Actions & automations
- Safety & privacy
- Test
- Conversations & analytics
- Advanced

This replaces the pattern of placing an assistant's setup in global pages and a widget's many
settings in a large modal.

### Operate context

Visible only to installation operators:

- **Overview**
  - readiness;
  - service health;
  - active warnings;
  - processing/data-boundary summary;
  - version and updates.
- **AI infrastructure**
  - providers and credentials;
  - approved model catalogue;
  - capability defaults;
  - model health;
  - embeddings and vector jobs.
- **Identity & access**
  - users;
  - administrator access;
  - external authentication;
  - sessions and audit.
- **Data & security**
  - storage locations;
  - retention;
  - backups;
  - privacy defaults;
  - allowed external processing.
- **Communication**
  - inbound/outbound mail;
  - messaging platform infrastructure;
  - global connector health.
- **Usage & commercial**
  - system usage;
  - subscriptions;
  - moderation;
  - global limits.
- **Advanced configuration**
  - raw settings;
  - diagnostics;
  - maintenance operations.

### Personal account

Keep only user-owned concerns:

- Profile
- Preferences: language, theme, timezone
- Personal memory and privacy
- Sign-in and security
- Personal plan/subscription, when applicable
- Delete account

Move system statistics, global feedback operations, provider keys, and installation controls out of
the account menu. If API keys authorize workspace or developer access, place them under Manage >
Channels/Developer instead of Profile.

## Navigation interaction

### Desktop

- Keep the compact rail for Work.
- Add one clearly separated **Manage** entry when the user can manage assistants or shared content.
- Add one clearly separated **Operate** entry for installation operators.
- Selecting Manage or Operate opens that context's own labeled side navigation and breadcrumb.
- Do not expose every child destination in hover flyouts from the primary rail.
- Preserve the last destination within each context.

### Mobile

- Bottom navigation should contain at most four stable actions: Chat, History, Sources, More.
- More contains Account and any authorized Manage/Operate context switches.
- Resource detail pages use a page-local section menu, not a second giant global accordion.

### Search and command access

Add a global destination search/command palette only after the hierarchy is clear. Search is a
shortcut, not a repair for confusing navigation.

## Progressive-disclosure rules

1. Show the recommended default first.
2. Explain the user outcome, not the implementation primitive.
3. Put optional settings under "Customize."
4. Put uncommon or risky controls under "Advanced."
5. Keep diagnostics next to the resource they diagnose, with deeper infrastructure diagnostics in
   Operate.
6. Never hide authorization behind visual disclosure: the backend must still enforce every
   capability.
7. Do not create a global "Easy mode." It produces two products, drifts over time, and makes support
   harder.

Examples:

- Offer an assistant owner "Balanced," "Private/local," "Best quality," and "Lowest cost" policies
  if product policy supports them. Let an operator inspect the exact model bindings.
- Show "Knowledge is ready" or "3 files need attention" before exposing vector-run details.
- Show "Data may be processed by Anthropic" before exposing provider IDs and raw endpoints.
- Create a widget with safe defaults, then offer appearance and behavior customization after the
  first working preview.

The four-sprint breakdown, file list, and per-sprint test gates live in
[01_execution_sprints.md](./01_execution_sprints.md). The deliverables below are the
research contract that those sprints implement.

## Proposed sprint: separation before redesign

### Sprint goal

Reduce cognitive load without requiring new backend domain models: separate daily work from
installation operations, simplify the first-use chat experience, and create one predictable home
for settings.

### Deliverable 1: capability-aware shell

- Keep Work to Chat, History, and Sources.
- Add Manage and Operate as explicit contexts using existing authorization and feature flags.
- Move current destinations into the appropriate context without deleting routes.
- Keep legacy routes as redirects/deep-link aliases.
- Ensure selected states, breadcrumbs, browser Back, direct links, and mobile behavior remain
  correct.

### Deliverable 2: operator overview

- Create a single operator landing page from existing status APIs.
- Present setup completeness, provider/model health, storage/indexing health, communication health,
  version, and critical warnings.
- Link each problem directly to its owning configuration page.
- Add a concise "Data processing" summary that distinguishes local and external providers.
- Keep raw configuration available under Advanced.

### Deliverable 3: simplified chat start

- Make the composer and example tasks the primary empty-state content.
- Move installation setup cards to Operate.
- Show only one blocking readiness message when chat genuinely cannot work.
- Keep a compact model selector only when the user may choose among approved models.
- Move token/budget detail to a secondary usage surface; retain a small warning only near a real
  limit.

### Deliverable 4: settings consolidation

- Merge visible language/theme/timezone controls into one Preferences destination.
- Keep profile identity and billing data separate from preferences.
- Keep memory/privacy and security as clearly labeled account sections.
- Remove duplicate destinations from the account menu.
- Replace remaining hardcoded profile messages with translated copy during implementation.

### Deliverable 5: terminology and destination audit

- Apply the four-noun mental model: Chat, Sources, AI assistants, Channels.
- Reserve provider/model/vector terminology for operator or advanced surfaces.
- Ensure each menu item has one destination and each destination has one canonical label.
- Update all four locales together.
- Add route-level page titles and breadcrumbs for every retained destination.

### Deliverable 6: usability validation

Test clickable prototypes or the staged implementation with at least:

- two chat-only users;
- one assistant/content owner;
- one company or installation administrator;
- one self-hosting/sovereignty-focused operator;
- one hosting-partner representative.

Use realistic tasks rather than asking whether the interface "looks simple."

### Deliverable 7: route, copy, and accessibility hygiene

- Inventory every registered route as canonical, redirect, contextual detail, internal utility, or
  removal candidate.
- Fix misleading destinations such as links to nonexistent settings tabs.
- Remove stale Easy Mode product copy and align current product documentation.
- Complete the terminology pass in user-visible text, accessible names, empty states, and page
  titles.
- Add focus trap and focus restoration to shared dialogs.
- Add a skip-to-content path and verify landmarks.
- Make accessibility checks blocking for a small set of stable primary journeys before expanding
  the gate.

## Follow-up sprint: assistant-centered management

The next sprint should restructure widgets and global AI setup around the AI assistant resource:

1. Introduce an AI assistant overview.
2. Associate instructions, knowledge, safety, and model policy with the assistant.
3. Treat widgets and other integrations as deployments/channels.
4. Replace the large advanced-widget modal with a full detail page and stable section navigation.
5. Add test/publish states so configuration is not immediately conflated with production.
6. Move system model catalogue and vector diagnostics to Operate.

This is a product-model change and should not be squeezed into the shell-only sprint.

## Separate future epic: hoster console

Do not add "Hoster" to the current Admin tabs. First decide and implement:

- tenant/customer boundaries;
- operator, reseller, customer-admin, and end-user capabilities;
- delegated administration;
- per-tenant quotas and token pools;
- billing ownership;
- branding and domains;
- audit logs;
- data isolation;
- support impersonation rules;
- provisioning lifecycle.

Only then design a hoster console around Customers, Plans & token pools, Usage, Provisioning, Health,
Branding, and Audit.

## Route migration approach

The first sprint should change navigation and ownership, not break external links.

- Retain existing route components initially.
- Add canonical context routes and redirect old links where safe.
- Preserve query parameters and resource IDs.
- Maintain feature-gate behavior.
- Add route ownership metadata such as Work, Manage, Operate, Personal, or Public.
- Add capability metadata separately from billing-plan metadata.
- Generate desktop, mobile, breadcrumbs, and destination search from the same route/navigation
  source.

## Accessibility and visual consistency requirements

The research found a reasonably consistent current visual language. The sprint should avoid a
simultaneous visual rebrand and focus on hierarchy.

Required checks:

- keyboard access to context navigation and page-local menus;
- visible focus states;
- meaningful headings and landmarks;
- correct dialog focus trap and return;
- no hover-only navigation;
- WCAG AA contrast in light, dark, and V2;
- usable layout from 320 px through wide desktop;
- zoom at 200%;
- labels that remain understandable without icons;
- reduced-motion behavior;
- loading, empty, error, disabled, and permission-denied states.

## Success measures

Capture a baseline before implementation, then compare:

- A chat-only user can start a new chat with one primary action.
- A chat-only user sees no more than four persistent navigation choices including Account.
- At least 80% of test participants find a known source without assistance.
- At least 80% of assistant owners find widget/assistant configuration without assistance.
- At least 80% of operators locate provider credentials and diagnose a disabled provider within
  two navigation decisions.
- No participant mistakes personal settings for system settings.
- No participant mistakes a billing company field for a managed organization.
- Navigation-related support questions and abandoned setup flows decrease.
- Existing deep links, mobile routes, guest gates, and browser navigation remain functional.
- No visible action leads to a nonexistent tab or unrelated settings page.
- Keyboard-only users can enter, traverse, and leave every changed navigation and dialog surface.

Useful product events:

- context selected;
- destination selected;
- navigation search used;
- setup task opened/completed;
- empty-state CTA used;
- wizard step abandoned;
- advanced section opened;
- permission-denied destination attempted.

Do not log user prompts, source content, credentials, or other sensitive values with these events.

## Acceptance criteria for the streamlining sprint

- Everyday users have a small Work navigation.
- Manage and Operate are visibly separate and authorization-aware.
- Provider/model/setup controls no longer dominate the chat empty state.
- Operator readiness and configuration entry points have one home.
- Personal preferences have one home.
- Current routes remain link-compatible.
- Desktop and mobile derive from one navigation model.
- All four locales are complete.
- Light, dark, V2, keyboard, and 320 px behavior are verified.
- Primary chat, authentication, navigation, and shared-dialog accessibility checks are blocking.
- The existing backend authorization remains authoritative.
- No unsupported workspace, company-admin, or hoster capability is implied.

## Decisions needed before implementation

1. Is "workspace" intended to become a real domain object, or does each installation remain the
   managed scope?
2. Can one AI assistant have multiple channels, or are widgets intentionally independent
   assistants?
3. Which roles may edit shared sources versus only upload personal sources?
4. Are model choices a user preference, an assistant policy, an operator default, or all three with
   explicit precedence?
5. Which usage and billing data is personal, organizational, installation-wide, or reseller-owned?
6. What exact claim should the sovereignty summary make, and which backend facts can prove it?
7. Should API and MCP credentials belong to a person, an assistant, or a managed scope?

## Workspace scope: why it is deferred, and why that is safe

Question raised in review: is it wise to leave the workspace (company/team) scope out?

**Yes — for these sprints.** Reasons:

1. There is no backend organization/tenant/role model to bind to. A "People & access" menu with
   no enforcing API would be a lie the backend cannot keep; the house rule is that visual
   disclosure never substitutes for authorization.
2. Subscription levels (TEAM, BUSINESS) describe what was purchased, not who may administer
   whom. Building menus on plan level would bake the wrong abstraction in.
3. The workspace questions (decisions 1, 3, 5, 7 below) are product decisions with billing and
   isolation consequences. Answering them under a shell-cleanup deadline produces a rushed role
   model that the hoster console then inherits.

**But deferral must not become exclusion.** The execution plan reserves the seam so the
workspace scope can land later without a third IA rewrite
([02_compatibility_contract.md](./02_compatibility_contract.md) §6):

- **Manage is designed as the future workspace home** — it later becomes "Manage
  *(workspace)*" and gains People & access plus Usage & billing children.
- Navigation visibility runs through **one named capability predicate per context**
  (Manage: signed-in today → `canManageWorkspace` later; Operate: `isAdmin` today →
  `canOperateInstance` later). Swapping predicates is the entire migration.
- Routes carry `meta.context` ownership metadata from Sprint 2 onward.
- No "Workspace", "Team", or "Organization" label appears in the new menu until the backend
  model exists, and Profile's company fields stay labeled as billing data.

Accepted interim cost: TEAM/BUSINESS customers keep managing users via the operator area, as
they do today. That is unchanged behavior, not a regression.

## Recommended decision

Approve the first sprint as an information-architecture separation using existing routes and
permissions. Do not begin with a visual redesign, a global easy/advanced switch, or a hoster tab.

In parallel, answer the domain questions for assistants, workspaces, and hosters. Those answers
determine the second-stage assistant editor and future hoster console.
