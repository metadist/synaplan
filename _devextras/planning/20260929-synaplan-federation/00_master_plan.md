# Synaplan Federation — Version 1.0 plan

> **Vision and history.** The binding plan is [`05_partners.md`](./05_partners.md).
> Where this file differs (directory, sharing UI, assistants, build order),
> `05` wins. Token market, payments and gossip below are parked.

> **Wir föderieren.** Two or more Synaplan installations opt in to share two
> things across a trusted link: **knowledge** (RAG excerpts) and **capacity**
> (model tokens). No central server, no data leaves without an explicit
> decision, and the wire format is open so anyone can implement it.

| | |
| - | - |
| **Status** | Reviewed 2026-09-29. Queued for the next sprint. See [`01_review.md`](./01_review.md). No product code yet. |
| **Branch** | `feat/synaplan-network` |
| **Type** | Vibe-coding sprint plan (F0–F13), backend-first, UI behind a default-off module. |
| **Scope v1.0** | **Pure opt-in.** Pairwise *Federation Links* between operators. Two goods: **Knowledge exchange** (RAG) and **Token exchange** (brokered inference). |
| **Supersedes** | The federation half of [`../discord_ai_buddy.md`](../discord_ai_buddy.md). That file's Discord bot becomes a *consumer* of this network, planned separately later. |
| **Binding contracts** | UX rules U1–U12 in [`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md) and AGENTS.md "Perfect UX & Stability". |
| **Owners of the surfaces** | `App\Module\Federation\FederationModule` + `App\Service\Federation\*` (backend); `Manage → Federation` + chat composer (frontend). |

Files in this folder:

| File | Content |
| ---- | ------- |
| `00_master_plan.md` (this) | Goal, decisions (§0), topology, protocol, exchange goods, payments, steps, gates |
| [`01_review.md`](./01_review.md) | Review + the slice that is actually the next sprint |
| [`02_federation_data_model.md`](./02_federation_data_model.md) | Exactly what is published, how updates spread, storage/bandwidth for 15k, zero-trust, MCP/API sharing |
| `STATUS.md` | Step log — next sprint is the knowledge link; the rest stays planned |

**Next sprint** (the only slice to build): two installs, one mutual link, one published folder, one `@domain:keyword` answer. Barter. No token market, no Stripe, no gossip mesh. Detail and the review findings are in [`01_review.md`](./01_review.md).

---

## 0. Decision checklist — tick every row before any code

The recommendation column is what this plan assumes. Change a row only with a
reason; several rows are **Ask-First** per AGENTS.md (schema, dependencies,
payment).

| # | Decision | Recommendation | Ask-First? | State |
| - | -------- | -------------- | ---------- | ----- |
| 1 | **Topology** | Federated **server-to-server HTTPS** for live queries + **epidemic gossip** for the directory. Not DHT/P2P, not hour-batched FIDO. (§2) | no | open |
| 2 | **Trust model** | **Pairwise opt-in Federation Links** (mutual handshake). A *circle* is the transitive set you choose to trust. Nothing is queryable without a link. (§3) | no | open |
| 3 | **Identity** | Domain + **Ed25519** keypair (`ext-sodium`), proven at `/.well-known/synaplan-federation`, optional DNS `TXT`. (§4) | no | open |
| 4 | **Goods exchanged** | Three, all metadata-published / result-only over a link: (a) **Knowledge** — signed query → excerpts, never files/vectors; (b) **Capacity** — brokered inference over a peer's model key; (c) **Capability** — a remote MCP tool / API call run on the peer's credentials. Full data model in [`02_federation_data_model.md`](./02_federation_data_model.md). (§6, §7) | no | open |
| 5 | **Token exchange shape** | **Metered brokered inference** — a new `peer` key source in `MessagesGateway`. No transfer of prepaid credits between vendors' accounts. (§7) | no | open |
| 6 | **Accounting** | Internal **credit ledger** with a signed per-request **receipt**; both sides reconcile. Pegged unit (1 credit = €0.0001). (§8) | no | open |
| 7 | **Settlement** | Next sprint is **barter only** (price 0). Prepaid caps and Stripe netting stay later. `StripeBillingModule` is end-user PRO billing and is **not** the settlement seam (§8). | **yes** | open |
| 8 | **Health / decay** | Local exponential-decay reputation; unreachable ⇒ probation ⇒ quarantine ⇒ drop. Records carry a TTL so dead nodes expire network-wide on their own. Gossip *hints* never evict directly (anti-poisoning). (§5) | no | open |
| 9 | **Gossip cadence** | Anti-entropy every 5 min to 3 random healthy peers + push-on-change. Convergence in minutes, not hours. (§5) | no | open |
| 10 | **Address scheme** | `@domain.tld[:account]:keyword` in chat, alongside the existing `@`-file-mention palette. (§6.1) | no | open |
| 11 | **Feature module** | `FederationModule` (env `FEDERATION_ENABLED`, `FEDERATION_SEEDS`), default **off** ⇒ no nav, no routes, no palette section (U11). | no | open |
| 12 | **DB schema** | New MariaDB tables (links, directory, offers, ledger, receipts, peer-health) via Galera-safe Doctrine migration. (§9) | **yes** | open |
| 13 | **New dependency** | None required for the core (uses bundled `ext-sodium` + Symfony HTTP + Messenger). Crypto settlement later would add one. | verify | open |

---

## 1. The idea, honestly scoped

Three concrete things an operator can do after v1.0:

1. **Know-how link (the "cartel").** I link my RAG with yours on a topic. A
   question I cannot answer locally is asked, live and signed, to your instance;
   your instance answers from exactly the folder you published, and I get quoted
   excerpts with a source line. *"Schau mal bei `@telekom.de:org` wie der
   Geschäftsführer heißt"* resolves to a knowledge query against the linked
   `telekom.de` instance — **only** if `telekom.de` published an `org` topic to
   our link. My Mistral can consume what your GPT-4 curated, because we exchange
   **text**, not models.

2. **Token phalanx (the capacity market).** I have spare Claude Opus 5.5 budget;
   you are out. You ask the directory *"Hast Du 2M von GPT-6 Astra?"*, find a
   peer that offers it, and your `MessagesGateway` transparently routes those
   calls through that peer's key — metered, priced, and settled. Point Cursor at
   your own Synaplan OpenAI-compatible endpoint and it "just switches" to
   whichever linked peer has the cheapest capacity for the requested model.

3. **Automatic directory.** Once linked, each side learns (by gossip) what the
   other — and the peers *they* trust — have opted to announce: topics and
   capacity offers. The directory builds itself; you never maintain a list by
   hand. You still choose what *you* announce and whom you trust.

Everything is opt-in twice: a **link** must be mutually accepted, and each
**topic/offer** must be explicitly published onto a link. Default state is a
lonely, fully-functional Synaplan that talks to nobody.

---

## 2. Topology — why not P2P, why not FIDO

The user is right that ad-hoc P2P (DHT/Kademlia, NAT holepunching, hour-batched
FIDO mail runs) is where these projects die. We take the boring, proven middle
that Matrix, ActivityPub and email itself use:

- **Live queries are direct HTTPS between two linked instances.** Request →
  response, sub-second, no routing, no relays, no store-and-forward. If the peer
  is down, the query fails fast and the caller falls back to local knowledge.
  This is the opposite of FIDO's latency and needs no P2P overlay.
- **The directory replicates by epidemic gossip** — the "infect your neighbours"
  part. Small signed records spread node-to-node in minutes; there is no global
  broadcast and no coordinator.
- **No DHT, no overlay network, no NAT traversal.** Every instance is already a
  public HTTPS server (it serves the app). We reuse that. An instance that
  cannot be reached over HTTPS simply cannot federate — which is fine and keeps
  the model dead-simple.

> **Decision:** Federated HTTPS for queries + gossip for the directory. This is
> the single most important simplification in the whole plan.

```text
   ┌───────────────┐  live signed query (HTTPS)   ┌───────────────┐
   │  my.synaplan  │ ───────────────────────────▶ │ telekom.de     │
   │  (requester)  │ ◀─────────────────────────── │ (responder)    │
   └──────┬────────┘   quoted excerpts + receipt   └──────┬─────────┘
          │  gossip (every 5 min, 3 peers)                │  gossip
          ▼                                               ▼
   ┌───────────────┐        directory records       ┌───────────────┐
   │   peer A      │ ◀────────────────────────────▶ │   peer B       │
   └───────────────┘   (signed, TTL, highest seq)   └───────────────┘
```

---

## 3. Trust model — the Federation Link

A **Link** is the unit of opt-in. It is a mutual handshake between two
operators, like adding a peer, not joining a public square.

1. Operator A opens `Manage → Federation`, enters `telekom.de`, chooses what to
   **offer** on this link (which knowledge topics, which capacity offers, a
   credit cap) and sends an invite.
2. A's instance fetches `telekom.de`'s well-known, verifies its key, and posts a
   signed `link.invite`.
3. Operator B sees a pending invite (Approvals-style inbox), reviews exactly
   what A offers and what A requests, sets B's own side, and **accepts**.
4. Both sides now hold a signed `Link` object: peer identity, offered topics,
   offered capacity, credit cap, direction (offer/consume/both), created/updated.

Properties:

- **Symmetric consent, asymmetric content.** Both must accept the link; each
  side independently decides what to expose on it. A can offer knowledge while B
  only offers tokens.
- **Circles are transitive discovery, not transitive trust.** Through B, A may
  *learn* about `bmw.de` (B's peer) in the directory, but A cannot *query*
  `bmw.de` until A and `bmw.de` form their own link. Gossip carries the *record*
  (so the directory is rich); the *query* still needs a direct link. This is the
  privacy-clean core: discovery is cheap and safe, access is explicit.
- **Revoke is one click**, kind-specific consequence: "Link removed. Queries in
  both directions stop now; any unsettled balance is frozen for reconciliation."

---

## 4. Identity & proof of domain

Identical to a well-run federation identity (kept minimal):

- **Instance = domain + Ed25519 keypair**, generated on first join, stored in the
  encrypted secret store (same path as plug keys — never in the DB, never
  logged). Signing via bundled `ext-sodium` (verify enabled in
  `synaplan-base-php`; no Composer dependency).
- **Well-known:** `GET https://<domain>/.well-known/synaplan-federation`

  ```json
  {
    "protocol": 1,
    "domain": "telekom.de",
    "key": "ed25519:9f2c…",
    "api": "https://synaplan.telekom.de/api/v1/federation",
    "software": "synaplan/2.14.0",
    "contact": "ai-ops@telekom.de"
  }
  ```

- **Optional DNS binding** `TXT _synaplan.<domain> "key=ed25519:…"` raises trust
  and is the recovery path after a lost key.
- **Key rotation:** a new key is announced in a record signed by the old key;
  domain control (well-known + DNS) is the ultimate root of trust.
- **Optional public accounts:** a user may opt in to a pseudonymous handle
  (`[a-z0-9][a-z0-9-]{1,31}`), so `@telekom.de:anna:contracts` addresses Anna's
  published topic. The instance signs and vouches for its accounts.

---

## 5. The directory & the health/decay engine

### 5.1 Directory record

Each instance publishes exactly **one** signed record; the directory is the set
of all records it has heard, keyed by domain (highest valid `seq` wins — no
consensus needed because only the key owner can sign for its domain).

```json
{
  "protocol": 1,
  "domain": "telekom.de",
  "key": "ed25519:9f2c…",
  "api": "https://synaplan.telekom.de/api/v1/federation",
  "seq": 42,
  "issuedAt": "2026-10-04T10:00:00Z",
  "expiresAt": "2026-10-18T10:00:00Z",
  "languages": ["de", "en"],
  "topics": [
    { "account": null, "keyword": "org",
      "title": "Org chart & leadership", "summary": "Boards, GF, org units.",
      "languages": ["de"], "license": "internal-quote-only", "documents": 320 }
  ],
  "capacity": [
    { "modelKey": "anthropic:claude-opus-5.5",
      "available": 4200000, "unit": "tokens", "per": "day",
      "price": { "amount": 0.6, "per": 1000, "currency": "credit" },
      "sovereignty": "eu" }
  ],
  "policy": { "logsQueries": false, "ratePerMinute": 30 },
  "sig": "ed25519:…"
}
```

Hard limits (named constants, enforced on receive): record ≤ 32 KB, ≤ 200
topics, ≤ 50 capacity offers, `expiresAt` ≤ 14 days. Leaving publishes a
tombstone (`"left": true`, higher `seq`). **A topic or capacity offer only
appears if the operator published it onto at least one link** — the record is a
projection of consented offers, never the raw catalog.

### 5.2 Gossip (the "infection")

- Every **5 min**, pick **3** random *active* peers, exchange a **digest** (256
  buckets; each bucket = hash of its sorted `(domain, seq)` pairs), then fetch
  only records in buckets that differ. Logarithmic convergence.
- **Push-on-change:** publish/unpublish/rotate ⇒ immediately push to 3 peers.
  Target: 95 % of the *linked* graph sees an unpublish within **15 min**.
- **Verify before accept:** signature, `seq`, size, expiry. First sighting of a
  domain ⇒ fetch its well-known once (queued, rate-limited) and match the key.

### 5.3 Health & rate-down ("until they are history")

Each node keeps its **own** health view — gossip only *hints*, it never evicts
(this defeats a partitioned or malicious node poisoning others):

| State | Trigger | Effect |
| ----- | ------- | ------ |
| `active` | recent successful contact | queried live, gossiped as healthy, `score → 1` |
| `probation` | missed probes for **> 5 min** | still gossiped; live queries skip it after one fast retry; `score` decays |
| `quarantined` | missed for **> 1 h** or repeated bad signatures | not queried, not gossiped as healthy; periodic slow re-probe |
| `dropped` | no contact for **> record TTL** (≤ 14 d) or tombstone | record removed; domain becomes "history" |

- **Score update:** success ⇒ `score = min(1, score*0.7 + 0.3)`; failure ⇒
  `score *= 0.5`. Simple, fast, no tuning rabbit-hole.
- **Records self-expire.** Even with zero explicit tombstones, a dead node
  vanishes network-wide when its last record passes `expiresAt`. This is the key
  to "rate them down until they are history" without any central authority.
- **Gossip hint handling:** "peer X reports Y down" only *lowers confidence* and
  schedules an early local probe; the observing node still decides from its own
  probe result. No node can evict another by assertion.

---

## 6. Good #1 — Knowledge exchange (RAG)

### 6.1 Addressing in chat

- `ChatInput.vue` already opens the mention palette on `@`
  (`FileMentionPalette.vue`). A federation token is `@` plus a **domain and a
  colon** (`@telekom.de:org`). A dot alone is not enough: `@report.pdf` and
  `@notes.zip` stay file mentions. `@` alone still lists files.
- Grammar: `@domain.tld[:account]:keyword`. Up to 3 per message; recognised only
  after start-of-text/whitespace so `mail@telekom.de` in prose never triggers.
- v1 resolves only domains reachable through an accepted **link**. An unlinked
  domain gets a one-line hint ("No federation link with telekom.de — send an
  invite in Manage → Federation"), never a blind outbound request.

### 6.2 The query wire (reuses today's RAG stack)

```text
POST {api}/query           (on the responding instance)
```

Request (signed by the requester's instance key; `sig` covers the body):

```json
{
  "protocol": 1, "from": "my.synaplan",
  "linkId": "lnk_7f…",
  "target": { "account": null, "keyword": "org" },
  "query": "Who is the CEO of Telekom?",
  "language": "de", "maxChunks": 6,
  "nonce": "b3f1…", "issuedAt": "2026-10-04T10:05:00Z", "sig": "ed25519:…"
}
```

Responder pipeline:

1. Verify signature + link is accepted + requester in good standing; apply the
   record's rate limits.
2. Map `target` → the knowledge folder published on this link.
3. Run the **existing** `VectorSearchService` over exactly that folder's
   `RagScope` (owner-bounded, unchanged IAM path — a publication is one extra
   network-visible grant on one folder).
4. Return **excerpts only** (each ≤ ~1200 chars, ≤ `maxChunks`), each with a
   title + score. Never file IDs, never downloads, never whole documents,
   **never vectors**.
5. Log the *query text* only if `policy.logsQueries` is true (default off).

The requester renders a **source card in the thread** (pattern §4.7 of the UX
contract): "Answered by **telekom.de** · topic **org**", excerpts as citations,
labelled an AI answer over federated sources. Remote text is treated as
untrusted data: fenced, length-capped, stripped of anything resembling a tool
call or system directive before it reaches the local model (prompt-injection
defence). Timeout ⇒ one honest sentence ("telekom.de did not answer in time —
showing your own knowledge instead. Nothing left your server.").

> **We exchange text, not vectors.** Each instance embeds locally with its own
> model. No cross-instance embedding compatibility is ever required and no peer
> can poison another's vector space. (Directory *topic* text is embedded locally
> into a `federation_directory` Qdrant collection so "who knows about tyres?"
> works semantically.)

---

## 7. Good #2 — Token exchange (brokered inference)

The novel part, and it drops cleanly onto an existing seam. `MessagesGateway`
already does "model resolve, **credential resolve**, translator dispatch,
**metering**" and tags each call `key_source: 'user' | 'operator'`. We add a
third source: **`peer`**.

### 7.1 How a token trade works

1. A user (or Cursor via the OpenAI-compatible endpoint) asks for a model the
   local instance **cannot** serve (no key, or out of budget).
2. `MessagesModelResolver` consults the **directory**: which linked peers publish
   a `capacity` offer for that `modelKey`, with enough `available`, at what
   price, sovereignty, health.
3. `MessagesGateway` opens a **brokered call**: `POST {api}/infer` to the chosen
   peer with the (translated) request body. The peer runs it against its own
   provider key, streams the completion back, and returns a **signed receipt**
   with the token counts and price.
4. The requester meters the call as usual (into `BUSELOG`/`BCOST`) with
   `key_source: 'peer'` and the peer's price as raw cost; the operator markup and
   the user-facing taximeter work unchanged.
5. Both instances append the receipt to the **link ledger** (§8).

```text
POST {api}/infer            (on the selling instance)
  headers: X-Synaplan-Sig: ed25519:…   X-Synaplan-Link: lnk_7f…
  body:   { "protocol":1, "modelKey":"openai:gpt-6-astra",
            "messages":[…], "stream":true, "maxTokens":4000,
            "quote":"qte_2a…", "nonce":"…", "issuedAt":"…" }
→ 200 (SSE or JSON) + trailing/again-signed:
          { "receipt": { "id":"rcp_9c…", "promptTokens":812,
            "completionTokens":1340, "price":{"amount":1.29,"currency":"credit"},
            "modelKey":"openai:gpt-6-astra", "sig":"ed25519:…" } }
```

### 7.2 Capacity discovery ("Hast Du 2M von GPT-6 Astra?")

- A directory query: find linked peers whose `capacity[modelKey].available ≥ N`,
  sorted by price × health × sovereignty preference.
- Surfaced two ways: an admin **Capacity market** panel (browse/quote/buy a
  budget block), and automatically inside `MessagesGateway` for transparent
  routing.
- **Quote before commit:** a peer can be asked for a `quote` (price + a short
  hold) so the buyer confirms the price before the spend. A quote is a signed,
  expiring token referenced by `/infer`.

### 7.3 Guardrails

- Per-link **spend cap** and **per-minute rate**; the gateway refuses to broker
  past the cap and tells the user in one sentence.
- **Sovereignty honoured:** a request tagged EU-only never routes to a US-cloud
  offer; the offer carries a `sovereignty` field.
- **Model names via the catalog only** (`ModelCatalog`/`ModelRepository`) —
  offers reference `service:providerId:tag` keys, never hardcoded names.
- The peer's provider key **never leaves the peer**; brokering is proxying, not
  key sharing.

---

## 8. Payments — feasible, not fashionable

Per-query on-chain micropayments are the trap. We separate **accounting** (every
request, exact) from **settlement** (periodic, netted).

### 8.1 The comparison

| Option | Per-query fit | Pros | Cons | Verdict |
| ------ | ------------- | ---- | ---- | ------- |
| **Credit ledger (internal)** | ✅ exact, zero fee | No dependency, instant, works offline of any chain | It's IOUs — needs eventual real settlement or trust | **v1 core** |
| **Stripe (net settlement)** | ⚠️ not per-query (fees) | Real money, invoices, tax handled by Stripe | `StripeBillingModule` bills end users, not peer operators. Needs Connect or invoicing, plus VAT. Fees only work on **netted** balances | **later**, after the knowledge link |
| **IOTA** | ✅ feeless microtx | Designed for machine micropayments | Wallet UX, on/off-ramp, ecosystem/regulatory maturity | **later**, behind provider iface |
| **ETH L2 (Base/OP)** | ⚠️ sub-cent, not free | Programmable escrow, sub-cent gas | Volatility, KYC, wallet/key mgmt, complexity | **later**, optional |
| **ETH mainnet** | ❌ | — | Gas ≫ a query's value | **no** |

### 8.2 The design

- **Unit:** 1 credit = **€0.0001** (four-decimal, integer micro-credits stored;
  no floats). Prices in offers are in credits.
- **Every brokered request writes a signed receipt** to the per-link ledger on
  **both** sides. Nightly the two sides exchange ledger digests and must
  reconcile; a mismatch freezes new spend on that link and raises an admin flag
  (U8 copy, not a stack trace).
- **v1 default = prepaid credit line per link, cap-limited.** The buyer pre-funds
  a balance (or the pair agrees a cap of unbacked credits, i.e. barter/trust).
  No escrow, no third party. Exposure is bounded by the cap.
- **v1 money = optional Stripe net settlement, and it is not a reuse of
  today's billing.** `StripeBillingModule` charges Synaplan end users for a
  PRO plan. Operator-to-operator settlement is a different product (Stripe
  Connect or invoicing, VAT, who is the merchant). Next sprint does not build
  it. When it is built, it is a new `StripeSettlement`, one netted charge per
  period.
- **Free/barter mode:** set price 0 and skip settlement entirely. Two partner
  companies who just want to share know-how never touch money.
- **Pluggable `SettlementProvider` interface** (`settleNet(linkId, amount)`):
  `NullSettlement` (barter), `StripeSettlement` (v1), and future
  `IotaSettlement` / `EthL2Settlement`. The ledger and receipts are
  settlement-agnostic, so adding crypto later is a plugin, not a rewrite.

> **Recommendation:** ship credits + prepaid caps + optional Stripe netting +
> barter. It is fully feasible now, needs no chain, and the receipt/ledger design
> makes IOTA a drop-in when it is worth the effort.

---

## 9. Backend building blocks

```
backend/src/Module/Federation/FederationModule.php   FeatureModule (FEDERATION_ENABLED, FEDERATION_SEEDS)
backend/src/Service/Federation/
  InstanceIdentityService.php   keypair, well-known, DNS proof, rotation
  FederationLinkService.php     invite / accept / revoke, per-link offers + caps
  DirectoryStore.php            records, topics, offers, peer-health (repositories)
  DirectoryReplicationService.php  digest, gossip, push, verify-on-receive
  DirectoryDigest.php           256-bucket digest (pure, unit-tested)
  RecordSigner.php              Ed25519 sign/verify via ext-sodium (pure)
  AddressParser.php             @domain[:account]:keyword (pure, unit-tested)
  HealthEngine.php              score decay + state machine (pure, unit-tested)
  KnowledgeResponder.php        inbound /query over an existing RagScope
  KnowledgeClient.php           outbound signed /query, merges + cites results
  RemoteExcerptSanitizer.php    fence + strip injection from remote text (pure)
  CapacityMarket.php            match modelKey ↔ peer offers, quotes
  BrokerClient.php              outbound /infer (requester side)
  BrokerResponder.php           inbound /infer over an existing provider key
  LedgerService.php             receipts, per-link balance, reconciliation
  Settlement/SettlementProvider.php + NullSettlement + StripeSettlement
backend/src/Message/            async: ReplicateDirectory, ProbePeer, ReconcileLedger
```

Integration seams (extend, don't fork):

- `App\AI\Messages\MessagesGateway` — add `key_source: 'peer'` + broker path.
- `App\AI\Messages\MessagesModelResolver` — consult `CapacityMarket` when local
  capacity is missing.
- `App\Service\RAG\VectorSearchService` + `RagScope` — reused verbatim by
  `KnowledgeResponder`.
- `App\Service\RateLimitService::recordUsage()` → `BUSELOG`/`BCOST` — brokered
  calls meter here with the peer price as raw cost.
- `App\Service\BillingService` — end-user metering only. Peer settlement does not call it until a later sprint.
- `App\Model\ModelCatalog` / `ModelRepository` — capacity offers by model key.

DB (one Galera-safe migration, `CREATE TABLE IF NOT EXISTS`, no Schema API,
delete children before parents): `federation_link`, `federation_directory`,
`federation_topic`, `federation_offer`, `federation_peer_health`,
`federation_ledger`, `federation_receipt`. Directory topic text embeds locally
into a `federation_directory` Qdrant collection.

**Testing:** every pure class unit-tested; `KnowledgeResponder` and
`BrokerResponder` integration-tested against a fixture directory + mock Qdrant +
mock provider; two in-process instances exercise a full link → query → infer →
receipt → reconcile loop. Replication, probing and reconciliation run on the
Messenger worker, never inline in a chat turn. If federation addresses touch the
classifier, re-record the routing characterization snapshots and review each line.

---

## 10. Endpoints

Peer-facing (signed, under the module gate):

| Route | Auth | Purpose |
| ----- | ---- | ------- |
| `GET /.well-known/synaplan-federation` | public | Identity + key |
| `POST /api/v1/federation/link` | signed peer | invite / accept / revoke |
| `GET /api/v1/federation/directory/digest` | signed peer | 256-bucket digest |
| `POST /api/v1/federation/directory/records` | signed peer | push/pull records |
| `POST /api/v1/federation/query` | signed peer + link | knowledge query → excerpts |
| `POST /api/v1/federation/quote` | signed peer + link | price hold for a capacity buy |
| `POST /api/v1/federation/infer` | signed peer + link | brokered inference → completion + receipt |
| `GET /api/v1/federation/health` | signed peer | liveness for scoring |
| `POST /api/v1/federation/ledger/reconcile` | signed peer + link | nightly digest exchange |

Admin/UI (session-auth, same origin): `…/membership`, `…/links`,
`…/publications`, `…/directory`, `…/market`, `…/ledger`, `…/activity`. All ship
complete OpenAPI annotations so Zod schemas generate.

---

## 11. Journeys the UI sprints must walk (U1)

1. **Join + first link.** `Manage → Federation` empty state → **Join**, see this
   server's address + key fingerprint → invite `telekom.de` → they accept →
   link row shows what each side offers.
2. **Publish a topic onto a link.** Pick a knowledge folder → keyword → publish
   on the link → it appears in the directory → the peer can address it. Unpublish
   ⇒ answers stop now, entry gone within ~15 min.
3. **Ask a peer from chat.** Type `@telekom.de:org …` → Federation palette →
   source card with excerpts. Timeout ⇒ honest fallback sentence.
4. **Buy capacity.** Market panel: *"2M of `openai:gpt-6-astra`"* → quotes from
   linked peers → confirm price → subsequent calls route via the peer, metered,
   with a receipt; balance visible in the ledger.
5. **A peer goes dark.** Stop the peer; within minutes its rows show *probation →
   quarantined*; queries fall back locally with honest copy; the record later
   expires to *history*. Bring it back ⇒ it recovers to *active*.

Each journey walked in the browser (U10) in light, dark, V2, at 320 px, WCAG AA;
copy in all five locales (U9). Consequence copy for publish/unpublish/revoke/buy
is kind-specific (U3, U7). Flag off hides everything (U11).

## 12. Exit criteria (per §6 sprint-file contract)

For every `ota-candidate` step: (1) named journey walked; (2) findability in ten
seconds (link row, directory entry, ledger row, activity row); (3) kind-specific
consequence copy in five locales; (4) empty + error + flag-off states; (5) dark +
V2 + 320 px. Plus federation-specific gates, asserted in tests: unpublish clears
the entry network-wide within the target window; an unlinked instance cannot
query; remote excerpts cannot inject; **no files and no vectors ever cross the
wire**; brokered spend never exceeds a link cap; ledgers reconcile or the link
freezes.

---

## 13. Sprints (vibe-coding order)

Small, reviewable steps; each ends green on `make ci-local` (add `make test-e2e`
before pushing a UI step). Backend-first so the protocol is proven before paint.

| # | Type | Deliverable |
| - | ---- | ----------- |
| **F0** | plan | This file reviewed; §0 ticked; `docs/FEDERATION_PROTOCOL.md` v1 frozen at `protocol: 1` |
| **F1** | backend | `FederationModule`, identity, well-known, `RecordSigner`, `AddressParser`, `HealthEngine` (+ unit tests) |
| **F2** | backend | `DirectoryStore` + migration; `DirectoryDigest`; gossip/push replication; verify-on-receive |
| **F3** | backend | `FederationLinkService` (invite/accept/revoke) + link storage |
| **F4** | backend | `KnowledgeResponder` over an existing `RagScope`; `KnowledgeClient`; `RemoteExcerptSanitizer` |
| **F5** | ota-candidate | Admin UI: Membership + Links + Publications (journeys 1–2) |
| **F6** | ota-candidate | Chat: palette Federation section + source card (journey 3) |
| **F7** | backend | `CapacityMarket`, `quote`, `BrokerResponder`, `BrokerClient`; `MessagesGateway` `peer` source |
| **F8** | backend | `LedgerService`: receipts, per-link balance, nightly reconcile |
| **F9** | backend | `SettlementProvider` + `NullSettlement` + `StripeSettlement` (**Ask-First: Stripe wiring**) |
| **F10** | ota-candidate | Capacity market UI + ledger view + Cursor/OpenAI-compatible transparent routing (journey 4) |
| **F11** | backend | Health/decay hardening, anti-Sybil join stamp, subscribable denylists, abuse reports |
| **F12** | ota-candidate | Directory browse + Activity + peer-health visualisation (journey 5) |
| **F13** | docs | `docs/FEDERATION_PROTOCOL.md` finalised for third-party implementers; example minimal peer |

F1–F4, F7–F9, F11 are backend-only (Playwright not required). F5, F6, F10, F12
are user-visible ⇒ `make test-e2e` before push.

---

## 14. Ask-First before building (AGENTS.md boundaries)

1. **Schema** — the seven `federation_*` MariaDB tables (Doctrine migration).
2. **Payments** — enabling **Stripe** net settlement (money movement between
   operators); and the policy default (barter vs prepaid vs Stripe).
3. **`ext-sodium`** present in `synaplan-base-php` for Ed25519 (verify; likely
   already there — no Composer dep expected).
4. **Seeds** — which domains ship in `FEDERATION_SEEDS` for bootstrap (metadist
   runs seeds as an ordinary peer, no protocol privilege).
5. **Governance well-known** — who hosts the membership-rules + denylist docs and
   under what name.

## 15. Later (v2+)

- **IOTA / ETH-L2 settlement** as `SettlementProvider` plugins (true per-query
  micropayments where the fee model justifies it).
- **Private circles** — invite-only sub-networks sharing a directory subset.
- **Reputation-weighted routing** — price × health × user feedback.
- **Escrow** for postpaid trading between operators without a prior credit line.
- **Discordyai** and other channels as consumers of the federated network
  (folds the [`../discord_ai_buddy.md`](../discord_ai_buddy.md) bot back in).
- **Direct discovery** of an unlinked domain via its well-known, behind an admin
  setting.

---

### One-paragraph pitch for the coding agent

Build a **default-off `FederationModule`**. An instance is a **domain + Ed25519
key**. Instances form **mutually-accepted Links**. A tiny signed **directory
record** (topics offered + model capacity offered) spreads by **5-minute gossip**;
dead peers **decay to history** via local health scores + record TTL, and gossip
only *hints*. Over a link, one instance asks another a **signed knowledge query**
and gets **quoted excerpts** (reusing `VectorSearchService` + `RagScope` — text
only, never files or vectors), or a **brokered inference** call routed through the
peer's model key (a new `peer` source in `MessagesGateway`, metered into
`BUSELOG`). Money is a **credit ledger** with **signed receipts**, settled
**netted via Stripe** or not at all (barter), behind a **pluggable
`SettlementProvider`** so IOTA/ETH can land later. Keep every user-visible surface
inside the U1–U12 contract. **We exchange text and tokens, never models and never
vectors.**
