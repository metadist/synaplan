# Governance and privacy — personal data stays in the house, and someone decides what goes live

Row 41. Umbrella: [`20261102-enterprise-readiness/`](../20261102-enterprise-readiness/00_master_plan.md) (C11, C16).

Companies that use cloud models ask a data-protection question first:
"What happens when someone pastes a customer's name, IBAN or diagnosis
into the chat?" Public bodies add: "Can a department be held to local
models only?" And once staff build assistants: "Who checks an assistant
before it answers customers?"

Synaplan has the pieces:

- group policies (allowed models, features, rate tier);
- the AI call pipeline with hooks that may refuse a call (row 22);
- the egress policy;
- local providers;
- agent publishing.

This track joins them into four answers. Each answer is a group policy
key, so there is still one policy store and one admin surface.

## 1. Findings (checked 2026-11-02 against `main`)

| # | Prio | Finding | Evidence |
| - | ---- | ------- | -------- |
| F1 | P1 | Nothing detects or masks personal data before a call to a cloud model | `AI/Pipeline/Hook/` has metrics, circuit breaker, health, rate limit, request context, usage — no privacy hook |
| F2 | P1 | "Local only" exists per job as an idea (use-case research Q8) but not as a policy: a group can be limited to allowed models, yet nothing says "never leave the house", and a fallback can still pick a cloud model | `PolicyAllowList::keys()` has `MODELS.ALLOWED`; no locality key; `ProviderBranding::local()` marks local providers in the descriptors |
| F3 | P2 | An answer does not show where it was processed ("on your server" / "at {provider}, {region}") | chat message footer shows the model name only |
| F4 | P2 | Any user allowed to build agents can publish one to the organisation or a widget without a second pair of eyes | `Service/Agent/AgentPublisher.php` |
| F5 | P2 | No retention rule for conversations: chats are kept until the person deletes them. Tenders ask for data minimisation per department | no `CHAT.RETENTION_DAYS`; `Service/Iam/Erasure/ConversationEraser.php` exists for account deletion only |
| F6 | P2 | An admin cannot see in one place what a group is allowed to do (models, features, privacy) | policies are set per key on the group page |

## 2. What is fine — do not "fix" it

- `BGROUPCONFIG` plus `PolicyAllowList` with merge rules (`first`, `union`, `or`, `highest`). New keys only.
- `AiCallPipeline` with `before()` returning `AiCallRefusal`. Policy hooks fail closed and observability hooks fail open (row 22 contract). The privacy hook is a policy hook.
- Egress policy (`Service/Egress/`) as the network-level guard. "Local only" is enforced at the model choice **and** checked by egress, not by egress alone.
- Approvals for tools (durable approvals, seeded on). Agent review reuses the approval inbox pattern (U2 sibling inbox) and is not a new queue.

## 3. Target model

### 3.1 Personal data masking (`PRIVACY.PII`)

| Value | Effect on calls to a **cloud** model (local models are never masked) |
| ----- | ------------------------------------------------------------------- |
| `off` | today |
| `mask` (recommended) | names, addresses, mail, phone, IBAN, card numbers, German tax id, social security number, health insurance number, ID card / passport numbers, licence plates are replaced by stable placeholders (`[PERSON_1]`, `[IBAN_1]`) before the call. The model's answer is mapped back before the person sees it |
| `block` | a message with detected data of the listed kinds is not sent. The person reads "This message contains an IBAN. Your organisation does not send personal data to cloud models. Choose a local model or remove it." with **Use local model** as the action |

- **Engine.** Microsoft Presidio analyzer + anonymizer as a sidecar (`Module/Sidecar/PresidioModule`). It ships 13 German recognizers, several with check-digit validation (Steuer-IdNr., Rentenversicherungsnummer, KVNR, LANR), plus spaCy `de_core_news_md` and `en_core_web_lg` for names, places and organisations. Without the sidecar, the policy values `mask` and `block` cannot be chosen and the group page says why (U11).
- **Reversible within a conversation.** Presidio is stateless, so Synaplan keeps the mapping: placeholder ↔ value per conversation, encrypted (libsodium secretbox, key from `APP_SECRET` derivation) in Redis with the conversation's TTL and in `BMESSAGEMETA` for history. The same person gets the same placeholder in every turn. Streaming answers are un-masked token-wise with a small look-ahead buffer for split placeholders.
- **Where.** A `PiiMaskingHook` (policy hook, fail closed: if the sidecar is down and the policy is `mask`, the call is refused with a sentence, never sent unmasked). It covers chat, widget, mail, channels, research reads and tool arguments sent to cloud models. File content sent for RAG embedding with a cloud embedding model is masked at chunk time.
- **Visible.** A small shield on the message: "2 personal details were masked before {provider} saw this message." with **Show** (only to the sender).
- **Thresholds.** Score ≥ 0.5 by default. Entities are configurable per group; postal codes and plates are off by default because they produce many false positives.

