# Feature flags

Every feature that shipped in the September 2026 waves is behind a flag in
`BCONFIG` (owner `0`). All of them are **on by default**: a fresh install seeds
them on, and the migration `Version20260911090000` turns the existing global
rows on for installs that were created earlier. This page lists the flags,
where to switch them, and how an automated deployment pins one off.

## Three ways to switch a flag

| Where | Who | Notes |
| ----- | --- | ----- |
| **Operate → System configuration → Features** (`/admin/config?tab=features`) | administrator, in the browser | Takes effect immediately; the page reloads the runtime config so menus and pages appear or disappear without a restart. |
| Environment variable `FEATURE_<GROUP>_<SETTING>` | Helm chart, compose stack, CI image | `false` pins the feature **off**, `true` pins it **on**, unset or empty lets the database decide. The admin toggle then shows as locked and names the variable. |
| SQL on `BCONFIG` | automation that already talks to the database | `INSERT … ON DUPLICATE KEY UPDATE BVALUE = '0'` on the `BOWNERID = 0` row. |

Precedence is **environment → per-user row → group policy → global row → code
fallback**, the same order `REGISTRATION_ENABLED` and `GUEST_CHAT_ENABLED`
use. An unrecognised environment value (anything that is not a boolean
spelling such as `true`, `false`, `1`, `0`, `on`, `off`, `yes`, `no`) is
ignored rather than guessed.

The variable name is derived from the `BCONFIG` key: `IAM.SHARING_ENABLED`
becomes `FEATURE_IAM_SHARING_ENABLED`, `MODULES.GATE_TIKA` becomes
`FEATURE_MODULES_GATE_TIKA`. The same name is the field key in the admin UI.

Example for a Kubernetes values file that ships an instance without the
desktop client and without group policies:

```yaml
env:
  FEATURE_DESKTOP_AGENT_ENABLED: "false"
  FEATURE_IAM_GROUP_POLICIES_ENABLED: "false"
```

The self-hosted stack (`deploy/compose.yaml`) forwards every fixed
`FEATURE_*` variable from `selfhost.env` to the backend and worker with an
empty default, exactly like `REGISTRATION_ENABLED`. Module gates
(`FEATURE_MODULES_GATE_<ID>`) are one variable per module and are added in a
compose override when a deployment needs one. The local dev stack loads
`backend/.env` as an `env_file`, so any `FEATURE_*` line there applies
directly.

## Features switched on in the last waves

Everything below merged to `main` between 2026-09-03 and 2026-09-11 and is
now on by default. "What users see" is what disappears when the flag is off.

