# Human Takeover Real-Time Delivery — Clustering & Redis/Valkey Plan

**Status:** DB-backed fix shipped (interim). Redis/Valkey push layer = future work.
**Last updated:** 2026-05-30

---

## 1. The bug (prio1)

During human takeover, operator and visitor messages only appeared **after a manual
page reload** — and at one point "only every second message" showed up live.

### Symptom timeline

1. First report: admin writes, message doesn't show until reload (same for the visitor's
   own messages).
2. After the optimistic-render fix: each **sender** saw their own message, but the
   **recipient** still only got "every second message".

### Root cause

Production runs **3 web nodes behind a round-robin load balancer** (Cloudflare in front).
Real-time widget events were stored in a **node-local filesystem cache**
(`cache.adapter.filesystem`, the old `WidgetEventCacheService`).

- The SSE stream is pinned to one node for its lifetime.
- The operator's `POST /reply` round-robins across all 3 nodes.
- Only replies that landed on the *same* node as the visitor's SSE stream were delivered
  live; the rest sat in another node's cache → invisible until reload (reload reads the
  shared DB).
- Second, independent bug: the old `publish()` did a **read-modify-write of one cache
  array with no lock**, so concurrent visitor+operator writes clobbered each other and
  dropped events even on a single node.

> Sticky/session affinity does **not** fix this: visitor and operator are different
> clients, so affinity pins them to *different* nodes — the data still has to cross nodes.
> The only shared medium that survives node reboots is the DB (Galera) or a shared
> pub/sub (Redis/Valkey).

### Cluster facts (from `synaplan-platform`)

- Nodes: web1 / web2 / web3 (internal IPs: see the private `synaplan-platform`
  inventory). LB = `web.synaplan.com` round-robin on :80.
- **MariaDB Galera** cluster — each node talks to its local Galera; DB is replicated
  (shared) → this is why "reload works".
- **NFS** shared storage on a separate storage box (uploads/config), but the app's
  `var/cache` is per-container (node-local).
- **No Redis** (deliberately removed; `cache.yaml` was filesystem-only).

---

## 2. The interim fix (SHIPPED) — DB-backed event store

Moved the SSE backing store from the node-local cache to the **Galera-replicated DB**,
so every node sees every event. Each publish is an atomic `INSERT` (kills the
read-modify-write race).

### Changes

| File | Purpose |
|------|---------|
| `backend/src/Entity/WidgetEvent.php` | New entity → table `BWIDGET_EVENTS` (append-only, per-row TTL). |
| `backend/src/Repository/WidgetEventRepository.php` | Stream queries (`findStreamEventsSince`, `maxStreamEventId`), operator-typing (latest-wins), `deleteExpired`. |
| `backend/src/Service/WidgetEventStoreInterface.php` | Transport abstraction (same public API as the old cache service). |
| `backend/src/Service/DatabaseWidgetEventStore.php` | DB implementation. Notifications reuse the table under session id `notifications`. |
| `backend/migrations/Version20260530000000.php` | Creates `BWIDGET_EVENTS`. |
| `config/services.yaml` | Alias `WidgetEventStoreInterface → DatabaseWidgetEventStore`. |
| `config/packages/cache.yaml` | Removed unused `cache.widget_events` pool. |
| _deleted_ `backend/src/Service/WidgetEventCacheService.php` | Old node-local cache service. |

Callers updated to the interface only (type hint): `HumanTakeoverService`,
`WidgetPublicController`, `WidgetSessionController`, `WidgetEventsController`.

Frontend (from the earlier turn): optimistic render of the sender's own message in
`ChatWidget.vue` (visitor) and `WidgetSessionsView.vue` (operator), de-duped against the
SSE echo by real message id.

### TTLs

- Regular events (message/takeover/handback/notification): 600s (10-min reconnect window).
- Operator typing indicator: 6s, latest-wins.
- Opportunistic purge of expired rows (~1/50 publishes); reads filter by expiry anyway.

### Gate status

PHPStan OK · PHP-CS-Fixer clean · 1763/1763 PHPUnit pass · migration applies clean ·
`BWIDGET_EVENTS` matches the entity mapping (no schema drift introduced).

### Known caveat (acceptable for interim)

Galera assigns auto-increment ids per node (interleaved offsets), so id order is not a
strict commit order — a row committed slightly later on another node can carry a lower id.
For a 1:1 takeover chat, simultaneous cross-node writes to the *same* session are rare, and
consumers de-dupe by message id, so this is safe. A Redis stream removes the caveat.

