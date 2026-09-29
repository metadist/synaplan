# Synaplan Network + Discordyai — plan

| | |
| - | - |
| **Status** | Draft for review, 2026-09-29. The federation half is superseded by [`planning/20260929-synaplan-federation/`](./planning/20260929-synaplan-federation/00_master_plan.md) (reviewed, queued). This file's Discord bot stays parked until that link works. |
| **Branch** | `feat/synaplan-network` |
| **Parts** | A — Synaplan Network (federation + replicated directory) · B — Network knowledge in chat · C — Discordyai plugin |
| **Order** | A → B → C. Discordyai is one way into the network, not the core. |
| **First scope** | Parked. Build [`planning/20260929-synaplan-federation/`](./planning/20260929-synaplan-federation/01_review.md) first (pairwise knowledge link). |
| **Contract** | UX rules U1–U12 in [`planning/20260907_ux_user_flows.md`](./planning/20260907_ux_user_flows.md); every `ota-candidate` sprint below carries the five exit bullets from its §6. |

---

## 0. The idea in one page

Every Synaplan installation is a library. Its knowledge folders are the
books. Today every library is an island.

The **Synaplan Network** turns them into one worldwide library system:

- **Questions travel, books stay home.** A server never copies another
  server's documents. It sends a question to the server that knows, gets back
  a handful of quoted excerpts, and answers with sources.
- **Everyone keeps the card catalog.** The directory of *who knows what* — the
  **Atlas** — is not hosted by anyone. Every member keeps a full, signed copy
  and passes it on. Joining the network *means* carrying the Atlas. There is
  no read-only membership and no central server to switch off.
- **One address format for knowledge**, typed right into a normal chat:

  ```text
  @bmw-classic.de:bmw        How do I set the idle on a Solex 40 PDSI?
  @philately.com:anna:stamps Is a 1948 Bizone overprint with a shifted print rare?
  @*:stamps                  Who in the network knows about German stamp errors?
  ```

- **Discordyai** is an open-source plugin: any Synaplan server can switch it on
  and run its own openly-AI Discord buddy, `discordyai_123`, `discordyai_124`,
  … The number comes from the Atlas. Each bot answers from its own server's
  knowledge *and* from the whole network.

The network is the product. Discord, chat, the widget, MCP and the desktop
client are doors into it.

---

## 1. Non-negotiable principles

1. **Honest AI, always.** Every Discordyai is openly an AI character: Discord
   APP label, a bio that says so, and a truthful answer when asked. Network
   answers are labelled as AI answers with sources. This is required by EU AI
   Act Art. 50(1) (applies since 2026-08-02), by Discord's Terms (no automated
   user accounts, "self-bots"), and by the network's membership rules (§2.9).
   A fork that removes it can run, but it gets delisted.
2. **Knowledge stays home.** Only folders an owner explicitly publishes are
   reachable. Only excerpts that answer a specific question leave the server.
   No bulk export, no vector copies, no file downloads through the network.
3. **Publishing is explicit and undoable in one click.** Default off. Unpublish
   stops answers on the owner's server immediately; the directory entry
   disappears network-wide within minutes.
4. **Give to join.** A member serves the Atlas to others and stays reasonably
   in sync. Members that only take get no answers (§2.5, "good standing").
5. **Remote text is data, never instructions.** Excerpts from other servers are
   fenced, length-capped, sanitized, and can never enable a tool or change the
   system prompt.
6. **No privileged node.** Seeds exist only for bootstrap. Any member can be a
   seed. metadist runs seeds like anyone else, with no special power in the
   protocol.
7. **Default off, one feature module.** `NetworkModule` in
   `backend/src/Module/`; flag off ⇒ no nav entry, no palette section, no
   routes (U11).
8. **The user knows where a question goes** before it goes there for the first
   time.
9. **Ship text, not vectors.** Servers exchange text only. Each server embeds
   with its own model. No cross-instance embedding compatibility is ever
   required, and a peer cannot poison another peer's vector space.

---

## 2. Part A — Synaplan Network

### 2.1 Vocabulary

Primary UI copy never says *node*, *gossip*, *peer*, *Atlas* or *federation*.

