# Research & Estimation — AWS Bedrock as a model interface

**Release:** 4.0 (candidate) · **Priority:** P2 (customer-driven) · **Status:** Research / estimation only
**Trigger:** A customer asked us to integrate **AWS Bedrock** as an interface for our models.
**Question to answer:** Is it possible? Do we know the API? What's the effort?

> TL;DR — **Yes, very feasible.** Bedrock's modern **Converse API** is message-based
> and normalizes across every chat model (Claude, Llama, Mistral, Amazon Nova,
> Cohere, …), so it maps almost 1:1 onto our existing `ChatProviderInterface`.
> The only real wrinkle vs. our other providers is **auth**: Bedrock uses AWS
> SigV4 (access key/secret + region), not a bearer API key. Core chat + embeddings
> behind a new `BedrockProvider` is roughly **6–9 dev-days**; full polish
> (vision, image gen, pricing) **~10–12 days**.

---

## 1. Is it possible? — Yes

Bedrock is a managed, multi-vendor model gateway. One AWS account + region gives
access to foundation models from Anthropic, Meta, Mistral, Amazon (Nova/Titan),
Cohere, AI21, Stability AI, etc., through a single runtime endpoint
(`bedrock-runtime.<region>.amazonaws.com`). That is conceptually the same role a
"provider" plays in our `ProviderRegistry`, so it slots into the existing
abstraction rather than fighting it.

The customer benefit: enterprises that already buy AWS (and have data-residency /
procurement constraints) can consume models **through their own AWS account and
billing**, inside their region, under their IAM/Guardrails — without us holding
each vendor's API key.

---

## 2. Do we know the API? — Yes

Bedrock Runtime (`bedrock-runtime`, API version `2023-09-30`) exposes two
relevant surfaces:

### a) Converse / ConverseStream  ← recommended for chat

A **unified, message-based** API that works across all chat-capable models with
one schema — "write once, run on any model":

```jsonc
// Converse request (shape)
{
  "modelId": "amazon.nova-lite-v1:0",          // or an inference-profile id, see §4
  "system":  [ { "text": "You are…" } ],
  "messages": [ { "role": "user", "content": [ { "text": "Hello" } ] } ],
  "inferenceConfig": { "maxTokens": 512, "temperature": 0.5 },
  "toolConfig": { "tools": [ … ] }              // tool/function calling, optional
}
// Response: output.message.content[].text  +  usage.{inputTokens,outputTokens,totalTokens}
```

`ConverseStream` returns an AWS **event stream** (`messageStart` →
`contentBlockDelta` text deltas → `contentBlockStop` → `messageStop` →
`metadata` with token usage). This maps directly onto our
`ChatProviderInterface::chatStream()` callback contract (content chunks + a
final `finish` signal).

Other useful Converse-family ops: `CountTokens`, `ApplyGuardrail` (enterprise
content filtering), and image/document **content blocks** (covers our
`VisionProviderInterface` need).

### b) InvokeModel / InvokeModelWithResponseStream  ← per-model bodies

The original API. Each vendor has its own request/response JSON. We'd use this
only where Converse doesn't apply:

- **Embeddings** — Amazon Titan Embeddings v2 / Cohere Embed (Converse is
  chat-only).
- **Image generation** — Amazon Titan Image Generator / Stability SDXL.
- **Async video** — `StartAsyncInvoke` / `GetAsyncInvoke` / `ListAsyncInvokes`
  (e.g. Amazon Nova Reel). See the synergy note in §6.

---

## 3. The one real difference vs. our other providers: auth (SigV4)

Every existing provider (`AnthropicProvider`, `OpenAIProvider`, …) is a thin
Symfony `HttpClient` wrapper with a bearer/API-key header. Bedrock instead needs
**AWS Signature V4** signing with an access key + secret (+ optional session
token) and a region. Three options:

| Option | Pros | Cons | Verdict |
|---|---|---|---|
| **A. `async-aws/bedrock-runtime`** (v1.3, Jun 2026, PHP 8.2+, MIT, Symfony-friendly, modular) | Tiny dependency, handles SigV4 **and** event-stream framing, fits our HttpClient world | Slightly less battle-tested than the monolith | **Recommended** |
| B. `aws/aws-sdk-php` `BedrockRuntimeClient` | Official, full `Converse`/`ConverseStream`/`InvokeModel` | Large dep; streaming needs `'@http' => ['stream' => true]`; had streaming/error-body quirks (fixed in 2025) | Acceptable fallback |
| C. Manual SigV4 + hand-rolled event-stream parsing over our `HttpClient` | Zero new deps, full control | We'd re-implement SigV4 **and** the binary streaming frames — not worth it | Avoid |

**Recommendation:** Option A. Adds one focused Composer package (needs the
"Ask first before adding dependencies" sign-off per `AGENTS.md`).

---

## 4. Customer-side caveats to flag up-front (onboarding)

- **Model access must be enabled** per model, per region in the customer's AWS
  console ("Model access"). A fresh account has none enabled.
