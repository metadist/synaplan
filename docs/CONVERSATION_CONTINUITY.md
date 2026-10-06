# Conversation Continuity & Deep Memory

How Synaplan keeps a conversation coherent over hundreds of turns and makes
messages from months ago findable again. Two complementary mechanisms:

| Mechanism | Scope | Freshness | Storage |
| --------- | ----- | --------- | ------- |
| **Rolling conversation summary** | one chat | refreshed asynchronously after every long-chat turn | Redis cache over a durable `BCHATSUMMARIES` row |
| **Message digests (deep memory)** | all of a user's history | indexed daily, out of band | `BMESSAGEDIGESTS` (authoritative) mirrored into the Qdrant `user_message_digests` collection |

Both are injected into the system prompt on **every channel** — web chat,
WhatsApp, email, MCP, and the HTTP API — on both the streaming and the
non-streaming path.

**Never answer without older context.** The just-persisted current turn is
excluded from the verbatim history budget, so a long paste or a file-heavy
reply cannot evict prior turns. When a chat has already outgrown the window
but no `BCHATSUMMARIES` row exists yet, the hot path injects a capped raw
excerpt of the older span (no extra model call). The most recently updated
*other* chat is also replayed as a short verbatim tail so a follow-up in a
new window can see what was just said there. After every turn the worker
indexes other chats immediately (`DigestOtherChatsCommand`); `QUIET_SECONDS`
applies only to the live chat.

## Rolling conversation summary

When a chat outgrows the verbatim context window, the older part is condensed
into a structured markdown summary (gradient compression: the oldest turns are
condensed hardest). The newest turns are always replayed word for word.

- **Generation:** asynchronous, via the Messenger worker
  (`RefreshConversationSummaryCommand`), so answering never waits on the
  summarizer. The model is the `SUMMARIZE` default (falls back to `SORT`, then
  `CHAT`), configurable per install in the model settings.
- **Storage:** written to the `BCHATSUMMARIES` table and cached in Redis
  (read-through: a Redis miss falls back to the DB row and re-warms the
  cache). A config-change fingerprint invalidates stale summaries.
- **Cleanup:** deleting a chat deletes its summary row.
- **Admin knobs:** Admin → System Configuration → Routing → *Rolling
  conversation summary* (`CONVERSATION_SUMMARY.*` BCONFIG rows, ownerId 0).
  Kill switch: `CONVERSATION_SUMMARY_ENABLED`.

## Message digests (deep memory)

A daily job walks each user's new messages and asks the memory model to pick
the **KEY messages** — documents with their content, results, decisions,
important facts/amounts/dates — and write one searchable title per message
("office rent letter to realtor about the increase of payments"). Requests to
the assistant ("make a chart of this") and notes that a file was created are
skipped; only the facts they contain count. Titles are written in the language
the user writes in, so a German question also finds an English contract. The
model sees the existing titles
of the batch's chats and the user's 30 newest titles, so a task repeated in
another chat is not indexed twice. Each digest row is embedded and indexed in
Qdrant.

During a chat turn, the user's prompt embedding (already computed for memory
search — no extra model call) searches the digest index. Hits are re-ranked by
`vector score × 0.5^(age / half-life)`; the best hits get their **original
message pulled verbatim** into the prompt so the model can quote amounts and
dates. The model cites sources as `[Message:ID]`, rendered in the web UI as a
clickable badge that opens the source chat; WhatsApp/email/MCP receive the
quoted digest title instead.

- **Job:** `app:digest:run` — self-locking, scheduler-driven (daily, wired in
  `container-runtime.sh`). Per-user cost caps (`BATCH_SIZE` ×
  `MAX_BATCHES_PER_USER` model calls max per run) and a per-user cursor, so
  every message is billed once. A failed model call keeps the cursor; a
  provider outage stops the whole run, and a batch that fails three times is
  skipped. Every batch checks the user's cost budget first. Messages in the
  *live* chat younger than `QUIET_SECONDS` are left to the rolling summary,
  and a pass stops below them until they leave that window. After each completed
  turn, `DigestOtherChatsCommand` indexes the user's other chats without
  that quiet window. A short verbatim tail of the most recently updated
  other chat is also injected on the hot path (SQL only, no digest wait).