| Internal term | User-facing (en) | Meaning |
| ------------- | ---------------- | ------- |
| Instance | Synaplan server | One installation, identified by its domain |
| Atlas | Network directory | Replicated list of servers and their public topics |
| Card | Public topic | One published knowledge folder, addressed by keyword |
| Knowledge address | Network address | `@domain.tld[:account]:keyword` |
| Member in good standing | — | Serves a fresh Atlas, answers health checks |
| Publication | Public on the Synaplan Network | Mapping keyword → knowledge folder |

German, Spanish, French and Turkish terms are fixed in the first UI sprint and
added to the canonical term list in `AGENTS.md` (e.g. de: *Synaplan-Netzwerk*,
*Netzwerkverzeichnis*, *öffentliches Thema*).

### 2.2 Identity

- **Instance = domain + Ed25519 key pair.** The key is generated when an admin
  joins and stored in the encrypted secret store (same path as plug keys).
  Signing uses `ext-sodium` (bundled with PHP — verify it is enabled in
  `synaplan-base-php`; no Composer dependency needed).
- **Proof of domain:** the instance serves
  `https://<domain>/.well-known/synaplan-network`:

  ```json
  {
    "protocol": 1,
    "domain": "bmw-classic.de",
    "key": "ed25519:9f2c…",
    "api": "https://synaplan.bmw-classic.de/api/v1/network",
    "software": "synaplan/2.14.0",
    "contact": "admin@bmw-classic.de"
  }
  ```

  HTTPS with a valid certificate is required. `api` may point to another host
  (delegation), so `bmw-classic.de` can run Synaplan on a subdomain.
- **Optional DNS binding:** `TXT _synaplan.<domain> "key=ed25519:9f2c…"`
  raises trust and is the recovery path after a lost key.
- **Key rotation:** the new key is announced in a record signed by the old key.
  If the old key is lost, domain control (well-known + DNS TXT) is the root of
  trust and a new record with a higher sequence number wins.
- **Public accounts:** a user on an instance may opt in to a public handle
  (`[a-z0-9][a-z0-9-]{1,31}`, unique per instance, pseudonymous by choice). The
  instance signs the account's cards; the instance vouches for its accounts.

### 2.3 Knowledge addresses

```text
address = "@" domain [ ":" account ] ":" keyword
domain  = DNS name with at least one dot, lowercased, IDN as punycode
account = slug   ; [a-z0-9][a-z0-9-]{1,31}
keyword = slug
```

| Typed | Meaning |
| ----- | ------- |
| `@bmw-classic.de:bmw` | Instance-level topic `bmw` |
| `@philately.com:anna:stamps` | Anna's public topic `stamps` |
| `@*:stamps` | Ask the network: Atlas lookup, fan-out to the best matches (phase N7) |
| `@bmw-classic.de` | Not a query — the palette shows that server's topics |

Rules:

- One segment after the domain is a keyword; two are account + keyword.
- Recognized only after start-of-text or whitespace, so `mail@bmw-classic.de`
  in prose never triggers.
- v1 resolves only domains that are in the local Atlas. An unknown domain gets
  a hint on the message ("bmw-classic.de is not in the network directory"),
  not a blind outbound request. Direct discovery via well-known for unlisted
  domains is a later option behind an admin setting.
- Up to three addresses per message; results are merged and cited separately.
- Coexists with the existing file mentions: `FileMentionPalette.vue` already
  opens on `@`. The palette gains a **Network** section as soon as the query
  contains `.` or `:` (§3.1). Real TLDs such as `.zip` or `.mov` look like file
  names, so the palette shows both sections and the user's pick decides.

### 2.4 The Atlas — the directory everyone carries

#### Record

Each instance publishes exactly one signed record. The Atlas is the set of all
valid records.

