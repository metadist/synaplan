# S1 — Meta Model API provider (Muse Spark)

**Steps `S1.0`–`S1.2`.** Meta becomes a keyed chat provider behind the
same admin/env key flow as every other cloud provider. No UI except the
automatic provider/model rows the catalog already renders.

**Depends on:** `00_master_plan.md` §0 ticked.
**Journey:** J-MM-1 (walked in S1.2, re-walked after S2).

---

## S1.0 — Docs spike (no product code)

Read the live Meta docs and record — with URL + access date — into this
file's §Appendix:

1. Chat Completions request/response shape (confirm OpenAI parity, esp.
   `stream`, `stream_options`, tool calls, structured output).
2. Reasoning: exact parameter name, accepted levels, default, per-model
   support, whether reasoning streams as deltas (and under which field).
3. Vision: image input shape on the chat endpoint (URL/base64, limits).
4. Models endpoint (`/v1/models`) shape for health checks.
5. Rate limits + error shape (for the provider's retry/exception mapping).
6. Any `organization` / project scoping headers beyond Bearer.

If the spike contradicts a §0 recommendation (e.g. no effort levels exist),
the checklist row is re-opened — code does not start on a guess.

**Commit:** `docs(meta): spike — effort param, levels, vision payload, support matrix`

## S1.1 — Provider + catalog rows

**Machine instructions**

1. `backend/src/AI/Provider/MetaProvider.php` extends
   `AbstractChatCompletionsCloudProvider` (copy `TrustedTokensProvider.php`
   as the skeleton): name `meta`, display `Meta`, base
   `https://api.meta.ai/v1`, env `META_API_KEY`, defaults per §0 row 2,
   capabilities per §0 row 3.
2. services.yaml: env default, key-map entry, `app.ai.chat` (+`vision`)
   tags — same three spots as the TrustedTokens wiring.
3. `backend/.env.example`: `META_API_KEY=` with a one-line comment.
4. Catalog: `muse-spark-1.3` row(s) via `ModelSeeder`/`ModelCatalog::upsert`
   (`meta:muse-spark-1.3` key): chat capability, 1M context, `features`
   including `reasoning` iff the spike confirms thinking support,
   `reasoning_effort_default` per spike, vision/tool_use per spike.
5. Reasoning deltas: if the spike shows a `reasoning_content`-style stream
   field, map it to the `reasoning` chunk type (xAI/HF pattern); else skip.

**Tests**

- Provider unit test (name/display/defaults/capabilities, key resolution
  precedence, client rebuild on key change) — mirror the TrustedTokens test
  if one exists, else the Groq provider test shape.
- Catalog test: row present, key resolves via `findBidByKey`, features match
  the spike record.

**Commit:** `feat(ai): Meta Model API provider (Muse Spark) + catalog rows`

## S1.2 — Keys, health, pricing + provider tests

**Machine instructions**

1. `ProviderKeyStore` picks up `meta` (admin UI Models & keys card appears
   automatically — verify, do not hand-build).
2. Health check + pricing entries follow the existing provider pattern
   (same files the last provider touched — list them in the PR).
3. `docs/` provider list gains Meta (one row/section, same shape as peers).

**Tests**

- Keystore round-trip (UI/env precedence) for `meta`.
- Health + pricing unit coverage per the touched files' conventions.
- Full gate green.

**Walk (J-MM-1):** admin sets the key (or env), user picks Muse Spark as
default chat model, sends a chat; answer streams. Screenshot in the PR.

**Commit:** `feat(ai): Meta keys, health, pricing + provider tests`

## Appendix — spike record (fill in S1.0)

| # | Doc | URL | Read on | Finding |
| - | --- | --- | ------- | ------- |
| 1 | Chat Completions | — | — | — |
| 2 | Reasoning | — | — | — |
| 3 | Images/vision | — | — | — |
| 4 | Models list | — | — | — |
| 5 | Rate limits/errors | — | — | — |
| 6 | Auth scoping | — | — | — |
