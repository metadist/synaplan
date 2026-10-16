# Federation data model — what is shared, how it spreads, what it costs

Reviewed 2026-09-29. This is the **full v1.0 data model**. The next sprint
([`01_review.md`](./01_review.md)) builds only identity + link + knowledge
query; capacity offers, capability offers, and gossip are defined here and
land in later steps. Master plan: [`00_master_plan.md`](./00_master_plan.md).

The whole design in one line: **share small signed catalogs, keep the data
home, and authenticate every byte.**

---

## 1. Three layers, one rule per layer

| Layer | Example | Where it lives | Trust |
| ----- | ------- | -------------- | ----- |
| **Identity** | domain, public key, api URL | one signed record per instance, replicated to all | proven by domain + signature |
| **Catalog** | "I have a topic `org`; I sell Claude tokens; I expose an OCR tool" | inside that record, metadata only | signed by the owner |
| **Live exchange** | the actual answer, completion, or tool result | point-to-point, per request, never stored network-wide | authorized per link, per request |

The rule that makes it safe: **the network replicates catalogs, never
content.** A catalog entry is an offer to answer. The answer is computed on
demand, on the owning instance, and travels only to the one peer that asked.

---

## 2. What is federated — exactly

### 2.1 Published (signed, replicated to the whole network)

One record per instance. Metadata only. This is everything a stranger can
ever see without a link.

```jsonc
{
  "protocol": 1,
  "domain": "telekom.de",
  "key": "ed25519:9f2c…",              // instance public key
  "api": "https://synaplan.telekom.de/api/v1/federation",
  "seq": 42,                            // monotonic; highest wins
  "issuedAt": "2026-10-04T10:00:00Z",
  "expiresAt": "2026-10-18T10:00:00Z",  // ≤ 14 days; auto-expiry = auto-cleanup
  "languages": ["de", "en"],

  // ── Catalog: three kinds of offer, all metadata, all optional ──
  "topics":  [ { "keyword": "org", "title": "Org chart & leadership",
                 "summary": "Boards, GF, org units.", "languages": ["de"],
                 "license": "quote-only", "documents": 320 } ],
  "capacity":[ { "modelKey": "anthropic:claude-opus-5.5", "available": 4200000,
                 "per": "day", "price": { "amount": 0.6, "per": 1000 },
                 "sovereignty": "eu" } ],
  "tools":   [ { "name": "vin_decode", "title": "VIN decoder",
                 "summary": "German KBA vehicle lookup from a VIN.",
                 "inputSchema": { "type": "object",
                    "properties": { "vin": { "type": "string" } },
                    "required": ["vin"] },
                 "price": { "amount": 2, "per": "call" },
                 "readOnly": true, "sovereignty": "eu" } ],

  "policy": { "logsRequests": false, "ratePerMinute": 30 },
  "sig": "ed25519:…"                    // covers every field above
}
```

Hard caps enforced on receive: record ≤ **32 KB**, ≤ 200 topics, ≤ 50
capacity offers, ≤ 50 tool offers, title ≤ 120, summary ≤ 500, `expiresAt`
≤ 14 days. **A row appears only if the operator published it onto a link** —
the record is a projection of consented offers, never the raw catalog.

### 2.2 Exchanged live (point-to-point, per request, never replicated)

Three goods, three endpoints, each a signed request over an accepted link,
each returning a result **plus a signed receipt**:

| Good | Endpoint | Request carries | Returns |
| ---- | -------- | --------------- | ------- |
| **Knowledge** | `POST …/query` | keyword + question | text excerpts (≤ ~1200 chars each) + source |
| **Capacity** | `POST …/infer` | modelKey + messages | completion (SSE/JSON) + token receipt |
| **Capability** | `POST …/call` | tool name + args | tool result + call receipt |

`…/call` is the MCP/API bridge: the responder runs one of its own MCP tools
(the same registry as `McpServerFactory` — `rag_search`, a custom connected
tool, `vin_decode`) **with its own credentials**, and returns only the
result. It is a remote tool call, fully metered, never a credential handout.

### 2.3 Never leaves the instance — ever

Documents · files · raw chunks · **embeddings/vectors** · provider API keys ·
MCP/tool credentials · user data · memories · chat logs · the un-offered
catalog. No endpoint returns any of these, and no test may pass that lets one
cross the wire (§ exit gates in the master plan).

> **We federate the card catalog and run the library ourselves.** Text and
> results travel; the corpus, the keys, and the vectors stay home. Each
> instance embeds locally (bge-m3, 1024-dim), so no cross-instance vector
> compatibility is ever required and no peer can poison another's index.

---

## 3. How updates propagate

A last-writer-wins register keyed by domain. No consensus, no coordinator,
because **only the domain's key can sign its record**, so there is never a
real conflict — the highest valid `seq` from that key is the truth.

| Mechanism | When | What moves | Cost |
| --------- | ---- | ---------- | ---- |
| **Push-on-change** | operator publishes / unpublishes / rotates a key | that one record, immediately pushed to 3 peers, which re-push | ~3 KB × small fan-out |
| **Anti-entropy gossip** | every **5 min**, 3 random healthy peers | a 256-bucket digest of `(domain, seq)` hashes; then pull only the buckets that differ | ~10 KB/round + diffs |
| **First sighting** | a domain never seen before | fetch its well-known once, verify the key matches, then accept | one HTTPS GET, rate-limited |
| **TTL expiry** | `expiresAt` passes with no refresh | the record is dropped locally, network-wide, on its own | zero — it is deletion by silence |
| **Tombstone** | explicit leave | a final record `"left": true` with a higher `seq` | ~0.3 KB |