| Wave | Feature | What users see | `BCONFIG` key | Environment variable | Default | Shipped in |
| ---- | ------- | -------------- | ------------- | -------------------- | ------- | ---------- |
| 1 | People & groups | **Operate → People** (users, groups, audit); **Account → My groups**; group API | `IAM.GROUPS_ENABLED` | `FEATURE_IAM_GROUPS_ENABLED` | on | [#1708](https://github.com/metadist/synaplan/pull/1708), [#1742](https://github.com/metadist/synaplan/pull/1742) |
| 1–2 | Sharing | **Share** on folders, chats, AI assistants, saved tasks and widgets; "Shared with me" filters and pills | `IAM.SHARING_ENABLED` | `FEATURE_IAM_SHARING_ENABLED` | on | [#1713](https://github.com/metadist/synaplan/pull/1713), [#1714](https://github.com/metadist/synaplan/pull/1714), [#1717](https://github.com/metadist/synaplan/pull/1717) |
| 2 | Directory groups | Groups filled from the company login (OIDC groups claim); People → Audit | `IAM.DIRECTORY_SYNC_ENABLED` | `FEATURE_IAM_DIRECTORY_SYNC_ENABLED` | on | [#1718](https://github.com/metadist/synaplan/pull/1718) |
| 2 | Group policies | **People → Policies**: default and allowed models, feature switches and rate-limit tier per group; locked defaults | `IAM.GROUP_POLICIES_ENABLED` | `FEATURE_IAM_GROUP_POLICIES_ENABLED` | on | [#1719](https://github.com/metadist/synaplan/pull/1719), [#1722](https://github.com/metadist/synaplan/pull/1722) |
| 2–3 | AI assistants (agent builder) | The assistant builder (instructions, knowledge folders, tools, publish as widget), `/api/v1/agents`, shared knowledge folders | `AGENTS.ENABLED` | `FEATURE_AGENTS_ENABLED` | on | [#1738](https://github.com/metadist/synaplan/pull/1738), [#1763](https://github.com/metadist/synaplan/pull/1763), [#1769](https://github.com/metadist/synaplan/pull/1769) |
| 3 | Assistants in routing | The message sorter may answer with an assistant marked "reachable from chat" | `AGENTS.ROUTABLE_ENABLED` | `FEATURE_AGENTS_ROUTABLE_ENABLED` | on | [#1769](https://github.com/metadist/synaplan/pull/1769) |
| 3 | Export / import bundles | Move assistants, instructions and saved tasks between installs (`/api/v1/bundles`) | `BUNDLE.ENABLED` | `FEATURE_BUNDLE_ENABLED` | on | [#1769](https://github.com/metadist/synaplan/pull/1769) |
| 3 | AI plugs: extraction, web search, rerank, model import, plugin adapters | **Operate → AI infrastructure** tabs (Extraction, Web search, Rerank); Docling and SearXNG providers | – (provider configuration; optional module gates below) | – | on | [#1750](https://github.com/metadist/synaplan/pull/1750), [#1756](https://github.com/metadist/synaplan/pull/1756), [#1758](https://github.com/metadist/synaplan/pull/1758), [#1760](https://github.com/metadist/synaplan/pull/1760), [#1761](https://github.com/metadist/synaplan/pull/1761), [#1762](https://github.com/metadist/synaplan/pull/1762) |
| 3 | Watched pages (URL watch) | **Saved Tasks → Watched pages**: one saved copy per address, scheduled compare and diff mail; "get this URL" in chat | `MULTITASK.URL_FETCH_ENABLED` | `FEATURE_MULTITASK_URL_FETCH_ENABLED` | on | [#1754](https://github.com/metadist/synaplan/pull/1754), [#1813](https://github.com/metadist/synaplan/pull/1813) |
| 3 | Linked platforms | Nextcloud / ownCloud / OpenCloud users link their existing Synaplan account; **Operate → Linked platforms**, **Account → Linked platforms** | `PLATFORM_LINKS.ENABLED` | `FEATURE_PLATFORM_LINKS_ENABLED` | on | [#1745](https://github.com/metadist/synaplan/pull/1745) |
| 4 | Tool registry | One registry of callables (MCP, custom HTTP, built-in) for the assistant and Saved Tasks | `TOOLS.REGISTRY_ENABLED` | `FEATURE_TOOLS_REGISTRY_ENABLED` | on (kill switch) | [#1774](https://github.com/metadist/synaplan/pull/1774) |
| 4 | Approvals | Ask before a tool that changes or deletes something runs; unattended Saved Tasks pause under **Approvals** | `TOOLS.APPROVALS_ENABLED` | `FEATURE_TOOLS_APPROVALS_ENABLED` | on | [#1774](https://github.com/metadist/synaplan/pull/1774) |
| 4 | Custom tools | Users declare their own HTTP / OpenAPI tools under **Connections** | `TOOLS.CUSTOM_HTTP_ENABLED` | `FEATURE_TOOLS_CUSTOM_HTTP_ENABLED` | on | [#1774](https://github.com/metadist/synaplan/pull/1774) |
| 5 | Saved Tasks Steps editor & webhook trigger | **Steps** on a saved task (tool step, condition, outbound webhook, ask-before-run); webhook trigger | `WORKFLOWS.BUILDER_ENABLED` | `FEATURE_WORKFLOWS_BUILDER_ENABLED` | on | [#1821](https://github.com/metadist/synaplan/pull/1821) |
| 5 | Desktop client (public beta) | **Channels → Desktop**: pairing codes, connected computers, job queue; download link to [synaplan-desktop](https://github.com/metadist/synaplan-desktop) | `DESKTOP_AGENT.ENABLED` | `FEATURE_DESKTOP_AGENT_ENABLED` | on | [#1669](https://github.com/metadist/synaplan/pull/1669), [#1808](https://github.com/metadist/synaplan/pull/1808) |
| – | Office document tools | The assistant builds and revises Word, Excel and PowerPoint files step by step | `DOCUMENT_TOOLS.ENABLED` | `FEATURE_DOCUMENT_TOOLS_ENABLED` | on | [#1685](https://github.com/metadist/synaplan/pull/1685) |
| Intermezzo | Optional module gates | Hide an unconfigured module (Tika, Docling, SearXNG, Piper TTS, Collabora, WhatsApp, Stripe, mobile IAP, Google AI, Higgsfield, TheHive, local AI): its routes answer 404 and no card is shown | `MODULES.GATE_<ID>` | `FEATURE_MODULES_GATE_<ID>` | **off** (module stays visible with a "needs setup" state) | [#1815](https://github.com/metadist/synaplan/pull/1815), [#1816](https://github.com/metadist/synaplan/pull/1816), [#1817](https://github.com/metadist/synaplan/pull/1817) |

Two related switches keep their previous defaults on purpose:

- `DOCUMENT_TOOLS.ALLOW_UPLOAD_EDIT` stays **off** — editing files a user
  uploaded is a separate, opt-in decision (Features → Office documents only
  switches the tools themselves).
- `SAVEDTASKS.ENABLED` was already on; it is listed under **Routing** and is
  unchanged.

## Where each flag lives in the admin UI

| Features tab section | Flags |
| -------------------- | ----- |
| People & sharing | `FEATURE_IAM_GROUPS_ENABLED`, `FEATURE_IAM_SHARING_ENABLED`, `FEATURE_IAM_GROUP_POLICIES_ENABLED`, `FEATURE_IAM_DIRECTORY_SYNC_ENABLED` |
| AI assistants | `FEATURE_AGENTS_ENABLED`, `FEATURE_AGENTS_ROUTABLE_ENABLED`, `FEATURE_BUNDLE_ENABLED` |
| Saved tasks & watched pages | `FEATURE_WORKFLOWS_BUILDER_ENABLED`, `FEATURE_MULTITASK_URL_FETCH_ENABLED` |
| Tools & approvals | `FEATURE_TOOLS_REGISTRY_ENABLED`, `FEATURE_TOOLS_APPROVALS_ENABLED`, `FEATURE_TOOLS_CUSTOM_HTTP_ENABLED` |
| Office documents | `FEATURE_DOCUMENT_TOOLS_ENABLED` |
| Desktop & partner platforms | `FEATURE_DESKTOP_AGENT_ENABLED`, `FEATURE_PLATFORM_LINKS_ENABLED` |
| Optional modules | `FEATURE_MODULES_GATE_<ID>` for every declared module |

The non-boolean companions (directory claim path, group display names,
everyone-shares policy, tool policies per class, approval expiry) stay on the
**Access → Sharing** and **Routing → Tool policies** tabs.

## For developers

- `App\Service\Feature\FeatureFlagEnv` derives the variable name
  (`FeatureFlagEnv::envVarFor($group, $setting)`) and reads the pin. Every
  `*Config` resolver (`IamConfig`, `AgentConfig`, `ToolsConfig`,
  `WorkflowsConfig`, `PlatformLinksConfig`, `DesktopAgentConfig`,
  `DocumentToolsConfig`, `BundleConfig`, `MultitaskRoutingConfig`,
  `SavedTaskConfig`, `ModuleGateConfig`) checks it before the BCONFIG layers.
- The admin schema (`SystemConfigService::featureSections()`) keys every
  Features-tab field by that same variable name; a unit test asserts the two
  never drift.
- Seeders under `backend/src/Seed/` insert the global row as `'1'` when it is
  missing and never overwrite an operator's value. A new flag therefore needs
  three things: a seeder row, a `FEATURE_*` field on the Features tab, and —
  when existing installs must pick up the new default — a raw, idempotent
  `UPDATE` migration like `Version20260911090000`.
- The code fallback when **no row exists at all** stays `false` for most
  flags, so unit tests and the routing characterization snapshots run with
  the features off unless they opt in.

See also [CONFIGURATION.md](CONFIGURATION.md), [ADMIN.md](ADMIN.md) and
[DESKTOP.md](DESKTOP.md).
