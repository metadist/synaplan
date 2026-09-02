# Collabora integration (Synaplan inside the editor)

**Status:** planned (2026-09-02). No product code in this change.
**Sibling:** [`../20260902-office-docs/`](../20260902-office-docs/) — create,
analyse, convert, merge and edit office files *inside Synaplan*.

This directory is the **plan of record** for the Collabora side. Cursor
session `.plan.md` files are not authoritative.

| File | Role |
| ---- | ---- |
| [`00_master_plan.md`](./00_master_plan.md) | Scope, surfaces, decisions, epic order |
| [`01_epic_0_wopi_host.md`](./01_epic_0_wopi_host.md) | Synaplan WOPI host + editor view |
| [`02_epic_1_ai_assistant_provider.md`](./02_epic_1_ai_assistant_provider.md) | Collabora 26.04 AI Assistant → Synaplan `/v1/chat/completions` (built as office-docs Phase T) |
| [`03_epic_2_synaplan_extension.md`](./03_epic_2_synaplan_extension.md) | iframe-hosted Synaplan extension |
| [`04_epic_3_mcp_and_tasks.md`](./04_epic_3_mcp_and_tasks.md) | Collabora MCP from Synaplan tasks |
| [`05_epic_4_partner_platforms.md`](./05_epic_4_partner_platforms.md) | Nextcloud / OpenCloud / ownCloud |
| [`STATUS.md`](./STATUS.md) | Step table and decision log |

**Shared with office-docs:** Collabora CODE sidecar (A0) and Phase T tool
calling (Epic 1.1). Build those once; do not duplicate them here.