```json
{
  "protocol": 1,
  "domain": "bmw-classic.de",
  "key": "ed25519:9f2c…",
  "api": "https://synaplan.bmw-classic.de/api/v1/network",
  "seq": 42,
  "issuedAt": "2026-10-04T10:00:00Z",
  "expiresAt": "2026-10-18T10:00:00Z",
  "languages": ["de", "en"],
  "policy": { "logsQuestions": false, "retentionDays": 0, "ratePerMinute": 30 },
  "cards": [
    {
      "account": null,
      "keyword": "bmw",
      "title": "BMW classics 1950–1990: repair manuals and workshop notes",
      "summary": "Solex/Weber carburetors, M10/M20 engines, rust repair, parts cross-references.",
      "languages": ["de"],
      "license": "CC-BY-4.0",
      "documents": 1240,
      "updatedAt": "2026-10-01T08:12:00Z"
    }
  ],
  "apps": { "discordyai": { "number": 123, "claimedAt": "2026-10-04T09:58:00Z", "aiDisclosure": true } },
  "sig": "ed25519:…"
}
```

Limits (named constants, enforced on receive): record ≤ 32 KB, ≤ 200 cards,
title ≤ 120 chars, summary ≤ 500 chars. `expiresAt` ≤ 14 days: a record that is
not refreshed disappears, so dead servers leave the directory on their own.
Leaving the network publishes a tombstone (`"left": true`, no cards) with a
higher `seq`.

#### Replication

- **Conflict-free by construction.** The Atlas is a map `domain → record`. Only
  the key owner can sign a record for its domain; the highest valid `seq` wins.
  No consensus protocol is needed.
- **Anti-entropy gossip.** Every 5 minutes each member picks 3 random members,
  exchanges a digest (256 buckets keyed by a hash prefix of the domain, each
  holding a hash of its sorted `(domain, seq)` pairs), and fetches only records
  in buckets that differ. Convergence takes a logarithmic number of rounds.
- **Push on change.** When a member's own record changes (publish, unpublish,
  key rotation), it pushes to 3 members at once. Target: 95 % of the network
  sees an unpublish within 15 minutes.
- **Verify before accept.** Check the signature, `seq`, limits and expiry. For
  a domain seen for the first time, fetch its well-known once and check that
  the key matches (queued, rate-limited).
- **Bootstrap.** `NETWORK_SEEDS` lists a few seeds (metadist-run + community).
  After the first full sync, seeds are no longer needed.
- **Size.** 10,000 servers × ~5 KB typical record ≈ 50 MB — fine for MariaDB.

#### Everyone must distribute

Carrying the Atlas is the membership fee, and it is checked technically:

- A member must serve `GET {api}/atlas/digest` and
  `POST {api}/atlas/records`.
- Peers score each member: reachability, digest freshness (lag < 1 hour
  against what the peer knows), correct signatures.
- Search requests are signed by the requesting instance (§2.5). A responder
  answers only requesters that are **in good standing** in its own Atlas. A
  server that does not carry the Atlas cannot ask anyone anything.

#### Local storage and search

- MariaDB tables for instances, cards, app claims and peer scores (a Doctrine
  migration, idempotent SQL per the Galera rules: `CREATE TABLE IF NOT EXISTS`,
  no Schema API).
- Card text is embedded **locally** with the instance's own embedding model
  (bge-m3 by default) into a Qdrant collection `network_atlas`, so "who knows
  about oldtimer carburetors?" works semantically without any shared vectors.

#### Abuse resistance

| Threat | Mitigation |
| ------ | ---------- |
| Impersonating a domain | Well-known over valid TLS, optional DNS TXT, signatures |
| Tampering while gossiping | Signatures; highest `seq` from the owner key only |
| Sybil flood (thousands of fake servers) | Proof-of-work stamp on first join (~1 CPU minute); max 10 records per registrable domain; 24 h probation before a new server appears in search; subscribable denylists |
| Spam or misleading cards | Size limits; local hide; signed shared denylists (admins choose which to follow, Fediverse-style); reports to the listed contact |
| Leeching | Good-standing check on every search request |
| Stale or dead servers | `expiresAt`, health score, ranking by freshness |

### 2.5 Federated search — the wire

A search is a signed, size-bounded request that returns quoted excerpts, never
files or vectors.

```text
POST {api}/search        (on the responding instance)
```

