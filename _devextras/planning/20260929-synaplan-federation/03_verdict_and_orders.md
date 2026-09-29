# Federation — second review: cut it to one product, then ship (2026-09-29)

> Binding for the next sprint. [`00_master_plan.md`](./00_master_plan.md) stays the
> vision document. This file is the verdict on it and the only order list that
> matters until two installs can ask each other one question.
> [`01_review.md`](./01_review.md) already cut F0–F13 to the knowledge link; this
> review cuts the knowledge link itself down to what is actually shippable.

## 1. Honest verdict

The idea is genuinely cool: Synaplan instances that can ask each other questions
without copying documents is a real differentiator. The plan's load-bearing
choices are right — direct HTTPS for queries, mutual link + explicit publish as
the double opt-in, text excerpts only, default-off module. Keep those. Do not
reopen them.

But the plan as written is **three products, one fintech company, and one global
network protocol** stacked into a "v1.0":

1. **Knowledge exchange** (ask a peer's folder, get excerpts). Real, shippable,
   matches the RAG stack you already have.
2. **Token exchange** (brokered inference through a peer's provider key).
   A different product on a different seam (`MessagesGateway` `key_source`,
   tool loops, streaming, vision, metering), with provider-ToS, tax, and fraud
   questions the plan hand-waves.
3. **Capability exchange** (remote MCP/API calls on a peer's credentials).
   RCE-as-a-service from a trust perspective, spec'd in one table row.
4. **Global gossip directory + health engine + semantic index for 15k nodes.**
   Premature distributed-systems engineering for a network that today has zero
   nodes.
5. **Credit ledger + Stripe netting + future IOTA/ETH settlement.** That is a
   billing company, not a feature. VAT, merchant-of-record, cross-border
   payouts, wallet UX — none of it belongs in a knowledge-sharing sprint.

No user asked for all five at once. No two operators have yet shared one folder.
The 15k math (§4 of `02_federation_data_model.md`) is fun and irrelevant: the
next milestone is **2 installs**, and for 2 installs every gossip paragraph is
pure bug surface.

**The unbiased recommendation: ship product 1 only. Park 2–5 explicitly, not
"later in v1" but "not v1, revisit only if the knowledge link gets real use."**
A knowledge link that two real operators use weekly beats a protocol document
that describes five goods nobody has tried.

## 2. What is good (keep)

- **Double opt-in.** Link must be mutual AND each topic must be published onto
  that link. This is the privacy core. It survives every cut below.
- **Text, not vectors / files / keys.** Each instance embeds locally, answers
  from its own `RagScope`, returns capped excerpts. No embedding-compat
  problem, no vector-space poisoning, no key sharing. Correct and simple.
- **Direct HTTPS, no DHT/P2P/FIDO.** The single best simplification in the
  plan. Queries fail fast, fall back locally, need no overlay.
- **Default-off `FederationModule`.** Same tag pass as `TelegramModule`,
  flag off ⇒ no nav, no routes, no palette (U11). Correct.
- **`01_review.md` findings 1–13.** All still true, especially: `peer`
  key-source is a later seam, `StripeBillingModule` is the wrong seam,
  outbound URLs need SSRF guards, `@` needs domain+colon, only the folder
  owner publishes, remote excerpts are untrusted data.

## 3. Hard problems the plan underestimates

### 3.1 No validated pain, no named first operators

"Telekom shares its org chart with us" is a demo line, not a user. Who are the
first **two real operators**, what **one folder each** would they publish, and
why would they answer a stranger's server instead of sending a PDF? Until those
names exist, every protocol decision is speculation. Realistic first users are
boring: same org with two installs (HQ + subsidiary), two partner companies on
one project, a community knowledge base with 3–5 mirrors. Name them before
coding. If you cannot name two, do not build gossip for fifteen thousand.

### 3.2 Token brokering is probably against provider terms

Anthropic, OpenAI, and most providers prohibit reselling, proxying, or sharing
API access beyond your own use (exact wording varies by tier). Brokered
inference — "your `MessagesGateway` routes through my Claude key and I bill
you credits" — looks exactly like what those clauses forbid. The plan never
checks. **Before any F7 code, read the ToS of the first three providers you
would broker and get a written yes/no.** If the answer is no, the token market
is dead regardless of how clean the `peer` seam is. Cost arbitrage also rarely
works: you pay the provider plus proxy overhead plus settlement fees — why
would the buyer not buy direct? The honest answer is usually "they can't get a
key" (sanctions, procurement), which is precisely where brokering is riskiest.

### 3.3 Excerpt pagination exfiltrates the whole folder

"Excerpts only, ≤1200 chars, ≤6 per query" sounds safe. It is not a bulk-export
defence: an adversarial peer scripts 500 queries ("chunk 1… chunk 500") and
reconstructs the folder. Rate limits slow it, they do not stop it. The plan
needs to say this out loud: **publishing a folder means trusting the peer with
its readable content over time.** Mitigations that actually help: per-link daily
excerpt budget with a visible counter, per-topic query logging the owner can
read (Activity view), anomaly flag ("this link pulled 10× its usual volume"),
and honest publish copy ("Anyone on this link can keep asking until they have
read most of this folder. Only publish what you would email them."). Do not
promise "no bulk export" — promise "metered, logged, revocable."

### 3.4 The query leaving the server needs *user* consent, not just operator consent

The plan treats the link as operator consent. But the query text ("Is Anna's
contract renewed?") is *user* data leaving *your* server for a server you do
not control, possibly in another jurisdiction. GDPR-wise that needs a per-user
moment: first use per link shows where the question goes ("This question will
be sent to telekom.de, topic org, and answered from their folder. Nothing else
leaves this server. [Ask telekom.de] [Ask only my knowledge]"), remembered per
user per link, with a one-click revoke in the same place. Operator link ≠ user
consent. Build the consent line into F6 or do not ship chat addressing.

### 3.5 `@domain:keyword` is developer UX, not user UX

Typing `@telekom.de:org` correctly — domain, colon, exact keyword, no typo —
is a power-user move. Non-technical users will not discover it, will mistype
it, and will leak queries by guessing keywords. The plan's palette section
helps but still leads with syntax. **Lead with the picker, keep the syntax as
the shortcut:** `@` palette shows "Ask a linked server…" with server + topic
rows from *my links only*; picking one inserts the chip; typing the full
address still works for the fast. No `@*:keyword` fan-out in v1 — fan-out to
strangers contradicts the link-only trust model and multiplies the consent
problem by N.

### 3.6 Gossip leaks metadata and adds failure modes for zero v1 users

A replicated directory means every instance's topic titles, summaries,
languages, model keys, and sovereignty tags are broadcast to servers it has no
link with. "Projection of consented offers" does not fix it: the operator
consented to show `org` to *one partner*, not to publish its existence to
15,000 strangers. For v1 there is no directory beyond **my links**: on accept,
store the peer's signed catalog; on publish/unpublish, push the delta to linked
peers only. That is ~40 lines, converges instantly, leaks nothing beyond the
link, and needs no digest, no TTL engine, no Qdrant `federation_directory`
collection, no 5-minute scheduler you do not have (finding 5), no 3-node gossip
coordination (finding 4). Delete F2 from v1. Revisit gossip only when someone
has >20 links and complains.

### 3.7 The ops surface is huge for a default-off feature

Seven tables, three Messenger commands, nightly reconciliation, health
probes, a new Qdrant collection, seeds, denylists, PoW stamps — every line is
a support ticket from an operator who turned the flag on once. V1 must be:
**2 tables, 0 new collections, 0 periodic jobs, 0 seeds.** Health is
"reachable / not reachable on last try" computed at query time, not a
state machine. Seeds are "paste your peer's URL" — two dev installs exchange
URLs by hand (already in `01_review.md`). Anything with a timer waits.

### 3.8 Money is a separate company

Credits, receipts, reconciliation, prepaid caps, Stripe Connect vs invoicing,
VAT, who is the merchant, crypto settlement — each is a decision with legal
and accounting consequences. The plan correctly parks Stripe for the next
sprint but keeps the ledger/receipts design in v1 scope. **Cut the ledger too.**
V1 is barter with a visible counter ("this link answered 42 questions for you,
you answered 17 for them") and no monetary unit. If operators later want paid
topics, that is a pricing sprint with a lawyer in the room, not a protocol
field.

### 3.9 Protocol freeze is premature

Freezing `protocol: 1` before two installs have run for weeks turns every
lesson into compatibility debt. Ship the wire unfrozen (`protocol: 0`,
"experimental, breaks without notice"), freeze only after the hardening sprint
has run against two real installs for ≥2 weeks with no wire change. Third-party
implementers are a v2 audience; v1 has one implementer (you) and two operators.

### 3.10 Governance sneaks centralization back in

Seeds, membership rules, denylists, "who hosts the well-known docs" (§14.4–5)
are central powers with a decentral coat. For v1: no seeds list ships (empty),
no global denylist (per-link revoke is the moderation), no membership rules
beyond "the link UI states the terms." If a shared denylist ever exists, it is
a signed feed an admin explicitly subscribes to — never a default.

## 4. The simplification (binding)

### V1 is one sentence

> **Two servers, one mutual link, one published folder, one `@domain:keyword`
> answer — metered, logged, revocable, barter only.**

### V1 ships (6 endpoints, 2 tables, 0 jobs)

| Ships | Notes |
| ----- | ----- |
| `FederationModule` default-off | `FEDERATION_ENABLED` only. No seeds env. Flag off ⇒ 404 + no nav + no palette (U11) |
| Ed25519 identity + well-known | Keypair in secret store, `GET /.well-known/synaplan-federation`. Defer DNS TXT + rotation to hardening |
| `POST /api/v1/federation/link` | invite / accept / revoke. Invite carries a single-use token (expiry 24h) + requester's well-known URL. Accept stores peer key + peer catalog. Revoke kills both directions on next request |
| `POST /api/v1/federation/query` | signed query → excerpts over one owner-published `RagScope`. SSRF guard, rate limit, excerpt fence. No `/infer`, no `/call`, no `/quote`, no gossip, no reconcile |
| Admin UI `Manage → Federation` | Membership (join/leave = keypair create/delete), Links (invite/accept/revoke), Publications (publish/unpublish, owner-only), Activity (who asked what, denials). 5 locales, U1–U12 |
| Chat addressing | Picker-first `@` palette section (my links only) + `@domain:keyword` shortcut (no `:account:`), source card, per-link first-use consent line, honest timeout copy |

### V1 explicitly does NOT ship (parked, not "later in v1")

- Gossip, digests, TTL engine, `federation_directory` Qdrant collection, `@*`
  fan-out, transitive discovery, circle-of-trust browsing (F2, F12-directory).
- Token market, `/infer`, `/quote`, `peer` key source, `CapacityMarket`
  (F7, F10). Blocked on a provider-ToS read, not just code.
- Capability/MCP bridge, `/call`, `tools` offers (§6 of `02_federation_data_model.md`).
- Ledger, receipts, credits, caps-as-money, Stripe, crypto (F8, F9).
- Peer-health state machine, PoW stamps, denylists, abuse reports (F11).
- Account handles (`@domain:account:keyword`), DNS TXT, key rotation,
  third-party protocol doc beyond link+query (F13-full).
- Seeds list (ships empty), global directory browse, market UI.

### Data model for v1 (2 tables, not 7)

```text
federation_link
  id, peer_domain, peer_api_url, peer_key (ed25519, pinned on accept),
  status (pending_out | pending_in | active | revoked),
  direction (offer | consume | both), created_at, updated_at
  invite_token_hash (nullable, single-use, expires_at)

federation_publication        (my folders I published, per link)
  id, link_id → federation_link, owner_user_id, group_key (folder),
  keyword (slug), title, summary, languages, license,
  excerpt_budget_day, excerpts_served_day, created_at, updated_at
  UNIQUE(link_id, keyword)
```

- No `federation_directory`, `federation_topic`-as-global,
  `federation_offer`, `federation_ledger`, `federation_receipt`,
  `federation_peer_health` in v1. Activity is a log view over the existing
  request log + a small `federation_query_log` if the existing log cannot
  carry peer/link/keyword — decide in Order 4, do not pre-build a ledger.
- Peer catalog (their topics) is stored as the signed blob on the link row
  (`peer_catalog_json` + `peer_catalog_seq`), refreshed on accept and on
  peer push. No separate directory table.
- Galera-safe migration: raw `addSql`, `CREATE TABLE IF NOT EXISTS`, no
  Schema API, per AGENTS.md.

### Wire for v1 (`protocol: 0`, experimental)

```text
GET  /.well-known/synaplan-federation     public, identity + key + api url
POST /api/v1/federation/link              invite / accept / revoke (invite-token + signature)
POST /api/v1/federation/query             signed query → excerpts + source
GET  /api/v1/federation/catalog            signed peer, "what do you currently offer ME on our link"
```

`catalog` replaces gossip: cheap, link-scoped, no broadcast. Push-on-change
calls the peer's `catalog` refresh (best-effort, else they pull on next query
— stale catalog fails closed: unknown keyword ⇒ honest "no longer published").

## 5. Clear orders (do in this order, stop when red)

> Each order ends green on `make ci-local`. UI orders add `make test-e2e`
> before push. Backend-only orders do not need Playwright. Never commit to
> `main`; branch `feat/synaplan-network`; mobile class backend-only until
> the admin UI lands, then ota-candidate files go on the allow-list in the
> same PR.

### Order 0 — Name the first two operators (human, no code)

- [ ] Write down: operator A (domain + admin name), operator B, the one folder
      each will publish, and one sentence why a live query beats sending a file.
- [ ] Both confirm in writing: "we understand published folders are readable
      over time via repeated queries (§3.3) and queries leave the asker's
      server (§3.4)."
- [ ] If you cannot name two: **stop.** Do not start Order 1. A protocol
      without operators is a hobby.
- Done when: two names + two folders in STATUS.md. No code, no migration.

### Order 1 — Shrink the plan (docs-only, this file + STATUS)

- [ ] Mark `00_master_plan.md` §§5, 7, 8, F2/F7–F13 as PARKED with a pointer
      here. Mark `02_federation_data_model.md` capacity/tools/gossip/ledger
      sections as FUTURE (keep the zero-trust §5 — it still applies).
- [ ] Confirm the kill list in §4 above with the product owner (one yes).
- [ ] Done when: `STATUS.md` lists Orders 0–7 as the only next work.

### Order 2 — Sodium + well-known spike (backend, no UI, no migration)

- [ ] Inside the backend container, confirm `sodium_crypto_sign_detached`
      exists. If missing: stop and ask (finding 9) — do not add a dep silently.
- [ ] `FederationModule` (default-off) + `RecordSigner` (pure, unit-tested) +
      `InstanceIdentityService` (keypair in secret store) + public well-known
      route + `PublicWebhookUrlValidator`-style SSRF guard for outbound
      fetches (HTTPS only, reject private/local/DNS-rebinding, explicit
      allow-local flag for dev).
- [ ] Prove it with curl between two local installs (or two compose projects):
      A fetches B's well-known, verifies key, rejects http://169.254.169.254.
- Done when: `make ci-local` green + curl transcript in the PR. No tables yet.

### Order 3 — Link invite / accept / revoke (backend, 1 table)

- [ ] Galera-safe migration for `federation_link` only (human yes per AGENTS.md
      Ask-First: schema). Single-use invite token, 24h expiry, stored hashed.
- [ ] `FederationLinkService`: invite → pending_out / pending_in → accept
      (pins peer key, stores peer catalog blob) / revoke (both directions stop
      on next request; Activity notes it). Revoke copy: "Link removed. Queries
      in both directions stop now. Past answers stay where they were shown."
- [ ] Two-in-process-instances integration test: invite → accept → revoke,
      unlinked query rejected, tampered signature rejected, expired invite
      rejected.
- Done when: link lifecycle works with zero UI (API tests), `make ci-local`
  green. No gossip, no seeds, no health table.

### Order 4 — Knowledge query over one RagScope (backend, 1 table)

- [ ] Migration for `federation_publication` (owner-only publish enforced in
      service: publisher must equal folder owner — finding 8).
- [ ] `KnowledgeResponder`: verify signature + active link + keyword published
      on *this* link → `VectorSearchService` over exactly that owner's folder
      scope → excerpts (≤1200 chars, ≤6, title+score, no file ids/paths, no
      vectors) → per-link daily excerpt budget + Activity log row. Stale/unknown
      keyword ⇒ "no longer published", never a blind search of other folders.
- [ ] `KnowledgeClient` (outbound): signed request, 8s timeout, one fast retry
      only on connect timeout (never on 4xx), fail-closed to local knowledge.
- [ ] `RemoteExcerptSanitizer` (pure, unit-tested) + system-prompt fencing:
      remote text is quoted data, never enables a tool, never rewrites
      instructions (findings 10). Characterization snapshots re-recorded only
      if the classifier touches federation addresses — review each line.
- [ ] Abuse minimum for v1: per-link rate/min + per-link excerpts/day budget
      (visible counters), query text logged only as "asked <keyword> on <link>"
      by default (full text only with an explicit per-link admin toggle that
      states retention). Pagination-exfiltration warning in publish copy (§3.3).
- Done when: install A publishes one folder, install B's signed curl gets
  excerpts, unpublish stops the next request, `make ci-local` green.

### Order 5 — Admin UI: Membership + Links + Publications + Activity (ota-candidate)

- [ ] `Manage → Federation`, one page, tabs. Empty state = one sentence + one
      primary action (U5). Flag off ⇒ absent (U11). All copy in 5 locales (U9),
      consequence sentences per action (U3): invite / accept / publish /
      unpublish / revoke / leave each state what happens *here*.
- [ ] Findability (U2): pending invite = Approvals-style badge + row; link row
      shows direction + what each side offers; publication row shows keyword →
      folder + budget counters; Activity row per query/denial.
- [ ] Five questions on the open link row (U7): who (peer domain + key
      fingerprint short), who else (which local users' queries may use it —
      default: all, admin-narrowable), what it touches (which folders/keywords
      each direction), how to stop (revoke/unpublish, one click), where it came
      from (invite date + who accepted).
- [ ] Journeys 1–2 from `00_master_plan.md` §11 walked in browser (U10):
      join + first link, publish + unpublish. Light + dark + V2 + 320px + WCAG
      AA (U9).
- Done when: journeys walked, `make ci-local` + `make test-e2e` green, mobile
  allow-list updated in the same PR.

### Order 6 — Chat: picker-first ask + source card + consent (ota-candidate)

- [ ] `@` palette gains a "Linked servers" section listing *my links'* topics
      (server + keyword + summary). Typing `@domain:keyword` (domain + colon,
      finding 7) filters it; `@report.pdf` and `mail@telekom.de` never trigger
      (unit tests first). Max 3 addresses per message. No `:account:`, no `@*`.
- [ ] First use per user per link: inline consent line naming the destination
      ("Send this question to telekom.de (topic org)? Nothing else leaves this
      server. [Ask telekom.de] [Only my knowledge] [Always ask]"), remembered,
      revocable where it was granted (U3). Every federated answer carries a
      source card: "Answered by telekom.de · topic org", excerpts as citations,
      "AI answer over federated sources" label.
- [ ] Timeout/denial = one honest sentence (U8): "telekom.de did not answer in
      time — showing your own knowledge instead. Nothing left your server."
      (Or: "…has not published this topic anymore." / "…declined. Nothing was
      sent.") Never a stack trace, never a code.
- [ ] Journey 3 walked (U10) + full U9 matrix + 5 locales.
- Done when: B types `@<a-domain>:<keyword>`, gets A's excerpts with a source
  card; unpublish stops the next request; `make ci-local` + `make test-e2e`
  green.

### Order 7 — Harden, measure, then decide (backend + docs, no new features)

- [ ] Threat-model pass on what shipped: SSRF (incl. DNS rebinding + redirect
      following), replay (`nonce` + `issuedAt` window enforced), signature
      malleability, rate-limit bypass via multiple users, log injection from
      peer strings, key-compromise runbook (revoke + rotate + notify peer out
      of band). Fix or file every finding; no silent TODOs.
- [ ] Load reality check: 50 linked queries/min against one responder, p95
      added latency on the asker's chat turn, responder RAG p95 unchanged for
      local users. Numbers in the PR. If the asker waits >3s p95, the UX needs
      a "asking telekom.de…" progress state (U8) before merge.
- [ ] Run Orders 3–6 against the Order-0 operators for ≥2 weeks. Log: questions
      asked, answer usefulness (thumbs on the source card), unpublishes,
      revokes, abuse flags. **Freeze `protocol: 1` only after 2 weeks with no
      wire change** — until then it stays `protocol: 0` experimental.
- [ ] Write `docs/FEDERATION_PROTOCOL.md` for exactly what shipped (identity,
      link, catalog, query). One page per endpoint, curl examples, "what is
      NOT in v1" section. No `/infer`, no receipts, no settlement.
- Done when: runbook + numbers + 2-week log exist, protocol doc merged,
  product owner gives a written go/no-go for *any* parked item. Default is no.

## 6. Stop rules (read before coding)

1. **No Order 0 names ⇒ no code.** A link without two operators is a demo.
2. **No provider-ToS yes ⇒ no F7 ever.** Do not "build the seam anyway."
3. **No new table without Ask-First schema yes.** Two tables is the budget.
4. **No periodic job, no Qdrant collection, no seeds list in v1.** If a design
   needs one, the design is wrong for v1.
5. **No `@*`, no `:account:`, no transitive trust in v1.** Discovery = my
   links. Access = my links. Nothing else.
6. **No money in v1.** Counters, not credits. At price 0 there is no ledger.
7. **Freeze nothing until Order 7 says so.** Experimental wire until proven.

## 7. What happens to the parked ideas

They are parked, not deleted. Each gets one re-entry condition:

| Parked | Re-entry condition |
| ------ | ------------------ |
| Gossip / global directory | ≥1 operator has >20 links AND asks for discovery beyond them |
| Token market (`/infer`) | Written provider-ToS clearance + ≥2 operators with a real capacity mismatch + a payments owner (VAT/merchant decided) |
| Capability bridge (`/call`) | Knowledge link abused zero times in 3 months + one tool (read-only) with a named owner + a security review |
| Ledger / Stripe / crypto | Paid topics requested by ≥2 operators + finance/legal owner named |
| Account handles, DNS TXT, rotation | Requested by an operator, not by the protocol |
| Discord bot (`discord_ai_buddy.md`) | Knowledge link live ≥1 month; bot consumes `/query` only |
| 15k semantic index | The network has 500 instances. It has 0 today |

---

### One paragraph for the coding agent

Build a **default-off `FederationModule`**: domain + Ed25519 key, mutual
**links** (invite token + pinned peer key), per-link **publications**
(owner-only, keyword → folder). Over a link, `POST /query` runs the existing
`VectorSearchService` over exactly that folder and returns **capped text
excerpts** (no files, no vectors, no keys), fenced as untrusted data, metered
by visible counters, logged to Activity. Chat addressing is **picker-first**
with an `@domain:keyword` shortcut, a per-link first-use consent line, and a
source card. **No gossip, no tokens, no tools, no money, no jobs, no seeds.**
Two tables, `protocol: 0` until Order 7 freezes it. Walk journeys 1–3 in the
browser before merge.
