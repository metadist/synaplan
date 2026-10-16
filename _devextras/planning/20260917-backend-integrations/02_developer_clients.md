# Developer clients — Claude Code, VS Code, Cursor, Neovim

**Status:** Plan 2026-09-17. Product code waits on master plan §0 and
BI1 (Integrations page).
**Ask:** a developer uses **their** Synaplan the way they use Claude
Code: one URL, one key, one model. No second cloud account.

[Neovim](https://neovim.io/) is the hyperextensible Vim-based editor
with a first-class Lua API and a built-in LSP client. VS Code and
Cursor are the other two editors we will ship a client for. Cursor
speaks VS Code’s extension surface; we do not maintain a third
codebase.

---

## 0. What already works

Claude Code against Synaplan is **done**
([`docs/ANTHROPIC_COMPATIBLE_API.md`](../../../docs/ANTHROPIC_COMPATIBLE_API.md)):

```bash
export ANTHROPIC_BASE_URL="https://your-synaplan-host"
export ANTHROPIC_API_KEY="sk_your_synaplan_api_key"
claude
```

The Messages gateway translates `/v1/messages` to whatever chat model
the account actually has. Desktop uses the same face. Wave 6 must not
invent a fourth protocol for editors.

Continue, Aider, and Zed already speak OpenAI or Anthropic. BI2
publishes snippets. We do not fork them.

---

## 1. Product stance

| We will | We will not |
| ------- | ----------- |
| Ship a thin client that talks to Synaplan | Embed `web.synaplan.com` in a WebView |
| Let the user pick chat (and later STT) models on the Integrations page | Hard-code `claude-…` or `gpt-…` |
| Store the key in the editor’s secret store / `secrets` | Put `sk_` in a committed `init.lua` |
| Use RAG / memories only when the user turns **Use my knowledge** on | Silently inject the whole corpus |
| Work offline against a local Synaplan + Ollama | Require Anthropic’s cloud |

User-facing name: **Synaplan**. Never “Claude plugin”, “Copilot”,
“LSP backend” in primary copy.

---

## 2. Shared client contract

Every editor client implements the same three operations. The UI
chrome differs; the HTTP does not.

| Operation | Face | Notes |
| --------- | ---- | ----- |
| Chat / agent turn | `POST /v1/messages` (preferred) or `POST /v1/chat/completions` | Stream when the editor can show tokens |
| List models | `GET /v1/models` | Show only ids the key may use; include `assistant:<slug>` when published |
| Optional: transcribe a selection / voice note | `POST /v1/audio/transcriptions` | Same STT picker as openDesk |

Config shape (all three editors):

```json
{
  "baseUrl": "https://synaplan.example.org",
  "apiKey": "(os secret store)",
  "model": "assistant:contract-review",
  "useKnowledge": false
}
```

The Integrations page emits this as:

- Claude Code: three `export` lines
- VS Code / Cursor: `"synaplan.*"` keys in User Settings
- Neovim: a `require('synaplan').setup({ … })` block

---

## 3. VS Code and Cursor (BI3)

**Repo:** `synaplan-vscode` (new, public). One extension;
Cursor loads it as a VS Code extension.

**v1 surface**

- Side bar chat (one thread, stream, stop).
- Command Palette: *Synaplan: Ask about this file*, *Synaplan: Explain
  selection*, *Synaplan: Connect*.
- Status bar: instance host + model name. Click opens the Connect
  snippet if disconnected.
- `useKnowledge` toggle. Off by default.

**v1 out**

- Agent Skills / local shell (that is Desktop).
- Inline ghost text (Copilot-style). Revisit after v1; it is a
  different UX and a different quota story.

**Auth**

- `vscode.SecretStorage` for the key.
- Connect command opens the Synaplan Integrations URL with a
  `redirect` that the extension’s local callback can read — **or**,
  simpler v1: paste key + URL (Claude Code path). Prefer paste-key
  for v1; handshake is v2.

**Tests**

- Contract fixtures against the frozen OpenAPI snippets.
- No network in unit tests.

---

## 4. Neovim (BI4)

**Repo:** `synaplan-nvim` (new, public). Lua, `init.lua` friendly,
no Vimscript-only API.

**v1 surface**

- `:SynaplanChat` — floating or split buffer, stream into it.
- `:SynaplanAsk` on a visual selection.
- `:SynaplanConnect` prints the snippet and opens the Integrations
  URL if `netrw` / `xdg-open` works.
- Health: `:checkhealth synaplan` (URL, key present, `GET /v1/models`).

**v1 out**

- Replacing the built-in LSP client. We are a chat/tool plugin, not
  an LSP server.
- `vim.system` shell tools. Same rule as Desktop: no constructed
  shell.

**Config**

```lua
require('synaplan').setup({
  base_url = vim.env.SYNAPLAN_URL,
  -- key from $SYNAPLAN_API_KEY or a secret manager; never a literal
  model = 'assistant:contract-review',
  use_knowledge = false,
})
```

Ship as a rock / `:Lazy` spec. Document both.

---

## 5. Journeys

| Id | Journey |
| -- | ------- |
| J-ED-1 | Empty Integrations → Claude Code card → copy exports → `claude` answers with the picked model. |
| J-ED-2 | VS Code: Connect → settings written → *Ask about this file* streams; Disconnect clears the secret. |
| J-ED-3 | Neovim: `setup` + `:checkhealth` green → visual ask → answer buffer; flag-off on the server returns one understandable sentence. |

Empty / error copy: “Could not reach your Synaplan at {host}. Check
the address or disconnect.” Never an HTTP status as the only text.

---

## 6. Order

1. BI1 ships the Claude Code card (already works — we only add the
   form).
2. BI3 VS Code / Cursor.
3. BI4 Neovim (can start the day BI3’s HTTP helper is extracted, or
   independently — the contract is HTTP, not an npm package).

Do not block Neovim on a shared TypeScript SDK. Duplicate the three
calls. A shared SDK is a later extraction if both clients exist.
