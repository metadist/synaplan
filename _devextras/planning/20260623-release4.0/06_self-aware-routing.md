# Feature 6 — Self-Aware Routing ("Synaplan can explain Synaplan")

**Release:** 4.0 · **Priority:** P1 · **Status:** Planned (started 2026-06-27)
**Related:** [`00_master_plan.md`](./00_master_plan.md),
[`03_file-management.md`](./03_file-management.md) (shares the RAG/vectorization
backbone), routing pipeline in `backend/src/Service/Multitask/`.

> Goal: when a user asks *"What can Synaplan do?"*, *"Wie funktioniert das?"*,
> *"How do I add a knowledge base?"*, or *"Was ist neu?"* — in **any** of the four
> UI languages — the platform routes that to an accurate, grounded answer about
> **its own features**, instead of a generic LLM guess that drifts from reality.
> The trick: derive self-awareness from the **same registry that powers routing**,
> backed by a curated **"About Synaplan"** RAG source, so it can never lie about
> what it can do.

---

## 0. Done already (context — the trigger)

This plan grew out of fixing the **meeting-reminder timezone bug** in the
multitask DAG path. That fix is **shipped** (gate green: lint, PHPStan, focused
unit tests):

- **Root cause:** `TaskPlanner::timeContextBlock()` fed the planner only the
  *server* clock + `date_default_timezone_get()` (UTC in prod), and the canonical
  `tools:plan` example hardcoded `"timezone": "UTC"`. A user in `Europe/Berlin`
  (UTC+2) asking for "tomorrow 9:00" got `09:00Z` in the `.ics` → calendar showed
  **11:00** (2 h off).
- **Fix:** wired the chat path's `TimeContextBuilder` (profile timezone →
  unambiguous country → server fallback) into `TaskPlanner`; threaded
  `client_country` through `TaskPlanExecutor`; changed the prompt example to
  `Europe/Berlin`.
- **Files:** `backend/src/Service/Multitask/TaskPlanner.php`,
  `backend/src/Service/Multitask/TaskPlanExecutor.php`,
  `backend/src/Prompt/PromptCatalog.php` (+ two unit tests).

Working on that exposed how little the planner knows about **Synaplan itself**.
This feature closes that gap.

---

## 1. Why (the problem, grounded in the current code)

| # | Gap | Evidence |
|---|---|---|
| **G1** | **No "about the product" route.** The planner advertises user task topics via `[DYNAMICLIST]` and a generic `general` topic ("Wer bist du?" → `chat`/`general`). There is no dedicated, feature-accurate topic for product/how-to questions, so they fall to a generic model answer that hallucinates features. | `TaskPlanner::buildSystemPrompt()` `[DYNAMICLIST]`; planPrompt "Plain question" example routes to `general`. |
| **G2** | **No global / system-owned knowledge source.** RAG is per-user and group-scoped (`TASKPROMPT:{topic}`, `WIDGET:{id}`, user folders via `rag_group_key`). There is **no shared, read-only knowledge group** every user can draw on. | `ChatHandler::loadRagContext()` (~1325), `ChatRunner::ragContext()` (137–174), `WidgetPublicController` `WIDGET:{id}` (~752). |
| **G3** | **Feature awareness would drift.** A hand-written "what we do" blurb goes stale the moment a capability/provider/plugin is added. The authoritative list already lives in code (`CAPABILITY_DESCRIPTIONS`, seeded `tools:sort` topics) but is not surfaced to the user. | `TaskPlanner::CAPABILITY_DESCRIPTIONS`, `Capability::values()`. |
| **G4** | **Not multilingual.** Any product self-description must answer in `de/en/es/tr` (the supported set). Per-language prompt rows exist, but nothing is seeded for "about Synaplan", and no RAG content is curated per locale. | `frontend/src/i18n/index.ts` `supportedLanguages = ['de','en','es','tr']`; `PromptCatalog` seeds per `language`. |

Net: Synaplan can *do* a lot but cannot reliably *talk about* what it does,
especially across languages.

---

## 2. Design principles

1. **Single source of truth.** Self-awareness is generated from the live
   capability/topic registry + a curated KB — never a second, hand-maintained
   feature list that drifts.
2. **Grounded, not guessed.** Product answers come from the **"About Synaplan"**
   RAG group; the model is instructed to answer *only* from it and to say
   "I'm not certain — see the docs" rather than invent a feature.
3. **Cheap default path.** Most product questions resolve via a single `chat`
   node bound to a `synaplan` topic — no extra latency. RAG is added only where
   it earns its keep.