### 3.2 Local only (`PRIVACY.LOCAL_ONLY`)

- A group with `LOCAL_ONLY=1` may use only models whose provider is local (`ProviderBranding::local`, Ollama, OpenAI-compatible on the operator's network, Triton). `MODELS.ALLOWED` is intersected with that set.
- Fallbacks, the sorter, research, embeddings, speech and media follow the same rule. If no local model exists for a capability, that capability is off for the group with the sentence "Image generation needs a model on your own server. Ask your admin to add one." It is never a silent cloud fallback.
- Every answer shows where it was processed (F3, for everyone, not only local-only groups): "Processed on your server ({model})" or "Processed by {provider} in {region}". Region comes from the provider descriptor.

### 3.3 Agent review before publish (`AGENTS.PUBLISH_REVIEW`)

- When on for a group, publishing an agent to the organisation, a widget, a channel or the template gallery (row 42) creates a **review request** instead of going live.
- Reviewers are the group's admins (role `admin` on the group) or system admins. They find the request in the Approvals inbox, open the agent read-only with a diff against the live version (prompt, model, tools, knowledge, sharing), and **Approve** or **Send back** with a note.
- The author sees "Waiting for review by Sales admins" on the agent and can withdraw.
- The live version keeps running while a new version is under review.

### 3.4 Conversation retention (`CHAT.RETENTION_DAYS`)

- Per group, empty means keep. Conversations older than N days since the last message are deleted by a nightly job through `ConversationEraser`. Files attached only to them follow; knowledge files stay.
- A conversation pinned or shared is kept and shown as "kept because shared".
- Seven days before deletion the chat list marks the conversation as "Deleted in 7 days (your organisation's rule)".
- With several groups, the longest retention wins (`highest`), so nobody loses data because of a second group.

### 3.5 Governance overview

Admin → **Governance**: one table, groups as rows, columns for allowed
models (count + local only), masking, publish review, retention and rate
tier. Each cell shows its source (group, inherited, global lock) and links
to the group page where it is changed (U7, "who owns this, what can they
do"). A **Preview as** select shows the effective policy for one person.

## 4. Decisions

- [ ] **D1 (GV1).** Presidio as a sidecar (two images from `mcr.microsoft.com`, about 1.5 GB with German models). It is a new compose service and image, so it is the owner's call. *Alternative:* a PHP regex set only — it would catch IBAN, mail and phone, but no names, and that does not meet the question buyers ask.
- [ ] **D2 (GV1).** Mapping kept encrypted in Redis and in message metadata for history, so a reloaded conversation can still be un-masked. *Alternative:* Redis only, where history shows placeholders after the TTL.
- [ ] **D3 (GV2).** Fail closed when the masking sidecar is down. That follows the row 22 contract for policy hooks.
- [ ] **D4 (GV3).** "Processed on / by" line on every answer, for everyone.
- [ ] **D5 (GV4).** The longest retention wins across groups.
- [ ] **D6 (GV5).** Reviewers: group admins or system admins. The reviewer is never the author.

## 5. Journeys

| Id | Journey | Profile |
| -- | ------- | ------- |
| J-GV-1 | An admin sets "Mask personal data" for HR. An HR user writes "Draft a reply to Anna Schmidt, IBAN DE89 3704 0044 0532 0130 00". The answer names Anna Schmidt correctly; the shield says 2 details were masked; **Show** lists them. In the usage log metadata, the provider saw `[PERSON_1]` and `[IBAN_1]` | Admin, user |
| J-GV-2 | The masking sidecar is stopped. The same HR user's message is refused with "Personal data protection is not available right now, so this message was not sent. Try again in a few minutes or use a local model." | User |
| J-GV-3 | An admin sets "Local only" for Legal. A Legal user sees only local models in the picker; the answer says "Processed on your server (Llama 4 Scout)". Image generation is off with its sentence | Admin, user |
| J-GV-4 | A Sales user publishes the "Offer assistant" to the website widget. It shows "Waiting for review by Sales admins". The Sales admin opens Approvals, reads the diff, approves; the widget answers with it | Author, reviewer |
| J-GV-5 | An admin opens Admin → Governance and reads in ten seconds that Legal is local-only, HR masks personal data, and Sales has review on. They change HR's retention from the same row's link | Admin |

## 6. Steps

| Step | Commit subject | Release class | Depends on | Gate |
| ---- | -------------- | ------------- | ---------- | ---- |
| GV1 | `feat(privacy): optional personal-data detection sidecar` — `PresidioModule` (env `PRESIDIO_ANALYZER_URL`, `PRESIDIO_ANONYMIZER_URL`), client, German + English configuration, compose service (dev; `deploy/` per D1), health on System status | backend-only | D1 | `make ci-local`; `make test-e2e-full` (compose changed) |
| GV2 | `feat(privacy): mask personal data before it reaches a cloud model` — `PRIVACY.PII` policy key, `PiiMaskingHook` (fail closed), per-conversation encrypted mapping, streaming un-mask, shield + Show, embeddings path; PHPUnit for split placeholders and every entity kind; J-GV-1, J-GV-2 | backend-only + ota-candidate | D2, D3, GV1 | `make ci-local && make test-e2e`; latency added ≤ 150 ms p95 measured |
| GV3 | `feat(privacy): keep a group on local models only and say where every answer was processed` — `PRIVACY.LOCAL_ONLY`, intersection with `MODELS.ALLOWED`, every capability path, "Processed on / by" line, region in descriptors; J-GV-3 | backend-only + ota-candidate | D4 | `make ci-local && make test-e2e`; routing characterization snapshots reviewed |
| GV4 | `feat(privacy): delete old conversations by group rule` — `CHAT.RETENTION_DAYS` (`highest` merge), nightly job through `ConversationEraser`, 7-day marker in the chat list, shared and pinned kept | backend-only + ota-candidate | D5 | `make ci-local && make test-e2e` |
| GV5 | `feat(agents): review before an agent goes live` — `AGENTS.PUBLISH_REVIEW`, review request in the Approvals inbox, read-only diff, approve / send back, waiting state on the agent; J-GV-4 | backend-only + ota-candidate | D6 | `make ci-local && make test-e2e` |
| GV6 | `feat(admin): one governance overview` — Admin → Governance table, sources per cell, **Preview as**; J-GV-5 | ota-candidate | GV2–GV5 | `make ci-local && make test-e2e` |
| GV7 | `feat(synaplan): optional personal-data sidecar` in `synaplan-charts` — `presidio.enabled`, images, resources (≈ 2 GiB memory), NetworkPolicy backend-only | — (chart) | GV1 | `make all` |

Every UI step carries the five exit bullets of `docs/UX_CONTRACT.md` §6
before its first `.vue`:

- **Journeys walked:** J-GV-1 to J-GV-5.
- **Ten-second findability:** the policy is on the group page and in the Governance overview; the effect is on the message itself (shield, processed line); review requests are in the Approvals inbox.
- **Consequence copy in five locales:** "Turning on local only for Legal hides 14 cloud models from 9 people. Image generation is off for them until a local image model exists."
- **Empty, error and flag-off states:** without a sidecar the masking options are disabled with a reason; no local model → the local-only option explains what is missing.
- **Dark, V2 and 320 px.**

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| False positives make answers odd ("[PERSON_1] Straße") | Un-mask restores the original; thresholds and entity list per group; locations off by default for street names unless chosen |
| False negatives: personal data slips through | Copy says "masks common personal details", never "guarantees"; `block` mode for strict groups; documented recall numbers from a German test set in `docs/PRIVACY.md` |
| Sidecar memory on small installs | Optional; absent by default; chart resources documented |
| Retention deletes something a person wanted | 7-day marker; pin or share keeps it; longest rule wins; job logs counts in the audit log (`chat.retention_deleted`, row 37) |
| Review blocks urgent fixes | The live version keeps running; system admins can approve any; review is per group and off by default |

## 8. Non-goals

- Data loss prevention for uploads leaving Synaplan (mail attachments, exports).
- Classifying documents (confidential / internal) or reading Microsoft Purview labels — a later connector topic (row 40).
- Masking for local models.

## 9. Change log

| Date | Change |
| ---- | ------ |
| 2026-11-02 | Plan written from Presidio's German recognizer set, LLM-gateway masking practice (mask-and-restore, stable placeholders), the use-case research Q8, and a read of the policy, pipeline, provider-descriptor and agent-publish code on `main`. No code changed. |
