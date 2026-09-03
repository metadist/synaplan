# n8n ↔ Synaplan — Concrete Recipes

> Companion to `n8n-integration-research.md`. Step-by-step node configs and
> example payloads. Replace `https://your-synaplan.example.com` with your
> instance URL and `sk_xxx` with a real Synaplan API key
> (**Settings → API Keys**, or `POST /api/v1/apikeys`).

All recipes use **one** Synaplan API key. Nothing here requires a Synaplan code
change — these are stock n8n nodes against existing endpoints.

---

## 0. Create the API key

UI: **Settings → API Keys → New**. Copy the `sk_…` value (shown once).

Or via API (using an existing session/key):

```bash
curl -X POST https://your-synaplan.example.com/api/v1/apikeys \
  -H "Authorization: Bearer sk_existing" \
  -H "Content-Type: application/json" \
  -d '{ "name": "n8n", "scopes": ["webhooks:*"] }'
```

In n8n, store it once as an **HTTP Header Auth** credential:
- **Name:** `Synaplan API Key`
- **Header Name:** `Authorization`
- **Header Value:** `Bearer sk_xxx`

(You can also use `X-API-Key: sk_xxx` — both work on every Synaplan endpoint.)

---

## Recipe 1 — Synaplan as an AI Agent tool (MCP) ⭐ recommended

**Goal:** give an n8n AI Agent access to Synaplan's RAG, memories, and full chat
pipeline.

1. Add an **AI Agent** node (or **Tools Agent**).
2. Add an **MCP Client Tool** node and connect it to the agent's *tools* input.
3. Configure the MCP Client:
   - **Connection Type / Transport:** `HTTP Streamable`
   - **HTTP Streamable URL:** `https://your-synaplan.example.com/mcp`
   - **Additional Headers:** `Authorization: Bearer sk_xxx`
4. If using the community node as an agent tool, set the env var
   `N8N_COMMUNITY_PACKAGES_ALLOW_TOOL_USAGE=true` on the n8n instance.

The agent now sees these tools: `synaplan_chat`, `rag_search`, `rag_similar`,
`memory_search`, `memory_add`, `file_ingest`, `list_chats`, `get_messages`,
`list_prompts` (plus `synaplan://file/{id}` and `synaplan://memory/{id}`
resources).

> If you hit `Session not found` or the node won't leave SSE mode, force the
> transport to `httpStreamable` (Expression mode on the transport field) and
> ensure a recent node/n8n version.

**Quick connectivity check (outside n8n):**

```bash
curl -X POST https://your-synaplan.example.com/mcp \
  -H "Authorization: Bearer sk_xxx" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

---

## Recipe 2 — Synaplan as the LLM for existing AI nodes (OpenAI-compatible)

**Goal:** reuse n8n's OpenAI / LangChain nodes but route through Synaplan.

1. Create an **OpenAI** credential:
   - **API Key:** `sk_xxx` (your Synaplan key)
   - **Base URL:** `https://your-synaplan.example.com/v1`
2. Use it in the **OpenAI** node, or the **OpenAI Chat Model** sub-node feeding an
   **AI Agent** / **Basic LLM Chain**.
3. Set **Model** to a Synaplan model id (call `GET /v1/models` to list them, e.g.
   `gpt-4o`, `llama3.1:8b`). If omitted, Synaplan uses the user's default model.

**Raw check:**

```bash
curl https://your-synaplan.example.com/v1/chat/completions \
  -H "Authorization: Bearer sk_xxx" -H "Content-Type: application/json" \
  -d '{"model":"gpt-4o","messages":[{"role":"user","content":"Hello!"}]}'
```

> Limitations (current): no tool/function-calling, no vision input, `usage` may be
> zero. For RAG/memories use Recipe 1 or 3 instead of this raw shape.

---

## Recipe 3 — Deterministic "ask Synaplan" step (generic webhook)

**Goal:** in a normal (non-agent) workflow, send text and get the answer + any
generated files back synchronously.

**HTTP Request** node:
- **Method:** `POST`
- **URL:** `https://your-synaplan.example.com/api/v1/webhooks/generic`
- **Authentication:** Header Auth → `Synaplan API Key`
- **Body (JSON):**

```json
{
  "message": "Summarize this support ticket: {{ $json.ticketBody }}",
  "channel": "n8n",
  "metadata": { "ticket_id": "{{ $json.id }}" }
}
```

**Response:**

```json
{
  "success": true,
  "message_id": 123,
  "response": {
    "text": "…answer…",
    "files": [ /* generated media, if any */ ],
    "metadata": { "provider": "…", "model": "…", "usage": { … } }
  }
}
```

Map `{{ $json.response.text }}` downstream (Slack, email, DB, etc.).

---

## Recipe 4 — Native chat with file attachment + history

**Goal:** richer control — upload a file, attach it to a message, read history.

1. **Upload** (HTTP Request, `multipart/form-data`):
   - `POST https://your-synaplan.example.com/api/v1/messages/upload-file`
   - returns a file id.
2. **Send** with the file attached:
   - `POST /api/v1/messages/send`
   - Body: `{ "message": "Analyze the attached file", "fileIds": [<id>] }`
3. **History:** `GET /api/v1/messages/history`.

For long-running jobs (image/video generation) use the async pair:
`POST /api/v1/messages/enqueue` then poll `GET /api/v1/messages/{id}/status`.

---

## Recipe 5 — Keep the RAG corpus fresh from a file source

**Goal:** when a file lands in Drive/SharePoint/S3/etc., index it in Synaplan.

- **Trigger:** n8n's storage trigger (e.g. Google Drive: file created).
- **Extract text** (n8n's Extract From File node) → plain text.
- **Ingest** via MCP `file_ingest` (Recipe 1) **or** REST upload (Recipe 4 step 1).
  Pass a stable `group_key` so you can scope `rag_search` per source later.

---

## Recipe 6 — n8n as a channel bridge (e.g. Telegram → Synaplan → Telegram)

- **Trigger:** Telegram Trigger (or Slack, etc.).
- **Ask Synaplan:** Recipe 3 (generic webhook), passing the chat text.
- **Reply:** Telegram "send message" with `{{ $json.response.text }}`.

This lets Synaplan serve channels it has no native connector for.

---

## Recipe 7 — Trigger n8n *from* Synaplan (today's workaround)

> Synaplan has **no native outbound webhook** yet (see §6 of the research doc).
> Until that ships, poll:

- **Schedule Trigger** (e.g. every minute) →
- **HTTP Request** `GET /api/v1/messages/history` (Header Auth) →
- **Filter/IF** on new `message_id` (dedupe with n8n static data) →
- branch into your automation.

Latency- and quota-bound; prefer the outbound-webhook feature for production.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `401` everywhere | key not sent / wrong header | Use `Authorization: Bearer sk_…` or `X-API-Key: sk_…` |
| `429` | rate limit / cost budget | Check API-key owner's user level; throttle the n8n loop |
| MCP `Session not found` | SSE vs Streamable mismatch | Force `httpStreamable`; update n8n / MCP node |
| `/v1` answer ignores my docs | `/v1` is a raw model shape | Use MCP `synaplan_chat` or `/webhooks/generic` for RAG/memories |
| Model "not found" on `/v1` | unknown model id | `GET /v1/models`; or omit `model` to use the default |