Propagation targets: an unpublish reaches **95 % of the linked graph within
15 minutes** via push; gossip guarantees eventual convergence for the rest.
Epidemic spread is logarithmic in the number of instances, so 15k converges
in the same handful of rounds as 150.

**Integrity on every hop:** verify signature, monotonic `seq`, size caps, and
expiry before accepting a record. A tampered or replayed record is dropped, so
a relaying peer cannot alter what it forwards.

---

## 4. Storage & bandwidth for a 15,000-instance network

Each instance holds the whole directory: **15,000 records**. Real networks
are power-law (a few big catalogs, most tiny), so the typical record is small;
the 32 KB cap bounds the worst case.

### 4.1 Storage on each instance

| Component | Per record | × 15,000 | Notes |
| --------- | ---------- | -------- | ----- |
| Directory metadata (JSON + parsed rows + indexes) | ~3 KB typ | **~90 MB** | 32 KB cap ⇒ ~480 MB absolute worst case, never reached |
| Semantic index, **int8-quantized** (default) | ~3 KB (≈3 offers) | **~70 MB** incl. HNSW | so "who knows about tyres?" works over the whole world |
| Peer-health rows | ~0.2 KB | ~3 MB | state + score per peer |
| **Total (recommended, int8)** | | **≈ 165 MB** | trivial for any host already running Qdrant |
| Semantic index, float32 (if not quantized) | ~12 KB | ~270 MB incl. HNSW | total ≈ **365 MB** |

A global 15k-instance directory costs each server **well under a quarter of a
gigabyte**. For comparison, that is smaller than a single AI model file. The
semantic index is the only real cost and it is optional and quantizable; the
metadata alone (full directory, full addressing, full push/pull) is ~90 MB.

If even that is unwanted on a small node, the index can be scoped to your
**circle** (linked peers + the peers they announce) instead of the whole
world — but at 165 MB for everything, there is no need.

### 4.2 Bandwidth on each instance

| Event | Size | Frequency | Per day |
| ----- | ---- | --------- | ------- |
| Initial full sync | ~45 MB once | on join | — |
| Gossip digest round | ~10 KB each way | every 5 min × 3 peers | ~17 MB/day of digests |
| Record diffs (≈5 % daily churn) | ~3 KB × ~750 | steady | ~2–5 MB/day |
| **Steady-state total** | | | **< 25 MB/day** |

A live 15k network is **tens of MB per day** of background chatter after a
one-time ~45 MB sync. Live queries, inference, and tool calls are separate
and only happen when a user actually asks.

---

## 5. Zero trust between powerful friends

The network assumes every peer could be hostile, and stays useful anyway.
Trust is never ambient; it is granted one capability at a time and revoked in
one click.

1. **Membership grants nothing.** Being in the directory lets you be *found*,
   not *used*. Only an **accepted link** grants access.
2. **A link is a capability list, not a friendship.** It enumerates exactly
   which topics, which model offers, and which tools the peer may reach, plus a
   spend cap and a rate limit. Nothing implicit, nothing transitive — through a
   friend you may *discover* a third party, never *call* it.
3. **Every byte is authenticated.** Ed25519 signatures on every record and
   every request; domain ownership proven by well-known (+ optional DNS TXT).
   No cleartext trust, no shared secret, no bearer token to leak.
4. **Replay- and tamper-proof.** A `nonce` + a short `issuedAt` window; the
   signature covers the whole body. Old or altered requests are rejected.
5. **Inbound content is data, never authority.** Remote excerpts and remote
   tool results are fenced as untrusted quotes: they never auto-execute, never
   trigger a local tool, never modify a system prompt (prompt-injection
   defence).
6. **Bounded blast radius.** A compromised peer can, at most, spend up to its
   cap and see only what you placed on its link. It can read no other link, no
   file, no key.
7. **Everything is observable and revocable.** Every inbound and outbound
   request is logged (peer, link, action, cost) in the Activity view; revoking
   a link kills all its capabilities on the very next request.
8. **Credentials never travel.** Brokered inference and tool calls run on the
   owner's keys; the peer gets the *result*, never the *key*.

"Powerful friends" means the links are few, verified, and capable. "Zero
trust" means the protocol still assumes each one is an adversary — so a bad
actor among them costs you a capped bill and nothing else.

---

## 6. What this changes in the plan

- Adds a **third good, Capability (MCP/API calls)**, alongside Knowledge and
  Capacity. It rides the same identity, link, signing, ledger, and receipt
  machinery — one endpoint (`…/call`), one offer type (`tools`), reusing the
  existing MCP tool registry. It lands in the token sprint (F7–F8), not the
  first knowledge-link sprint.
- Pins the numbers: **~165 MB storage and < 25 MB/day** for a full 15k
  network, so "replicate the whole directory to everyone" is a decision we can
  make with confidence, not a worry.
- Restates the safety contract as an explicit **zero-trust** model (§5) that
  the master plan's exit gates already enforce.