- **Start point:** a migration stores the newest message id at upgrade time
  as `DIGEST.START_AFTER_ID`. A user without a stored cursor starts after it,
  so existing history is not indexed (or billed) automatically; use the
  backfill below for that. A fresh install stores 0.
- **Exclusions:** widget/guest chats are never digested; users with memories
  disabled are skipped; the whole feature honours the user's memory opt-out at
  retrieval time too.
- **Cap:** at most `DIGEST.MAX_PER_USER` active digests per user (default
  5000); on overflow the oldest are deactivated first.
- **Cleanup:** deleting a chat deactivates its digests and drops their
  vectors; account deletion purges rows and vectors.
- **User view:** Memories → *Long-term memory* lists the active entries
  (title, date, channel, source chat) with *Open in chat*, a single delete
  and *Delete all* (`GET /api/v1/user/message-digests/entries`,
  `DELETE /api/v1/user/message-digests/{id}`,
  `DELETE /api/v1/user/message-digests`). A delete deactivates the row and
  drops its vector; the same message is never digested again. Entries are
  part of the memory export (`longTermMemory`). The tab stays while the server
  kill switch is off and entries remain, and disappears once it is off and
  empty.
- **Admin knobs:** Admin → System Configuration → Routing → *Deep memory
  (message digests)* (`DIGEST.*` BCONFIG rows, ownerId 0). Kill switch:
  `DIGEST_ENABLED` (stops both indexing and retrieval).

## Operational runbooks

All commands run inside the backend container
(`docker compose exec backend php bin/console …`) and are self-locking.

### Backfill history for existing users

Indexing runs forward from `DIGEST.START_AFTER_ID`. To index older history
(billed to each user's budget):

```bash
# One user, last 12 months, capped at 20 model calls
php bin/console app:digest:backfill --user=123 --since-days=365 --max-batches=20

# Everyone (mind the model cost: users × max-batches calls worst case)
php bin/console app:digest:backfill --all-users --since-days=365

# Preview what the model would pick, storing nothing
php bin/console app:digest:backfill --user=123 --since-days=365 --dry-run
```

A dry run (also on `app:digest:run`) still calls the model and is billed; it
prints a table of the proposed titles with their message ids. Later batches see
the titles earlier batches proposed, as a real run would.

Backfill never moves the per-user cursor; idempotency comes from the
one-digest-per-message unique key, so overlapping runs are safe.

### Re-embed after an embedding-model change (or lost Qdrant volume)

MariaDB is authoritative — the vector index can always be rebuilt:

```bash
php bin/console app:digest:reindex --user=123     # one user
php bin/console app:digest:reindex --all-users    # everyone with active digests
```

Point ids are deterministic, so re-indexing overwrites in place and can be
re-run after an interruption.

### Disable switches

| Switch | Effect |
| ------ | ------ |
| `CONVERSATION_SUMMARY_ENABLED` (admin UI) | no summary injection or refreshes |
| `DIGEST_ENABLED` (admin UI) | no daily indexing, no retrieval in chat |
| user's own memories toggle | that user is skipped by indexing AND retrieval; existing entries stay until the user deletes them |

### Quality evaluation (live model calls, not part of CI)

```bash
make -C backend summary-eval   # summary quality across providers (8-case corpus)
make -C backend digest-eval    # digest retrieval quality (hit@1 / hit@k / MRR)
```

`app:digest:eval` uses the production recency-decay formula and the real
embedding model; use `--min-score` / `--half-life-days` to trial new
thresholds before changing the BCONFIG rows.

## Data model quick reference

- `BCHATSUMMARIES` — one row per chat: summary text, fold watermark
  (`BUPTOMESSAGEID`), config fingerprint.
- `BMESSAGEDIGESTS` — one row per key message: title, channel, source date,
  `BACTIVE` soft-delete. Unique per (user, message).
- Qdrant `user_message_digests` — point id `dig_{userId}_{digestId}`
  (UUIDv5-mapped), payload carries user/chat/message ids, title, source date,
  `active` flag, embedding provenance.