4. **Multilingual by construction.** `de/en/es/tr` for the system prompt, the
   user-facing strings, **and the curated KB content** — all four languages are
   authored, not just relied on via cross-lingual retrieval.
5. **Never quote pricing — link it.** Product answers must **not** state prices,
   plan limits, or quotas (they go stale). Instead they link to the canonical
   **upgrade/pricing page** and let the user read current numbers there.
6. **Additive & safe.** New reserved RAG group + idempotent seeders; no
   destructive schema change; everything behind a `BCONFIG` flag with a safe
   default.
7. **Reuse, don't reinvent.** Build on `PromptCatalog`/`app:seed`,
   `VectorStorageFacade`, `VectorizationService`, `ReVectorizeMessageHandler`,
   and the existing `rag_query` capability.

---

## 3. Architecture — four layers

```
User: "Was kann Synaplan?" / "How do I add a knowledge base?"
        │
        ▼
  MessageSorter / TaskPlanner  ──(new routing rule)──▶ topic_id = "synaplan"
        │                                                    │
        │                                              (optionally)
        ▼                                                    ▼
  chat node (synaplan system prompt)  ◀── rag_query node scoped to SYSTEM:synaplan
        │                                                    ▲
        │   system prompt augmented with:                   │
        │   • live capability list (dynamic)        curated docs from
        │   • user's enabled topics/providers       synaplan-docs + README
        │   • app version + changelog               (vectorized, multilingual)
        ▼
  Grounded, multilingual answer about Synaplan itself
```

### Layer A — A dedicated `synaplan` task topic (deterministic, cheap)
Seed a task topic (system prompt) `synaplan` in `PromptCatalog`, **one row per
locale** (`de/en/es/tr`). Because the planner injects `[DYNAMICLIST]` (topic +
description), a sharp multilingual description makes the planner route product /
how-to / "what can you do" questions to a `chat` node with
`params.topic_id = "synaplan"`. No new code path — reuses the existing topic
mechanism.

### Layer B — A global "About Synaplan" RAG source (`SYSTEM:synaplan`)
Introduce a reserved RAG group key `SYSTEM:synaplan`, **owned by the admin user**
and read-only to everyone else, vectorized through the existing Qdrant/MariaDB
pipeline. Bind the `synaplan` topic to it (the `TASKPROMPT`→group scoping pattern
already exists) **or** have the planner emit a `rag_query` node scoped to
`SYSTEM:synaplan`.

**Ownership & mutability (decided 2026-06-27):**

- **Admin-owned.** The group/content belongs to the admin user, not the system
  abstractly — so a deployment has a real, editable owner.
- **Seed-once, then immutable to the seeder.** The `app:seed` seeder is
  **create-if-absent**: it populates `SYSTEM:synaplan` only when it does **not**
  already exist in the DB. If rows already exist, the seeder **leaves them
  untouched** (no overwrite, no re-vectorize). This makes it idempotent *and*
  lets **self-hosters replace the content with their own** without every
  `app:seed` / deploy clobbering their edits.
- **Editable by the admin, not by end users.** The admin can intentionally
  curate/replace it (UI or CLI); regular users can only read it via RAG. An
  explicit `--force`/`app:seed:reset-synaplan` escape hatch re-seeds the stock
  content for those who *want* the upstream version back.

So: stock "About Synaplan" out of the box, fully overridable per deployment, and
never silently overwritten.

### Layer C — GitHub / docs as knowledge base (curated, not firehosed)
- **Source (decided 2026-06-27): `synaplan-docs` only.** Not `README`, not
  `CHANGELOG`, not the code repo (those pollute retrieval, leak internals, and
  degrade answers).
- **Plus a curated tech-stack reference.** A small, deliberately-authored doc
  (part of the seed, in all four locales) so the platform can answer "what is it
  built on / where does my data live": **Qdrant** (vector store / RAG memories),
  **Redis** (jobs, cache, realtime backbone), **Stripe** (billing/upgrades — link
  only, no prices per §2.5), **PHP 8.5**, **Docker** (local + image build),
  **Kubernetes** + **Helm charts** (production deployment). Keep it factual and
  high-level — capabilities and where data lives — not internal architecture.
- **Cadence (decided 2026-06-27): manual `app:seed` re-run for v1.** Ingestion
  happens when an operator runs the seed command (vectorizing into
  `SYSTEM:synaplan` via the existing async `ReVectorizeMessageHandler`); it is
  **create-if-absent** so a re-run never clobbers a self-hoster's edits (use the
  `--force`/reset command to refresh stock content). No scheduled job and **no**
  live GitHub API calls per query in v1 — a periodic re-vectorization job is a
  later enhancement if doc churn warrants it.