Optional belt-and-suspenders (not yet done): client reconcile-against-DB-history on SSE
reconnect.

---

## 3. Future work — Redis/Valkey push layer

### Decision: Valkey, not Redis

Synaplan is open-source and self-hosted. Redis 8 is AGPLv3/SSPL/RSALv2; **Valkey** is the
Linux Foundation **BSD-3** fork (default in Ubuntu 26.04 / Debian 13 / Fedora), wire- and
client-compatible. Use Valkey to avoid the license burden. Everything below applies to both.

### Redis is NOT Galera — expectation setting

| | Galera | Redis/Valkey |
|---|---|---|
| Topology | Multi-primary (write any node) | Single primary + replicas |
| Replication | Synchronous | **Asynchronous** |
| Failure | No committed-data loss | Small un-replicated-write loss window on failover |
| HA | Built-in quorum | Sentinel (failover) or Cluster (sharding) |

Async single-primary is fine here: the durable truth stays in Galera/`BMESSAGES`; Redis is
just the fast notification layer. A brief failover blip drops only transient events that
clients reconcile from the DB.

### HA choice: **Sentinel** (not Cluster)

Cluster = sharding for big datasets/throughput → unnecessary complexity for a tiny event
stream. Sentinel = one primary + replicas + quorum-based auto-failover → the "single
logical endpoint that survives reboots" we want.

### Topology for the 4 boxes

- **3 web nodes:** 1 Valkey primary + 2 replicas, **3 Sentinels** (one per node).
  Mirrors the Galera-per-web-node pattern.
- **Storage box:** Redis here is **not required**. Keep the Sentinel count
  **odd (3)** for a clean majority — do NOT add a 4th Sentinel (even = worse quorum).
  Optionally host a non-voting extra replica for backups; little SSE benefit.
- Quorum = 2 of 3 Sentinels → tolerates losing one node and still auto-fails-over.

```
web1:     Valkey PRIMARY + Sentinel
web2:     Valkey replica + Sentinel   (async replication from primary)
web3:     Valkey replica + Sentinel
storage:  (optional non-voting replica, NO sentinel)
```

### Rolling reboot without stopping the cluster

Same discipline as the Galera rolling-deploy doc (one node at a time, keep majority up):

1. Reboot a replica → zero impact; it resyncs on return. Repeat for the 2nd replica.
2. Reboot the primary → do a **controlled** failover first to shrink the window:
   ```bash
   valkey-cli -p 26379 SENTINEL FAILOVER mymaster   # promote a replica now
   # confirm new primary, then reboot old primary (rejoins as replica)
   ```
3. One machine at a time; always keep ≥2 Sentinels + ≥1 healthy replica. Wait for
   `SENTINEL master mymaster` to show resync before the next box.
4. App uses a **Sentinel-aware client** (PHP: `phpredis` or Predis sentinel mode) so it
   auto-discovers the current primary — no app restart on failover.

### Operational footguns

1. **Docker + Sentinel networking:** set `replica-announce-ip` / `sentinel announce-ip`
   to the host `10.0.0.x` (not the container IP) and expose `6379` + `26379` on the
   internal network. #1 cause of broken failover. Host networking or explicit announce
   config fixes it.
2. **Persistence:** replicas can be memory-only (resync). Enable `appendonly yes` on the
   primary if the stream must survive a full-cluster power event. Not critical for pure
   ephemeral notifications.

### Use Streams, not Pub/Sub

For the transport, use **Valkey Streams** (`XADD` / `XREAD` from last-seen id) — maps 1:1
to the `getNewEvents(lastEventId)` model already in `WidgetEventStoreInterface`: monotonic
ids, persisted in the stream, survive subscriber reconnects. Classic Pub/Sub is
fire-and-forget and silently drops messages for momentarily-disconnected subscribers,
which would reintroduce a "missed message" bug.

### Migration path (when we come back to it)

1. Add Valkey + Sentinel services to each node's `docker-compose` (announce-IP config).
2. Implement `StreamWidgetEventStore implements WidgetEventStoreInterface` (XADD/XREAD).
3. Repoint the one DI alias in `services.yaml`
   (`WidgetEventStoreInterface → StreamWidgetEventStore`).
4. Optionally keep `DatabaseWidgetEventStore` as a fallback / for single-node dev.
5. `BWIDGET_EVENTS` can then be dropped (or retained for audit) via a follow-up migration.

The interface was designed for exactly this swap — it's a one-alias change in app code.
