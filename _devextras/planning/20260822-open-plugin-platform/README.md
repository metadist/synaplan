# Open Plugin Platform — pluggable Channels, Plugins, and Skills

**Status:** Plan drafted 2026-08-22, target start: week of 2026-08-24.
**Inspiration:** [OpenClaw's channel catalog](https://docs.openclaw.ai/channels) — every messaging channel is a plugin ("bundled", "official, install with one command", or "external"), listed on a public docs page, installed on demand.
**Builds on:** [`channels-planning.md`](./channels-planning.md) (channel capability inventory), [`release4.0/08_mcp-data-nodes-and-skill-registry.md`](./release4.0/08_mcp-data-nodes-and-skill-registry.md) (SkillDescriptor/SkillCatalog, shipped), [`mcp-and-api-enhancements/`](./mcp-and-api-enhancements/) (early plugin-architecture notes, partly obsolete).

> **The ask:** `/channels/connections` offers solid but hardcoded choices. Make channels
> pluggable the way `plugins/` already works — a channels directory, optional download,
> a public catalog page ("get the most used ones") — and let plugins also contribute
> **Skills**. Conceptually more open.

---

## 1. Vision in one paragraph

**One packaging unit — the plugin — that can provide any extension type.** A plugin is a
directory with a manifest; what it *provides* is declared in the manifest: a **channel**
(conversation transport like Telegram or Slack), a **skill** (a planner building block /
DAG capability), a **chat command**, a **connection type** (a new sink/source for the
Connections hub), a **context provider**, or a full **UI page** — in any combination.
Bundled plugins ship with core, official plugins are downloadable from a curated
Synaplan registry, external plugins are drop-in like today. The `/channels` UI and the
planner's capability list stop being hardcoded and instead render whatever the installed
plugins register — plus "available to install" cards fed from the registry index, exactly
like OpenClaw's channel page.

Canonical terminology (keep these words consistent in code, docs, and UI):

| Term | Meaning |
| ---- | ------- |
| **Plugin** | The packaging + distribution unit (directory + `manifest.json`). The ONLY unit that gets installed/downloaded. |
| **Channel** | A conversation transport a plugin provides: message in → pipeline → reply out (WhatsApp, email, Telegram, …). |
| **Connection** | A linked external system used as data source/sink (`BCONNECTIONS`: M365, Dropbox, CalDAV, MCP, …). Already registry-based. |
| **Skill** | A multitask/DAG building block (`Capability` + `TaskRunner` + `SkillDescriptor`). Already a core concept; plugins will contribute them. |
| **Command** | A composer slash command (`/fastbill`) — the existing `chatCommands` seam. |

---

## 2. Where we stand (verified 2026-08-22)

### 2.1 What is already modular — reuse, don't rebuild

| Seam | Mechanism | Key paths |
| ---- | --------- | --------- |
| Plugin discovery + DI + routes | Kernel globs `plugins/*/manifest.json`, registers PSR-4, autowires/autoconfigures everything under `backend/`, imports attribute routes | `backend/src/Kernel.php`, `backend/config/plugin-autoloader.php` |
| Per-user install | Symlink into user tree + per-user `migrations/*.sql` | `Service/Plugin/PluginManager.php`, `app:plugin:install` |
| Plugin UI | Runtime config lists installed plugins → sidebar → `PluginView.vue` mounts `frontend/index.js` | `ConfigController`, `PluginView.vue`, `PluginAssetController` |
| Chat commands | Manifest `chatCommands` → composer union → `POST …/plugins/{name}{endpoint}` (bypasses AI pipeline) | `PluginManifest`, `stores/commands.ts`, `ChatView.vue` |
| Prompt context | `PluginContextProviderInterface`, tagged `app.plugin.context_provider` → `ChatHandler` | castingdata is the reference |
| Per-user storage | `plugin_data` table (`PluginDataService`), `BCONFIG` group `P_{slug}` | migration `Version20260417000000` |
| Skills (core) | `TaskRunner::describe(): SkillDescriptor` → `SkillCatalog` → planner `[CAPABILITYLIST]`, tagged `app.multitask.runner` | `Service/Multitask/Skill/` |
| Connection sinks/testers | `DestinationProvider` (`app.destination.provider`), `ConnectionTester` (`app.connection.tester`), `PlannerChannelCatalog` | `Service/Connection/`, `Service/Destination/` |

### 2.2 What is hardcoded — the gap this plan closes

1. **Conversation channels are bespoke.** WhatsApp (`WhatsAppService`, ~2.5k LOC),
   email (`EmailChatService` + webhook), widget — each its own controller/service pair
   calling `MessageProcessor` directly. No `ChannelInterface`, no registry. Adding
   Telegram today = new webhook controller + new service + new outbound path by hand.
   `MessageForwardingService` hardcodes `'whatsapp'`.
2. **The `/channels` UI catalog is hardcoded Vue.** The "add" cards in
   `ConnectionsConfiguration.vue` and the channel list in `InboundConfiguration.vue`
   (WhatsApp numbers are even mock data from `mocks/config.ts`) are template-coded,
   not rendered from a registry.
3. **No download/install story.** Plugins are ops-delivered: drop into `/plugins/`
   (mounted `:ro` in Docker!) or bake into the image. No registry index, no in-app
   install, no version/update handling, no multi-node story.
4. **Skills are core-only by convention.** The tagged-service mechanics would already
   pick up a plugin-provided `TaskRunner` (Kernel autoconfigures plugin classes) — but
   nothing declares, gates, or documents it, and the planner prompt / characterization
   snapshots assume a fixed capability set.

---

## 3. Target architecture

### 3.1 Manifest v2 — declare what a plugin *provides*

Extend `manifest.json` with a `provides` object. Everything stays additive; existing
manifests keep working (`chatCommands` is folded in as `provides.commands`).

```json
{
  "id": "telegram",
  "namespace": "Plugin\\Telegram",
  "displayName": "Telegram",
  "version": "1.0.0",
  "minSynaplanVersion": "4.1",
  "tier": "official",
  "provides": {
    "channels": [
      {
        "key": "telegram",
        "label": "Telegram",
        "icon": "frontend/assets/telegram.svg",
        "configSchema": "config/channel.schema.json",
        "webhookPath": "/webhooks/telegram"
      }
    ],
    "skills": [
      { "capability": "telegram_send", "runner": "Plugin\\Telegram\\Skill\\TelegramSendRunner" }
    ],
    "commands": [
      { "command": "tg", "endpoint": "/chat", "description": "…" }
    ],
    "connectionTypes": [],
    "contextProviders": [],
    "ui": { "entry": "frontend/index.js" }
  },
  "permissions": ["network:api.telegram.org", "plugin_data", "webhook:inbound"]
}
```

Core parses and **enforces** `provides` (unlike today's decorative `routes`/`config`
fields): a plugin that registers a channel class not declared in the manifest fails
loudly at boot. `permissions` is declarative documentation first (shown at install
time), enforcement is a later hardening step (§7 Q5).

### 3.2 Channels as plugins — the `ChannelTransport` contract

New core seam, deliberately shaped like the proven `DestinationProvider` pattern:

```php
// backend/src/Service/Channel/ChannelTransportInterface.php  (new, core)
interface ChannelTransportInterface
{
    /** Stable key: 'whatsapp', 'email', 'telegram', … (= Chat::$source value) */
    public function key(): string;

    /** Catalog card: label, icon, setup description, config schema, docs URL */
    public function descriptor(): ChannelDescriptor;

    /** Normalize a raw inbound payload (webhook body) into an InboundMessage DTO */
    public function normalizeInbound(Request $request): InboundMessage;

    /** Deliver an OUT message back to the transport (text/media/TTS as supported) */
    public function deliver(Message $out, DeliveryContext $ctx): DeliveryResult;

    /** Per-user or per-instance config test (mirrors ConnectionTester) */
    public function test(ChannelConfig $config): TestResult;
}
```

- **`ChannelRegistry`** collects implementations via `#[AutowireIterator('app.channel.transport')]`
  — identical mechanics to `RunnerRegistry` / `DestinationRegistry`.
- **One generic inbound endpoint** `POST /api/v1/webhooks/{channelKey}` (keep the
  existing `/webhooks/whatsapp` and `/webhooks/email` routes as aliases) →
  `ChannelRegistry::get($key)->normalizeInbound()` → shared identity/chat resolution →
  `MessageProcessor` → `deliver()` for the reply. The pipeline itself does not change.
- **Outbound forwarding generalized:** `MessageForwardingService` asks the registry for
  the chat's source transport instead of hardcoding `'whatsapp'`.
- **Migration of existing transports is adapter-first, not a rewrite:** wrap
  `WhatsAppService` and `EmailChatService`/`InternalEmailService` in thin
  `WhatsAppTransport` / `EmailTransport` adapters implementing the interface. Widget
  and web/SSE stay core (they are not webhook transports); they still register
  descriptors so the catalog is complete.
- **Pilot for the plugin path: Telegram.** Smallest real-world transport (bot token,
  long-poll/webhook, text+media) — the same reason OpenClaw calls it their fastest
  setup. It becomes the reference "channel plugin" the docs template is written from.

### 3.3 The catalog — dynamic UI instead of hardcoded cards

New endpoint `GET /api/v1/channels/catalog` returning three groups:

1. **Active** — configured channel instances for this user (from `ChannelRegistry` +
   per-user config in `plugin_data` / `BCONFIG`).
2. **Available** — transports registered by installed plugins/core but not yet
   configured (render "Set up" cards from `ChannelDescriptor`).
3. **Installable** — entries from the remote registry index (§3.5) that are not
   installed (render "Install" cards; admin-gated).

`ConnectionsConfiguration.vue` / `InboundConfiguration.vue` render from this endpoint;
the hardcoded M365/Dropbox/DAV cards become descriptor-driven, and
`mockWhatsAppChannels` dies. Connections (M365, Dropbox, …) keep their own registry —
the catalog endpoint merges both worlds into one page, but conversation channels and
connections remain distinct concepts underneath (see `channels-planning.md` §1).

### 3.4 Skills as plugin contributions

The mechanics are ~free (Kernel already autoconfigures plugin classes, so a plugin
`TaskRunner` gets tagged and collected by `SkillCatalog` today). What's missing is
making it *intentional and safe*:

- Manifest `provides.skills` declares each capability; boot-time check that every
  plugin-tagged runner is declared, and that its capability key is namespaced
  (`{pluginId}_{name}`) so plugin capabilities can never collide with core enum cases.
- `Capability` handling: plugin capabilities are **string-keyed, not enum cases**
  (the `dynamic` path the skill-registry doc anticipated). `TaskPlanValidator` accepts
  a capability iff core enum OR registered plugin skill for this user.
- Planner exposure is **per-user**: `SkillCatalog::render($userId)` only includes
  skills from plugins the user has installed — same pattern as the `mcp_fetch` dynamic
  tool sub-catalog.
- **Characterization discipline:** plugin skills must NOT churn the core routing
  snapshots. Snapshot runs execute with zero plugins installed; a separate fixture
  plugin exercises the plugin-skill path in its own test.
- **Prompt-pack skills (lightweight tier):** a plugin may also ship declarative
  skills — a `skills/*.md` instruction pack seeded as internal prompts (mandatory
  `tools:{pluginId}_{name}` topic prefix, enforced by the installer so a plugin can
  never inject a user-facing/classifier-selectable prompt). This is the
  OpenClaw/Claude-style "Skills" folder: no PHP needed for advisory knowledge packs.

### 3.5 Distribution — from drop-in to download

Three tiers, shipped in phases (§4):

| Tier | Delivery | Trust |
| ---- | -------- | ----- |
| **bundled** | In the core image (`hello_world`, and after extraction: whatsapp, email) | Core review |
| **official** | Curated registry: `https://plugins.synaplan.com/index.json` + signed tarballs; installed via admin UI or `app:plugin:fetch <id>` | Synaplan-signed, reviewed |
| **external** | Drop into the plugins dir yourself (today's model, unchanged) | Operator's own risk |

**Registry index** (static JSON, served from a repo-backed CDN — the docs site can
render it as a public catalog page, exactly like `docs.openclaw.ai/channels`):

```json
{
  "plugins": [
    {
      "id": "telegram",
      "displayName": "Telegram",
      "provides": ["channel"],
      "latest": "1.0.2",
      "minSynaplanVersion": "4.1",
      "sha256": "…",
      "signature": "…",
      "url": "https://plugins.synaplan.com/dist/telegram-1.0.2.tar.gz",
      "docs": "https://docs.synaplan.com/plugins/telegram"
    }
  ]
}
```

**Hard operational constraints we must design around (not around us):**

1. **`/plugins` is mounted read-only** (`./plugins:/plugins:ro`) and baked into the
   prod image. Downloads go to a SECOND, writable dir: `/plugins-external` (named
   volume). `Kernel` discovery globs both; bundled wins on id collision.
2. **PHP loads at Kernel boot** — an install/update needs a container/worker restart
   to take effect. v1 is honest about this: the admin UI marks the plugin
   "installed — restart required", and `synaplan-platform` gets a documented reload
   hook. No hot-loading magic in v1.
3. **Multi-node prod (web1/web2/web3 + Galera):** installs must be **declarative
   desired state, not imperative one-node actions**. New table `BPLUGINSTATE`
   (`plugin_id`, `version`, `sha256`, `enabled`) written by the admin action; every
   node runs a reconcile step on container start (compare state table ↔ local
   `/plugins-external`, fetch/verify what's missing). Same self-converging pattern as
   migrations-on-start.
4. **Security is non-negotiable:** plugin PHP runs fully privileged in-process — a
   plugin is code execution, full stop. Therefore: official tier only in the in-app
   installer (signature verified against a pinned Synaplan public key, sha256 checked,
   no third-party URLs), admin-only install rights, tarball extraction hardened
   (path traversal, symlink escape), and the external tier stays filesystem-only so
   installing unreviewed code always requires deliberate operator action on the host.

### 3.6 What plugins can extend — the complete seam table (target state)

| Extension point | Manifest key | Backend contract | Status |
| --------------- | ------------ | ---------------- | ------ |
| Channel | `provides.channels` | `ChannelTransportInterface`, tag `app.channel.transport` | **new (this plan)** |
| Skill (runner) | `provides.skills` | `TaskRunner` + `SkillDescriptor`, tag `app.multitask.runner` | mechanics exist, formalize |
| Skill (prompt pack) | `provides.skills` (`type: "prompt"`) | seeded `tools:{pluginId}_*` prompts | **new (this plan)** |
| Chat command | `provides.commands` | plugin controller endpoint (existing `chatCommands`) | exists, rename only |
| Connection type | `provides.connectionTypes` | `DestinationProvider` / `ConnectionTester` tags | tags exist, open to plugins |
| Context provider | `provides.contextProviders` | `PluginContextProviderInterface` | exists |
| UI page | `provides.ui` | `PluginView.vue` mount contract | exists |

---

## 4. Phased plan

### Sprint 1 (next week, 2026-08-24) — contracts + dynamic catalog

Goal: **the seams exist, nothing user-visible breaks, one pilot proves the model.**

1. `ChannelTransportInterface` + `ChannelDescriptor` + `ChannelRegistry` + generic
   webhook route; adapter-wrap WhatsApp and email (pure refactor, behavior-identical,
   existing webhook URLs stay); generalize `MessageForwardingService`.
2. `GET /api/v1/channels/catalog` (active/available groups; installable group stubbed
   empty) + full OpenAPI annotations → regenerate Zod schemas.
3. Frontend: `ConnectionsConfiguration.vue` + `InboundConfiguration.vue` render from
   the catalog endpoint; delete `mockWhatsAppChannels`; all four locales.
4. Manifest v2 parser (`provides`, `tier`, `permissions`) with back-compat for
   `chatCommands`; boot-time declaration checks.
5. **Pilot: Telegram channel plugin** in `plugins/telegram/` (bot token config,
   webhook in, text reply out — deliberately minimal) proving a channel ships as a
   plugin with zero core edits.
6. Full gate + characterization snapshots untouched (refactor must not move them).

### Sprint 2 — plugin-provided skills

1. Namespaced string capabilities for plugin skills; validator + `SkillCatalog`
   per-user filtering; fixture-plugin tests (core snapshots stay plugin-free).
2. Prompt-pack skill tier with enforced `tools:{pluginId}_` prefix in the installer.
3. Reference: give the Telegram plugin a `telegram_send` skill so a saved task /
   DAG can deliver to Telegram (mirrors `email_me`).

### Sprint 3 — download & install

1. `/plugins-external` writable volume + dual-dir Kernel discovery + hardened tarball
   installer (`app:plugin:fetch`, sha256 + signature verification).
2. `BPLUGINSTATE` desired-state table + on-boot reconcile (multi-node safe);
   "restart required" surfacing; `synaplan-platform` reload documentation.
3. Admin UI: plugin manager page (installed / available / install / enable / version),
   registry index client. Registry itself starts as a static `index.json` in a new
   `synaplan-plugins` repo + CI that builds/signs tarballs.

### Sprint 4 — the open catalog

1. Public docs catalog page listing channels/skills/plugins with tier badges
   ("bundled" / "official" / "external") — the `docs.openclaw.ai/channels` equivalent,
   generated from the registry index so docs and installability never drift.
2. Second official channel (candidate: Slack or Signal — decide by demand), authored
   from the plugin-developer guide to validate the docs.
3. Plugin developer guide (`docs/PLUGINS.md` rewrite): manifest v2 reference, channel
   + skill contracts, the security model, and the review checklist for the official tier.

---

## 5. Non-goals (v1)

- **No hot-loading** of PHP without restart, no plugin sandboxing/process isolation.
  A plugin is trusted code; the trust model is curation + signatures, not containment.
- **No paid marketplace / third-party self-publishing.** Official tier is curated by us.
- **No rewrite of WhatsApp/email internals** — adapters only. `WhatsAppService` stays;
  it just stops being load-bearing for the *shape* of the system.
- **No per-user channel plugins with secrets in the browser** — channel credentials
  stay server-side (`plugin_data` encrypted / `BCONFIG`), as today.
- Widget/web/SSE transports stay core (they're product surface, not integrations).

## 6. Definition of done (platform level)

- A new conversation channel ships as a plugin directory with **zero core edits**:
  manifest + transport class + config schema + icon + i18n. Proven by Telegram.
- `/channels` renders its catalog from the API; installing/enabling a channel plugin
  makes its card appear without a frontend deploy.
- A plugin can contribute a skill that appears in the planner catalog **only** for
  users who have the plugin, without touching core routing snapshots.
- `app:plugin:fetch telegram` on a fresh self-hosted install downloads, verifies,
  installs; after restart the channel is configurable. Same result on a 3-node
  platform deploy via the state table.
- A tampered or unsigned tarball is rejected; a plugin declaring an undeclared
  channel/skill fails at boot with a clear error.
- Full gate green; existing WhatsApp/email/widget behavior byte-identical
  (characterization + webhook contract tests).

## 7. Open questions (decide before Sprint 1 ends)

1. **Naming in the UI:** keep "Channels" for conversation transports and "Connections"
   for linked systems (recommended — matches `channels-planning.md` §1), or merge the
   nav into a single "Integrations" hub with type filters?
2. **Telegram pilot scope:** webhook-only (needs public URL — fine for prod, awkward
   for local dev) vs. also long-polling worker (nicer dev story, more moving parts)?
3. **Where does per-user channel config live** — `plugin_data` (`type=config`) like
   plugin settings, or `BCONNECTIONS` rows with a new `channel` type so testers/status
   pills come free? (Leaning `BCONNECTIONS`: one status/vault story for everything.)
4. **Registry hosting:** static JSON in a public `synaplan-plugins` repo served via
   Pages/CDN (recommended, auditable) vs. an API on the platform?
5. **Permission enforcement:** when do `permissions` become enforced (e.g. egress
   allowlist per plugin) rather than declarative? Realistic answer: post-v1 hardening;
   confirm we accept declare-only for now.
6. **Do Synaform / synafastbill migrate to manifest v2 immediately** or ride
   back-compat until Sprint 3? (Back-compat exists either way.)

## 8. File index (planned touch points)

| Area | Paths |
| ---- | ----- |
| Channel seam (new) | `backend/src/Service/Channel/ChannelTransportInterface.php`, `ChannelDescriptor.php`, `ChannelRegistry.php`, `InboundMessage.php`, `DeliveryContext.php` |
| Adapters | `backend/src/Service/Channel/Transport/WhatsAppTransport.php`, `EmailTransport.php` (wrapping existing services) |
| Generic webhook | `backend/src/Controller/ChannelWebhookController.php` (aliases keep old routes) |
| Catalog API | `backend/src/Controller/ChannelCatalogController.php` + OpenAPI + generated Zod schemas |
| Manifest v2 | `backend/src/Service/Plugin/PluginManifest.php` (extend), boot checks in `Kernel.php` |
| Skills opening | `Service/Multitask/Skill/SkillCatalog.php` (per-user plugin skills), `Plan/TaskPlanValidator.php`, prompt-pack seeding in `PluginManager.php` |
| Installer (S3) | `backend/src/Service/Plugin/PluginFetcher.php`, `Command/PluginFetchCommand.php`, `BPLUGINSTATE` migration, reconcile in entrypoint |
| Frontend | `ConnectionsConfiguration.vue`, `InboundConfiguration.vue`, new `channelCatalogApi.ts`, admin plugin manager view (S3), i18n ×4 |
| Pilot plugin | `plugins/telegram/` (manifest v2, transport, config schema, i18n, tests) |
| Docs | `docs/PLUGINS.md` (rewrite), registry repo `synaplan-plugins` (S3/S4), public catalog page (S4) |