- **Multilingual:** author the curated KB in **all four languages**
  (`de/en/es/tr`). Embeddings (bge-m3, 1024-dim) are multilingual so cross-lingual
  retrieval still works as a safety net, but each locale gets first-class content
  so a `de`/`es`/`tr` user gets a native, on-language answer.

### Layer D — Registry-derived awareness (the "trick")
Augment the `synaplan` system prompt at build time with facts derived from the
**live** system, so the answer can never contradict reality:
- **Capabilities:** generate "what I can do" from `Capability::values()` +
  `CAPABILITY_DESCRIPTIONS` (the same list the planner routes on).
- **Per-user:** the user's *enabled* task topics (`[DYNAMICLIST]` is already
  per-user) + configured providers/models/plugins → "what can you do *for me*".
- **Version/changelog:** inject app version + a short recent changelog → "what's
  new".
- **Guardrail:** answer product questions **only** from `SYSTEM:synaplan`; honest
  fallback with a docs link otherwise.
- **Pricing guardrail:** never state prices, plan limits, or quotas. When asked
  about cost/plans/upgrading, give a short benefit sentence and **link to the
  canonical upgrade/pricing page** so the user reads current numbers there. This
  rule is baked into the `synaplan` system prompt (all four locales).

---

## 4. Routing changes (prompt-level, low risk)

Add a rule to **both** the `tools:plan` (`PromptCatalog::planPrompt()`, near the
existing rules 7/8 at ~511–520) and `tools:sort` prompts:

> *Questions about Synaplan itself — its features, capabilities, pricing, how-to,
> "what can you do", "what's new" — → one `chat` node with
> `topic_id: "synaplan"`. If the question is specific/factual, ADD a `rag_query`
> node (scoped to the product knowledge) it depends on. Never the reply node for
> `email_me`.*

- After editing the planner prompt: re-record the routing characterization
  snapshots (`UPDATE_ROUTING_SNAPSHOTS=1` … `RoutingCharacterizationTest`) and
  **review the diff** before committing (AGENTS.md trap).
- Keep the change behind `BCONFIG` `SELF_AWARE.ROUTING_ENABLED` (default on once
  validated) so it can be disabled without a deploy.

---

## 5. Data / config (additive)

| Item | Where | Notes |
|---|---|---|
| `synaplan` task topic (×4 locales) | `PromptCatalog` + `app:seed` | System prompt + multilingual `[DYNAMICLIST]` description. |
| `SYSTEM:synaplan` RAG group | New reserved group-key constant + RAG seeder in `backend/src/Seed/` | **Admin-owned**, read-only to end users. Seeder is **create-if-absent** (never overwrites existing rows) so self-hosters can replace it; `--force`/reset command re-seeds stock content. Excluded from per-user file manager. |
| Curated KB content | `synaplan-docs` **only** + a curated tech-stack reference (Qdrant, Redis, Stripe, PHP 8.5, Docker, K8s, Helm) | Vectorized via existing pipeline; authored in all four locales. No README/CHANGELOG/code repo. |
| `BCONFIG` flags | `SELF_AWARE.ROUTING_ENABLED`, `SELF_AWARE.RAG_ENABLED` | Safe defaults; synchronous/legacy fallback unchanged. |
| i18n strings | `frontend/src/i18n/{de,en,es,tr}.json` | Any new UI labels (e.g. a "Ask about Synaplan" hint). All four locales. |

No `BFILES`/`BRAG` schema change required — `SYSTEM:synaplan` is just a reserved
group key in the existing vector store. Confirm the file manager and RAG search
correctly **scope out** the system group from per-user listings.

---

## 6. Sprints

- **S1 — Routing + topic (prompt/seed only, P0 of this feature).**
  Seed the `synaplan` topic (×4 locales); add the routing rule to
  `tools:plan` + `tools:sort`; re-record routing snapshots. Quick, low-risk win;
  no new infra. Answers are model-grounded by the topic prompt (no RAG yet).
- **S2 — Global RAG source.**
  Add the admin-owned `SYSTEM:synaplan` group + **create-if-absent** seeder
  (never overwrites existing rows; `--force`/reset command restores stock
  content); vectorize a small curated doc set; bind topic → group (or planner
  `rag_query` node); ensure per-user listings exclude the system group. Flag
  `SELF_AWARE.RAG_ENABLED`.