Request (signed with the requester's instance key; `sig` covers the body):

```json
{
  "protocol": 1,
  "from": "philately.com",
  "account": null,
  "target": { "account": "anna", "keyword": "stamps" },
  "query": "1948 Bizone overprint shifted print — rare?",
  "language": "de",
  "maxChunks": 6,
  "nonce": "b3f1…",
  "issuedAt": "2026-10-04T10:05:00Z",
  "sig": "ed25519:…"
}
```

Response:

```json
{
  "protocol": 1,
  "answeredBy": "bmw-classic.de",
  "card": { "account": null, "keyword": "bmw" },
  "license": "CC-BY-4.0",
  "chunks": [
    { "text": "The Solex 40 PDSI idle mixture screw…", "documentTitle": "Solex 40 PDSI service", "score": 0.82 }
  ],
  "truncated": false
}
```

Responder pipeline (reuses today's RAG stack):

1. Verify signature; confirm the requester is a known instance in good standing;
   apply per-requester and global rate limits from the record `policy`.
2. Map `target` → a published knowledge folder via the publication table.
3. Run the **existing** `VectorSearchService` over exactly that folder's
   `RagScope` (owner-bounded, unchanged IAM path — a publication is just a
   second, network-visible grant on one folder).
4. Return only excerpts (each ≤ ~1200 chars, ≤ `maxChunks`), each with a title
   and score. Never file ids, never download links, never whole documents.
5. Optionally log the *question* only if the record's `policy.logsQuestions` is
   true and retention is disclosed; default is no logging.

The requester renders the answer as **Network sources** (§3.2). Remote text is
treated as untrusted data (principle 5): fenced, stripped of anything that looks
like a tool call or a system directive before it is handed to the local model.

### 2.6 Endpoints (all under the `NetworkModule` gate)

| Route | Auth | Purpose |
| ----- | ---- | ------- |
| `GET /.well-known/synaplan-network` | public | Identity + key |
| `GET /api/v1/network/atlas/digest` | signed peer | 256-bucket digest |
| `POST /api/v1/network/atlas/records` | signed peer | Push/pull records |
| `POST /api/v1/network/search` | signed peer, good standing | Federated query |
| `GET /api/v1/network/health` | signed peer | Liveness for scoring |
| `POST /api/v1/network/report` | signed peer | Abuse report to contact |

Admin/UI (session-auth, same origin):

| Route | Purpose |
| ----- | ------- |
| `GET/POST /api/v1/network/membership` | Join/leave, key status, seeds, standing |
| `GET/POST/DELETE /api/v1/network/publications` | Manage keyword → folder |
| `GET /api/v1/network/directory` | Browse/search the Atlas |
| `GET /api/v1/network/activity` | Who asked us what, what we asked, denials |

Every endpoint ships complete OpenAPI annotations so the frontend Zod schemas
generate (`make -C frontend generate-schemas`).

### 2.7 Backend building blocks

```
backend/src/Module/NetworkModule.php            FeatureModule (env: NETWORK_ENABLED, NETWORK_SEEDS)
backend/src/Service/Network/
  InstanceIdentityService.php   keypair, well-known, DNS proof, rotation
  AtlasStore.php                records + cards + peer scores (repositories)
  AtlasReplicationService.php   digest, gossip, push, verify-on-receive
  AtlasDigest.php               256-bucket Merkle-ish digest (pure, unit-tested)
  NetworkRecordSigner.php       Ed25519 sign/verify (ext-sodium)
  PublicationService.php        keyword ↔ knowledge folder, on/off
  FederatedSearchClient.php     outbound signed search (requester side)
  FederatedSearchResponder.php  inbound search over an existing RagScope
  RemoteExcerptSanitizer.php    fence + strip injection from remote text
  NetworkAddressParser.php      @domain[:account]:keyword  (pure, unit-tested)
  GoodStandingPolicy.php        who may ask us / whom we answer
  ProofOfWorkStamp.php          join anti-Sybil stamp
backend/src/Message/            async: ReplicateAtlasCommand, VerifyInstanceCommand
```

Pure classes (`AtlasDigest`, `NetworkAddressParser`, `NetworkRecordSigner`,
`RemoteExcerptSanitizer`, `ProofOfWorkStamp`) get unit tests; the search
responder gets an integration test against a fixture Atlas and a mock Qdrant.
Replication and search run through the Messenger worker, never inline in a chat
turn (stability, principle U-stability). Characterization: routing snapshots may
drift if network addresses touch the classifier — re-record and review.

### 2.8 Admin UX (journey walked before Vue — U1)

`Manage → Network` (one page, tabs — pattern §4.5):

- **Membership.** Empty state: one sentence + **Join the Synaplan Network**.
  After join: this server's address, key fingerprint, standing badge
  (Serving / Behind / Unreachable), directory size, **Leave** (states the
  consequence: entry removed network-wide, publications stop answering).
- **Publications.** Drive-class one-row add (pattern §4.1): pick a knowledge
  folder → keyword → language → license → **Publish**. Live list; each row
  answers U7 (who can reach it, what it exposes — excerpts only, where it came
  from) and has one-click **Unpublish** with the kind-specific sentence
  ("Answers stop now; the directory entry disappears within ~15 minutes.").
- **Directory.** Browse/search cards; **Add to a chat** copies the address.
- **Activity.** Who queried which publication, what this server asked, and every
  denial with a plain reason (U8). No raw errors.

### 2.9 Governance & legal

- **License:** protocol spec and reference code Apache-2.0 (repo license). The
  spec lives in `docs/NETWORK_PROTOCOL.md` so third parties can implement it.
- **Cards carry a content license** (CC-BY, CC0, "all rights reserved — quote
  with attribution"); answers always cite the source instance.
- **Membership rules** (served at a well-known URL, versioned): honest-AI
  disclosure, no illegal content, honor unpublish, carry the Atlas. Breaking
  them ⇒ signed denylist entry others may subscribe to.
- **GDPR:** publishing personal data is the publisher's responsibility; the UI
  warns on publish. A card's contact is the controller for its content. Search
  requests are minimized and, by default, not logged.
- **Right to leave:** tombstone + local delete; other members drop the record on
  expiry even if they miss the tombstone.

---

## 3. Part B — Network knowledge in chat

### 3.1 Composer

- `ChatInput.vue` already opens the mention palette on `@`. Extend the trigger:
  once the token after `@` contains `.` or `:`, `FileMentionPalette.vue` shows a
  **Network** section (from the local Atlas) beside the existing **Files**
  section. Typing `@domain.tld:` lists that server's keywords; `@` alone still
  lists files, so nothing regresses for existing users.
- Selecting a card inserts the address as a styled chip. `@*:keyword` inserts an
  "ask the network" chip.
- Flag off ⇒ no Network section at all (U11).

### 3.2 Answer rendering

- A network answer appears as a **card in the thread** (pattern §4.7), not a new
  page: "Answered by **bmw-classic.de** · topic **bmw** · CC-BY", excerpts with
  titles and scores, and a one-line "AI answer from network sources" label.
- Failures are one sentence with recovery (U8): "bmw-classic.de did not answer
  in time — showing your own knowledge instead." Never a stack trace or an HTTP
  code, and it says what did *not* happen (no data left your server).
- Sources render like existing RAG citations; `MessageText.vue` gains a network
  source variant.

### 3.3 Where else it flows

The same `FederatedSearchClient` is reachable from:

- **MCP**: a `network_search` tool beside the existing `rag_search`
  (`McpServerFactory`), so external agents can query the network too.
- **Widget / API / Saved Tasks**: an assistant may be granted a network topic
  as a source, so a scheduled task can pull from a peer. Off by default per
  assistant.

Each surface honors the module gate and the good-standing rules.

---

## 4. Part C — Discordyai plugin (open source)

Built on the plugin system (`plugins/*/manifest.json`, autoloaded backend +
frontend + migrations + agents — see [`plugins/README.md`](../plugins/README.md)).
Anyone with a Synaplan install enables it and runs their own openly-AI Discord
buddy that answers from their knowledge **and** the whole network.

### 4.1 Layout

```
plugins/discordyai/
  manifest.json            id, namespace Plugin\Discordyai, capabilities [api, frontend, migrations], license MIT
  backend/
    Controller/            admin API: connect, status, opt-in log, safety flags
    Service/
      DiscordGatewayBridge.php   talks to the sidecar (§4.3)
      DiscordPersona.php         character, running gags, opinions (per language)
      DiscordConversation.php    one Discord event → one Synaplan chat turn
      BotNumberClaim.php         claims discordyai_<n> via the Atlas (§4.2)
      DiscordSafety.php          moderation in/out, minors, crisis, no-ads
      OptInMemory.php            /remember, /forget-me — opt-in only
      BuddyMatch.php             /buddy join, consented, moderated (§4.5)
    tests/                       fixtures, no live network
  agents/
    discordyai.json          the persona assistant (topic: discordyai_persona)
  migrations/01-init.sql     opt-in table, per-Discord-id memory scoping
  frontend/                  admin panel: connect, channels, opt-ins, flags, safety queue
```

Internal prompts use the `tools:` prefix so the sorter never picks them for a
user: `tools:discord_opinion`, `tools:discord_safety`, `tools:discord_persona`.

### 4.2 The bot number

- Each Discordyai claims the next free integer in the Atlas: the claim lives in
  the instance record under `apps.discordyai.number` (§2.4). A joining server
  scans known claims and takes `max + 1` (123 → 124 → 234 → …; gaps are fine,
  the number is an identity, not a count).
- Claims are first-come; a duplicate is resolved by earliest `claimedAt` under
  the older instance key, and the loser re-claims. Because the number is in the
  signed record, it cannot be forged.
- The Discord display name is `discordyai_<n>`; the profile bio states it is an
  AI character built with Synaplan and names its home server.

### 4.3 Getting onto Discord (the one real dependency)

- Discord needs a **persistent Gateway WebSocket**; PHP-FPM cannot hold it. A
  small **sidecar** (Node or Python, shipped as an optional compose service,
  default off) holds the connection, handles rate limits and slash commands, and
  forwards events to `POST /api/plugin/discordyai/event`; the plugin calls the
  sidecar to send replies. This is a **new optional dependency** and an
  `ota`/infra change — flagged for explicit approval before build (§8).
- Uses an **official Discord application / bot**, added to servers by their
  admins (never a self-bot), plus **user-install** so individuals can DM the
  buddy. Message Content Intent and verification (>100 servers) are requested
  early. A privacy policy + terms ship with the plugin (Discord requires them).

### 4.4 Character & safety

- Persona per language (es/en/de): humor, favorite genres, running gags, stable
  opinions kept consistent for the three-month arc via `tools:discord_opinion`.
- Answers pull from the server's own knowledge and, when the address or intent
  calls for it, the network — so a niche question in a Discord channel can be
  answered by a peer that actually knows.
- No ads, ever; no unsolicited Synaplan links; deescalates fights; never asks
  age/location/school; crisis topics get a caring reply that points to real
  help. `DiscordSafety` moderates inbound and outbound and can route to a human
  review queue in the admin panel.
- Own posts (a "weekly take") are drafted by a scheduled task and, in the first
  weeks, **held for human approval** in the existing Approvals inbox
  (pattern §4.3) before posting. Max one self-post per channel per day, with
  per-timezone quiet hours.

### 4.5 People memory & matchmaking (opt-in)

- Memory about a person is **off** until they run `/remember on`; `/memory show`
  and `/forget-me` are one command each. Nothing about third parties, nothing
  scraped from public channels.
- `/buddy join` records games, languages, timezone, skill (consented). An intro
  happens only when **both** sides accept, and always in a moderated LFG thread
  on the home server, never a private cold DM — this protects minors and keeps
  every match visible to moderators.

---

## 5. Journeys (U1) each UI sprint must walk

1. **Join the network.** Admin: Manage → Network → Join → sees this server's
   address and standing. Exit: directory populates within minutes; Leave works
   and the consequence copy is honest.
2. **Publish a topic.** Pick a knowledge folder → keyword → Publish → the card
   appears in the directory; a peer can address it. Unpublish → answers stop now,
   entry gone within ~15 min.
3. **Ask a peer from chat.** Type `@bmw-classic.de:bmw …` → Network section in
   the palette → answer card with sources. Timeout path shows the honest
   fallback sentence.
4. **Ask the whole network.** `@*:stamps …` → best matches → merged, separately
   cited answer.
5. **Discordyai answers in a channel** from its own + network knowledge; asked
   "are you a bot?", it says yes; `/forget-me` wipes the asker's memory.

Each journey is walked in the browser (U10) in light, dark, V2, at 320 px, WCAG
AA, and copy lands in all five locales (U9) — Discord copy in es/en/de plus the
admin UI in all five.

## 6. Exit criteria (per the §6 sprint-file contract)

Every `ota-candidate` sprint below repeats these five, made concrete:

1. Named journey(s) from §5 walked in the browser.
2. Findability in ten seconds: directory entry, activity row, or chip.
3. Kind-specific consequence copy (publish/unpublish/leave) in all five locales.
4. Empty + error + flag-off states present (join empty state, timeout copy,
   module-off 404).
5. Dark + V2 + 320 px verified.

Plus network-specific gates: unpublish removes the entry network-wide within the
target window; a non-member cannot query; remote excerpts cannot inject; no
files or vectors ever cross the wire (asserted in tests).

## 7. Sprints

| # | Type | Deliverable |
| - | ---- | ----------- |
| **N0** | plan | This file reviewed; `docs/NETWORK_PROTOCOL.md` v1 (spec frozen at `protocol: 1`) |
| **N1** | backend | `NetworkModule`, identity, well-known, signer, address parser (+ unit tests) |
| **N2** | backend | Atlas store, digest, gossip/push replication, verify-on-receive, migration |
| **N3** | backend | Federated search responder over an existing `RagScope`; outbound client; sanitizer |
| **N4** | ota-candidate | Admin UI: Membership + Publications (journeys 1–2) |
| **N5** | ota-candidate | Chat: palette Network section + answer card (journeys 3–4) |
| **N6** | backend | Anti-abuse: proof-of-work join, good-standing, denylists, reports |
| **N7** | ota-candidate | `@*:keyword` fan-out + Directory browse + Activity |
| **N8** | backend/infra | MCP `network_search`; assistant/Saved-Task network source (approval needed) |
| **D1** | plugin | `discordyai` plugin scaffold, persona, bot-number claim, admin panel |
| **D2** | infra | Discord Gateway sidecar (optional compose service) — **approval needed** |
| **D3** | plugin | Safety layer, opt-in memory, weekly-take via Approvals |
| **D4** | plugin | Buddy matchmaking (consented, moderated) |

N1–N3 and N6 are backend-only (Playwright not required); N4, N5, N7 are
user-visible and require `make test-e2e`. Every commit passes the house gate
(`make ci-local`; add `make test-e2e` before pushing UI).

## 8. Decisions needed before building (Ask-First items)

Per `AGENTS.md` boundaries, these need your go-ahead before code:

1. **Schema:** new MariaDB tables for the Atlas (Doctrine migration).
2. **Dependency / infra:** the Discord Gateway **sidecar** service (new optional
   compose service, new runtime dep) and its Docker wiring.
3. **`ext-sodium`** available in `synaplan-base-php` for Ed25519 (verify; likely
   already present).
4. **Seeds:** which domains ship in `NETWORK_SEEDS` for bootstrap.
5. **Brand/governance:** who hosts the membership-rules + denylist well-known
   documents, and under what name (metadist as a peer, not an authority).

## 9. Later (v2+)

- **Private circles:** invite-only sub-networks (a company's own instances)
  sharing an Atlas subset — same protocol, scoped membership.
- **Reputation & ranking:** weight answers by peer standing and user feedback.
- **Paid topics:** a card may require an API key or payment (ties into the
  existing commerce modules).
- **Direct discovery:** resolve an unlisted domain via its well-known on demand,
  behind an admin setting.
- **Caching:** short-TTL cache of a peer's excerpts for repeated questions, with
  the peer's consent flag in its record.

---

### Rejected: the covert version

The originally requested covert buddy — a Discordyai that hides its AI nature,
runs as an automated **user** account, joins servers on its own, and silently
builds profiles on thousands of people — is not planned and should not be built.
It breaks Discord's Terms (self-bots are bannable), the EU AI Act Art. 50(1)
disclosure duty (since 2026-08-02; fines up to €15 M / 3 % of turnover), and GDPR
(covert profiling, unmet Art. 14 duties), and it endangers the many minors on
gaming servers. The open version above keeps every good part — a funny, sharp,
multilingual buddy that makes friends and connects people — while being legal,
Discord-legit, and the best possible showcase for the Synaplan Network.