- **Cross-region inference profiles:** many newer models are only callable via an
  inference-profile id (`us.` / `eu.` / `apac.` prefixed, e.g.
  `eu.anthropic.claude-…`) rather than the bare model id. EU customers will
  typically want the `eu.` profile for data residency.
- **Region availability & quotas** differ by model; on-demand has TPM/RPM limits
  (throttling → must map to our retry/circuit-breaker), ProvisionedThroughput is
  optional.
- IAM permission required: `bedrock:InvokeModel` (+ `…WithResponseStream`).

---

## 5. Integration points in our codebase (concrete)

- **New** `App\AI\Provider\BedrockProvider` implementing `ChatProviderInterface`,
  `VisionProviderInterface`, and `EmbeddingProviderInterface`; `getName()` =
  `'bedrock'`; tagged `app.ai.chat` / `app.ai.vision` / `app.ai.embedding` so
  `ProviderRegistry` auto-discovers it.
- **Message mapping:** OpenAI-format `messages` (our internal shape) → Converse
  `system` + `messages[].content[].text`; map `usage.inputTokens/outputTokens`
  → our `{prompt_tokens, completion_tokens, total_tokens}`.
- **Streaming adapter:** ConverseStream events → our chunk callback (content
  deltas + final `finish`/`finish_reason`).
- **Credentials:** a `BedrockCredentialResolver` mirroring the existing
  `HiggsfieldCredentialResolver` pattern — store access key / secret / region
  (+ optional session token), global or per-workspace, encrypted at rest, never
  logged; env defaults (`AWS_BEDROCK_REGION`, standard AWS env vars).
- **Model catalog:** seed `BMODELS` rows (model/inference-profile id + capability
  tags) via the idempotent seed service.
- **Cost:** Converse returns token usage → wire into `CostCalculationService`
  with Bedrock per-model pricing.

No changes to the routing/classifier or the chat pipeline are expected — Bedrock
is "just another provider" behind the registry.

---

## 6. Synergy with Release 4.0 (worth noting)

Bedrock's **async invocation** (`StartAsyncInvoke` / `GetAsyncInvoke`) for video
(Amazon Nova Reel) maps cleanly onto the **MediaJob backbone** we just shipped in
[`01_async-media-jobs.md`](./01_async-media-jobs.md): implement
`SupportsAsyncVideo` (`startVideoOperation` → `StartAsyncInvoke`,
`pollVideoOperationOnce` → `GetAsyncInvoke`, `downloadVideoRaw` → fetch the S3
output). That would make Bedrock a first-class async-video provider with **zero**
new orchestration code. Out of scope for the first cut, but a clean follow-up.

---

## 7. Effort estimate

| Slice | Est. |
|---|---|
| Spike/PoC: Converse chat, one model, non-streaming, hard-coded creds | 0.5–1 d |
| Chat + streaming adapter (Converse/ConverseStream) incl. tool-use passthrough | 2–3 d |
| Credentials resolver + config/admin + env defaults | 1–1.5 d |
| Embeddings (Titan v2 / Cohere via InvokeModel) | 1 d |
| Vision (Converse image content blocks) | 0.5 d |
| Model catalog seeding + capability tags + cost/pricing | 1 d |
| Tests (unit mapping + integration with mocked Bedrock client) | 1–1.5 d |
| **Core total (chat + embeddings + creds + tests)** | **~6–9 d** |
| Image generation (Titan / SDXL) — optional | +1–2 d |
| Async video via MediaJob (`SupportsAsyncVideo`) — optional follow-up | +2–3 d |
| **Full** | **~10–12 d** |

---

## 8. Open questions / decisions before we commit

1. **Dependency:** OK to add `async-aws/bedrock-runtime` (preferred) — needs the
   AGENTS "ask first" sign-off.
2. **Credential scope:** global (one set of AWS keys we manage) vs. per-workspace
   (customer brings their own AWS account)? The customer ask implies the latter.
3. **Which models first?** Likely Claude (Anthropic on Bedrock) for parity with
   our current Claude path, plus Amazon Nova as the "AWS-native" option.
4. **Region/residency default** for the asking customer (EU?) → inference-profile
   prefix.
5. Scope of first cut: chat-only, or chat + embeddings (RAG)?

---

## Sources

- AWS SDK for PHP v3 — Bedrock Runtime examples (Converse): <https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/php_bedrock-runtime_code_examples.html>
- Bedrock Runtime API reference (Converse / ConverseStream / InvokeModel / StartAsyncInvoke): <https://docs.aws.amazon.com/goto/SdkForPHPV3/bedrock-runtime-2023-09-30/Converse>
- `async-aws/bedrock-runtime` (v1.3.0, 2026-06): <https://packagist.org/packages/async-aws/bedrock-runtime> · docs <https://async-aws.com/clients/bedrock-runtime.html>
- Streaming flag (`'@http' => ['stream' => true]`) note: aws/aws-sdk-php issues #3018, #3124 (fixed via #3214, 2025)
