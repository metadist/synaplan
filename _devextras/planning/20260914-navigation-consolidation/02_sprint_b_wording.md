# Sprint B — Wording pass

**Steps `NV10`–`NV12`.** Every menu label, page title and tab label says the
same thing in the same case, in five locales, with no implementation words
and no glitches ("Welcome to synaplan!" on the Usage page, "List Of All").

**Goal:** a non-technical user reads the menu and the page header and they
agree; a translator sees one canonical term per concept.

**Depends on:** Sprint A (labels for the surfaces that moved). Run after
NV01–NV08 so the pass covers the final tree. **Do not start on the NV06
branch.** New branch `feat/nav-nv10-wording` from `origin/main` after A.

**Progress 2026-09-15:** not started. NV06 already shipped the *menu* strings
`nav.configConnections` = Connected apps and `nav.groupDeveloper`. This
sprint still owns the matching **page titles**, the Manage tooltip, and the
rest of the table.

**UX exit:** U3 (kind-specific, plain words), U9 (no visual change), i18n
parity. No journey of its own — the wording is checked while walking
J-NV-1 … J-NV-6.

---

## 0. House rules applied here

1. **Sentence case** for every label, title, tab and button: "System
   configuration", "API keys", "MCP servers". Proper nouns keep their case
   (API, MCP, Nextcloud, Higgsfield).
2. **Menu label = page title = breadcrumb.** One key per concept; if two keys
   exist (`nav.adminFeatureStatus` vs `settings.features.title`), the page
   uses the nav key or both are set to the same string in the same PR.
3. **Canonical nouns** (AGENTS.md "UI copy & wording" + streamlining plan):
   chat widget, AI assistant, AI Setup Assistant, Sources, Channels,
   Automations, Connections, Operate, People, trigger (event / schedule).
4. **Banned in primary copy:** node, DAG, ACL, tenant, sandbox, container,
   auth code, provisioning, stdout, prompt topic, vector (outside Operate),
   "tool" as a page name.
5. **German et al.:** translate, do not transliterate. `Inbound` → not
   "Eingang" for a page that lists channels.

---

## 1. NV10 — Before / after table

Apply exactly; every row lands in `en`, `de`, `es`, `fr`, `tr`. Where a de
value is given it is the target, not a suggestion.

