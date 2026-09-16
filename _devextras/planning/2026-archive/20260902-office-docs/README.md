# Office documents (Synaplan side)

**Status:** planned (2026-09-02). No product code in this change.
**Sibling:** [`../20260902-collabora-integration/`](../20260902-collabora-integration/)
— Synaplan *inside* the Collabora editor.

This directory is the **plan of record**. Cursor session `.plan.md` files are
not authoritative; everything from those sessions lives here.

| File | Role |
| ---- | ---- |
| [`00_master_plan.md`](./00_master_plan.md) | v3 master: scope, Decisions 1–3, phase order |
| [`office-plan_v3.md`](./office-plan_v3.md) | English v3 write-up (session plan persisted) |
| [`office-plan_v2.md`](./office-plan_v2.md) | Original structured-editing design (Phase B detail) |
| [`03_phase_t_tool_calling_gateway.md`](./03_phase_t_tool_calling_gateway.md) | Phase T — tool calling + MCP/web search on `/v1/chat/completions` (**first**) |
| [`01_phase_a_engine_and_ux.md`](./01_phase_a_engine_and_ux.md) | Phase A — converter, UX, A0-docs in `synaplan-docs` |
| [`02_phase_b_structured_editing.md`](./02_phase_b_structured_editing.md) | Phase B — xlsx-first model + true merge |
| [`STATUS.md`](./STATUS.md) | Step table and decision log |

**Order of work:** Phase T → Phase A (including A0-docs) → Phase B.
Collabora editor / WOPI / partner platforms are not in this directory.

**Two delivery surfaces** (see `00_master_plan.md`): the public OSS repo
(`synaplan`, engine optional) and our hosted demo (`synaplan-platform`,
engine on). Phase T is OSS-only. A0 is the first dual-repo step.