- **S3 — Docs/GitHub ingestion.**
  **Manual `app:seed`** seeder that pulls **`synaplan-docs` only** + the curated
  tech-stack reference (Qdrant, Redis, Stripe, PHP 8.5, Docker, K8s, Helm) and
  re-vectorizes via `ReVectorizeMessageHandler`; content authored in **all four
  languages** (`de/en/es/tr`); create-if-absent (idempotent), `--force`/reset to
  refresh stock content. No scheduled job in v1.
- **S4 — Registry-derived awareness.**
  Inject live capability list + per-user topics/providers + version/changelog
  into the `synaplan` system prompt; grounding guardrail + docs-link fallback.
- **S5 — Polish & rollout.**
  Tests (routing characterization, planner unit, RAG scoping), flags-on, docs.

> S1 alone is shippable tomorrow and delivers most of the felt value. S2+ raise
> accuracy and "what's new" freshness.

---

## 7. Decisions log (all resolved — ready to build)

1. ~~**System RAG group concept** — reserved group vs. admin-owned.~~
   **DECIDED (2026-06-27):** `SYSTEM:synaplan` is **admin-owned**, read-only to
   end users, and seeded **create-if-absent** (never overwritten) so self-hosters
   can replace it with their own; a `--force`/reset command restores stock
   content. See §3 Layer B / §5.
2. ~~**GitHub ingestion cadence + content scope**~~ **DECIDED (2026-06-27):**
   **manual `app:seed` re-run** for v1 (create-if-absent; `--force`/reset to
   refresh; no scheduled job, no per-query GitHub calls). Content scope:
   `synaplan-docs` **only** + a curated tech-stack reference (Qdrant, Redis,
   Stripe, PHP 8.5, Docker, K8s, Helm). No README/CHANGELOG/code repo. See §3
   Layer C / §5 / §6 S3.
3. ~~**Content languages**~~ **DECIDED (2026-06-27):** author the system prompt
   **and** curated KB content in **all four languages** (`de/en/es/tr`);
   cross-lingual retrieval is only a safety net. See §2.4 / §3 Layer C.
4. ~~**Pricing/plan answers**~~ **DECIDED (2026-06-27):** the product topic
   **never quotes pricing/limits** — it links to the canonical upgrade/pricing
   page. Baked into the `synaplan` system prompt + a planner guardrail. See
   §2.5 / §3 Layer D.

---

## 8. Definition of done

- A product/how-to question in `de/en/es/tr` routes to the `synaplan` topic and
  returns a grounded, accurate answer (verified by routing characterization +
  manual spot-checks in all four locales).
- "What can you do?" reflects the **actual** enabled capabilities/topics for the
  asking user (registry-derived, not hardcoded).
- Factual product questions cite the curated KB; unknowns get an honest "see the
  docs" with a working link — no invented features.
- Pricing/plan questions return **no numbers** — only a benefit sentence + a link
  to the upgrade/pricing page (verified in all four locales).
- The `SYSTEM:synaplan` group is invisible in the per-user file manager and RAG
  listings.
- Full gate green (`make lint && make -C backend phpstan && make test &&
  docker compose exec -T frontend npm run check:types`); routing snapshots
  re-recorded and reviewed.

---

## 9. File index (touch points)

| Area | Paths |
|---|---|
| Routing prompts | `backend/src/Prompt/PromptCatalog.php` (`planPrompt()`, `tools:sort`, new `synaplan` topic) |
| Planner | `backend/src/Service/Multitask/TaskPlanner.php` (`buildSystemPrompt`, capability list), `TaskPlanExecutor.php` |
| Capabilities | `backend/src/Service/Multitask/Plan/Capability.php` (`rag_query`) |
| RAG | `backend/src/Service/RAG/VectorStorage/VectorStorageFacade.php`, `Service/File/VectorizationService.php`, `MessageHandler/ReVectorizeMessageHandler.php`, `Service/Multitask/Execution/Runner/ChatRunner.php` |
| Seed | `backend/src/Seed/` (new `synaplan` topic seeder + `SYSTEM:synaplan` RAG seeder), `app:seed` command |
| Config/flags | `backend/src/Service/Multitask/MultitaskRoutingConfig.php` (pattern), new `SELF_AWARE.*` flags |
| i18n | `frontend/src/i18n/{de,en,es,tr}.json` |
| Tests | `backend/tests/Characterization/RoutingCharacterizationTest.php`, `backend/tests/Unit/Service/Multitask/`, RAG scoping tests |