| Key | Before (en) | After (en) | After (de) | Note |
| --- | ----------- | ---------- | ---------- | ---- |
| `statistics.title` | Welcome to synaplan! | My usage | Meine Nutzung | Glitch. Page header of `/statistics` |
| `nav.statistics`, `pageTitles.statistics` | Usage | My usage | Meine Nutzung | Disambiguates from Operate usage |
| `config.usage.title` | Usage Statistics | Usage | Nutzung | Section headline inside the page |
| `config.usage.description` | Track your API usage across all channels and features | What you used in chats, channels and automations | Was Sie in Chats, Kanälen und Automatisierungen genutzt haben | Drops "API" |
| `admin.tabs.usage` | Usage | Usage (all users) | Nutzung (alle Nutzer) | `O006` |
| `admin.tabs.users` | Users | *(delete after NV01 if unused)* | — | `O004` gone |
| `nav.configInbound`, `pageTitles.configInbound` | Inbound | Channels overview | Kanalübersicht | `M040`; DE "Eingang" was wrong |
| `nav.configConnections` | Configure Connections | Connected apps | Verbundene Apps | `M060` — **done in NV06** (menu child only) |
| `config.connections.title`, `pageTitles.connections` | Connections | Connected apps | Verbundene Apps | Page header follows the menu; the *group* stays "Connections". **Still this PR** — Copilot asked on [#1917](https://github.com/metadist/synaplan/pull/1917); we deferred it here |
| `nav.groupDeveloper` (new) | — | Developer & devices | Entwickler & Geräte | NV06 group — **key already in five locales** |
| `nav.manageDescription` | Assistants, automations, channels and connections | Assistants, automations, channels, connections and developer tools | Assistenten, Automatisierungen, Kanäle, Verbindungen und Entwicklertools | Manage rail `title` in `SidebarV2.vue`. Copilot on #1917; do it here |
| `nav.adminFeatureStatus`, `settings.features.title`, `pageTitles.adminFeatures` | Feature Status / System Status | System status | Systemstatus | `O010`, one name |
| `settings.features.subtitle` | Monitor all services and features | Which services this installation can use right now | Welche Dienste diese Installation gerade nutzen kann | |
| `nav.adminModelStatus`, `adminModelStatus.title`, `pageTitles.adminModelStatus` | Model Status | Model health | Modellzustand | `O011` |
| `pageTitles.adminConfig` | System Configuration | System configuration | Systemkonfiguration | Case |
| `nav.mcpServers`, `pageTitles.mcpServers` | MCP Servers | MCP servers | MCP-Server | Case |
| `nav.configApiKeys`, `pageTitles.configApiKeys` | API Keys | API keys | API-Schlüssel | Case |
| `pageTitles.configApiDocs` | API Documentation | API documentation | API-Dokumentation | Case |
| `config.aiModels.tabs.choice` | Model Choice | Default models | Standardmodelle | `M011` |
| `config.aiModels.tabs.list` | List Of All | All models | Alle Modelle | `M012` |
| `config.aiModels.tabs.runs` | Vector Runs | Embedding runs | Embedding-Läufe | `M013`, admin tab |
| `config.aiModels.tabs.edit`, `config.aiModels.admin.editModels` | Edit Models | Model catalog | Modellkatalog | `M014`, admin tab |
| `adminSetup.cloudProviders` | Cloud providers | Provider keys | Anbieter-Schlüssel | After NV05 the grid holds every key |
| `adminSetup.cloudProvidersHint` | Paste an API key, it is tested live… | One place for every provider key — chat, image, video and speech. Paste a key; it is tested and stored encrypted. Keys from your .env file are picked up automatically. | *(translate)* | |
| `nav.aiAccounts`, `pageTitles.aiAccounts` (new) | — | Your AI accounts | Ihre KI-Konten | NV04 |
| `people.tabs.linkedPlatforms` | Linked platforms | Platform instances | Plattform-Instanzen | NV03 |
| `pageTitles.allChats`, `chats.tabs.all`, `chats.tabs.incoming` (new) | — | All chats · All · Incoming | Alle Chats · Alle · Eingehend | NV02 |
| `chatInput.tools.summarize`, `chatInput.tools.summarizeDesc` (new) | Summarizer · Summarize documents — opens the tool | Summarize a document · Attach a file and get a summary in this chat | Dokument zusammenfassen · Datei anhängen und die Zusammenfassung hier im Chat erhalten | NV07 |
| `nav.adminDashboard` | Overview | Overview | Übersicht | unchanged, listed for completeness |
| `nav.adminProviderSetup`, `adminSetup.title`, `pageTitles.adminSetup` | AI infrastructure | AI infrastructure | KI-Infrastruktur | unchanged |

**Placeholder discipline:** none of these keys carries placeholders; the
parity test still compares names — keep `{count}` etc. untouched elsewhere.

**Locale ledger:** every key translated here that has a row in
`tests/unit/i18n/localeParityBaseline.json` gets that row deleted in the same
commit. Never add a row.

**Machine instructions**

1. Apply the table to the five locale files (script allowed for the rename of
   values; review the diff line by line).
2. `rg` every changed English string across `frontend/src` (breadcrumbs,
   `helpId` copy, `docs/`) and update prose that names the old label
   ("the 'Feature Status' page").
3. `PeopleView.vue` back link `people.backToOperate` stays "Back to Operate".
4. `synaplan-docs`: only in NV22, not here.

**Tests**

- `localeParity.spec.ts` green; ledger shrinks.
- E2E specs that assert visible text (`navigation.spec.ts` "User menu shows
  Profile, Statistics, Preferences and Logout", `admin-panel.spec.ts`,
  `feature-status.spec.ts`) switch to test ids where they still match text;
  where text is the point, update to the new string.

**Commit:** `fix(i18n): navigation and page-title wording pass (five locales)`

---

## 2. NV11 — Unused keys and hardcoded strings

**Machine instructions**

1. Delete unused nav keys (verified `rg` count 0 today): `nav.aiSetup`,
   `nav.aiSetupDescription`, `nav.groupTools`, `nav.channelsDescription`,
   `nav.groupApi`, `nav.usage`. Re-run the count before deleting; the widget
   bundle imports the same locale files, so also `rg` in `frontend/src/widget*`.
2. Replace hardcoded copy on nav-adjacent pages: `views/ToolsView.vue:150`
   "Loading mail handlers..." → `$t('tools.mailHandler.loading')`.
3. Translate the two German code comments to English:
   `views/FeatureStatusView.vue:184`, `views/MemoriesView.vue:147` (rule:
   comments are English).
4. `FeatureStatusView.vue` category headings (`'AI Features'`, …) come from
   the backend as English strings — leave as-is but note it in NV16 (they
   become i18n keys keyed by `category` there).

**Tests**

- New unit `tests/unit/i18n/unusedNavKeys.spec.ts`: for every key under
  `nav.*` in `en.json`, assert at least one usage in `frontend/src` (a
  literal `nav.<key>` string). Fails on the next stale key.
- ESLint already forbids nothing here; the spec is the guard.

**Commit:** `chore(i18n): remove unused nav keys and hardcoded strings on nav-adjacent pages`

---

## 3. NV12 — Wording guard

**Machine instructions**

1. New unit `tests/unit/i18n/navWording.spec.ts`:
   - Every value under `nav.*`, `pageTitles.*`, `admin.tabs.*`,
     `people.tabs.*`, `adminSetup.tabs.*`, `config.aiModels.tabs.*` in
     `en.json` is sentence case: first character upper, no other capital
     unless the word is in an allow-list (`API`, `MCP`, `AI`, `PDF`, `OIDC`,
     proper nouns: `Nextcloud`, `Higgsfield`, `Anthropic`, `Microsoft 365`,
     `WhatsApp`, `Outlook`, `Synaplan`, `Ollama`).
   - None of those values contains a banned word (§0.4 list) in any of the
     five locales (case-insensitive).
   - `nav.<x>` and `pageTitles.<x>` that share a suffix in the pairs table
     inside the spec (`adminFeatures`, `adminModelStatus`, `adminConfig`,
     `configApiKeys`, `mcpServers`, `configInbound`, `statistics`,
     `aiAccounts`, `allChats`) are equal in every locale.
2. Wire nothing new into CI — Vitest runs it.

**Commit:** `test(i18n): wording guard for banned nav words and sentence case`

---

## 4. Exit

1. Table in §1 applied; `git diff` of the five locale files reviewed.
2. `navWording.spec.ts`, `unusedNavKeys.spec.ts`, `localeParity.spec.ts` green.
3. The Confluence "Label ≠ URL" observation is reduced to the accepted set:
   Sources = `/files`, Operate = `/admin`, My usage = `/statistics`,
   Preferences = `/settings`, Coding clients = `/channels/agents`. Listed in
   the master plan §2 as known and intentional (URLs are not user copy).
