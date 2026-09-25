# Wave 6 — Backend integrations

**Status:** Plan drafted 2026-09-17. No product code until
[`00_master_plan.md`](./00_master_plan.md) §0 is ticked.
**Live overview:** [`../20260925_roadmap.md`](../20260925_roadmap.md) §4–§5 (row 2 openDesk minimum, rows 3–4 catalog and clients).
**Binding UX:** [`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md).

Use **your** Synaplan instance the way people use Claude Code today: point
the tool at the API, pick a model, work. This folder is the collection of
those tools and the contract they share.

| File | Content |
| ---- | ------- |
| [`00_master_plan.md`](./00_master_plan.md) | Contract, Connect UI, decision checklist, sprints |
| [`01_catalog.md`](./01_catalog.md) | 20+ open-source consumers, grouped, with a state |
| [`02_developer_clients.md`](./02_developer_clients.md) | Neovim, VS Code, Cursor, Claude Code |
| [`03_office_and_mail.md`](./03_office_and_mail.md) | Outlook (have), Word, Excel, Thunderbird, OX |
| [`04_opendesk_audio_transcriber.md`](./04_opendesk_audio_transcriber.md) | **Flagship.** Element + Jitsi STT |
| [`STATUS.md`](./STATUS.md) | Step log |

**Not this track:** in-process `plugins/` packaging
([archived open-plugin-platform](../2026-archive/20260822-open-plugin-platform/README.md)),
Secure Compute, Desktop Agent Skills. Those stay in their own folders.
A Wave 6 adapter **calls** Synaplan; it does not become a PHP plugin
unless STATUS says so.
