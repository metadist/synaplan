# Realtime / WebSockets

Synaplan ships a generic realtime layer built on **Centrifugo + Redis**.
Use it whenever you need to push something from the backend to the
browser without making the browser poll. Examples currently in tree:

* live chat takeover (`widget:session.*`)
* operator notifications (`widget:operators.*`)

This document is the playbook for adding a new realtime feature without
re-inventing infrastructure. For ops / production tuning see
`_devextras/SYSADMIN-help.md`.

## Mental model

```
                  Backend                                 Frontend
 ┌────────────────────────────────────┐    ┌────────────────────────────────────┐
 │ feature service                    │    │ Vue component                      │
 │   uses RealtimePublisherInterface  │    │   uses useRealtimeChannel()        │
 │   builds a typed Channel value obj │    │   parses with a Zod schema         │
 │   publishes RealtimeEvent payload  │    │                                    │
 │                                    │    │                                    │
 │   ChannelAuthorizerInterface       │◄───┤ tokenApi → /realtime/subscribe     │
 │   gates per-subscriber access      │    │                                    │
 └────────────┬───────────────────────┘    └────────────┬───────────────────────┘
              │ HTTP publish (PHP-side)                 │ WSS /connection/*
              └────────────────► Centrifugo ◄───────────┘
```

The pieces you'll touch when adding a feature: a `Channel` value object,
a `ChannelAuthorizer`, and a Vue component. Everything else
(connection, JWT minting, reconnection, presence) is already wired.

---

## Adding a new channel — step by step

### 1. Define the channel

Pick a namespace (`widget`, `user`, `system`, `admin`, …). Add a value
object under `backend/src/Realtime/Channel/` implementing
`ChannelInterface`:

```php
final readonly class TeamPresenceChannel implements ChannelInterface
{
    public const NAMESPACE = 'team';

    public function __construct(public int $teamId) {}

    public function name(): string
    {
        return sprintf('%s:presence.%d', self::NAMESPACE, $this->teamId);
    }

    public function namespace(): string { return self::NAMESPACE; }
}
```

Conventions:

* `:` separates namespace from identifier (Centrifugo expects this).
* Use `.` inside the identifier so parsing stays trivial.
* Channels are **immutable, equatable** value objects — never strings.

### 2. Teach the parser

`ChannelParser::parse()` is the single place that turns browser-supplied
strings back into typed channels. Add a `case` for your namespace, validate
the identifier strictly, and throw `InvalidChannelException` for anything
suspicious. The parser is called for every subscribe token request, so it
is the **trust boundary** — be generous with the validation.

### 3. Authorise subscriptions

Implement `ChannelAuthorizerInterface` under
`backend/src/Realtime/Authorizer/`. Keep it tight — fail closed:

```php
final readonly class TeamPresenceAuthorizer implements ChannelAuthorizerInterface
{
    public function __construct(private TeamMembershipRepository $memberships) {}

    public function supports(ChannelInterface $channel): bool
    {
        return $channel instanceof TeamPresenceChannel;
    }

    public function authorize(ChannelInterface $channel, SubscriberContext $subscriber): void
    {
        if (!$channel instanceof TeamPresenceChannel) {
            throw new UnauthorizedSubscriptionException('wrong channel');
        }
        if (!$subscriber->isAuthenticatedUser()) {
            throw new UnauthorizedSubscriptionException('login required');
        }
        if (!$this->memberships->isMember($channel->teamId, (int) $subscriber->user?->getId())) {
            throw new UnauthorizedSubscriptionException('not a member');
        }
    }
}
```

The `_instanceof` block in `services.yaml` auto-tags every authorizer
with `app.realtime.authorizer`, so you do **not** need to register it
manually — the `ChannelAuthorizerLocator` discovers it automatically.

### 4. Publish events

Inject `App\Realtime\Publisher\RealtimePublisherInterface` and call
`publish()`:

```php
final readonly class TeamPresenceService
{
    public function __construct(private RealtimePublisherInterface $publisher) {}

    public function announceJoin(int $teamId, int $userId): void
    {
        $this->publisher->publish(
            new TeamPresenceChannel($teamId),
            'member.joined',
            ['userId' => $userId, 'at' => time()],
        );
    }
}
```

The publisher serializes a canonical envelope:

```json
{ "type": "member.joined", "ts": 1700000000123, "data": { ... } }
```

The `CentrifugoPublisher` swallows transport errors (logged as
warnings) — your feature code does **not** need a try/catch.

### 5. Subscribe from the frontend

Use the composable in any component:

```vue
<script setup lang="ts">
import { z } from 'zod'
import { useRealtimeChannel } from '@/composables/useRealtimeChannel'

const PayloadSchema = z.object({ userId: z.number(), at: z.number() })
type Payload = z.infer<typeof PayloadSchema>

useRealtimeChannel<Payload>(`team:presence.${teamId}`, {
  onPublication: (event) => {
    const parsed = PayloadSchema.safeParse(event.data)
    if (!parsed.success) return
    // ... update local state
  },
})
</script>
```

Lifecycle is automatic:

* subscribes on mount
* unsubscribes on unmount
* re-subscribes when the channel ref changes
* reconnect / token refresh handled by the underlying `RealtimeClient`

For the embedded widget (which doesn't load Pinia), use `RealtimeClient`
directly — see `frontend/src/services/realtime/widgetSessionRealtime.ts`
for an example.

### 6. Connection status UX

Drop `<ConnectionStatusBadge />` into the relevant view header so users
see when the realtime layer is degraded (reconnecting / error). The
badge reads from the `realtime` Pinia store and is locale-aware via
`realtime.*` keys in `frontend/src/i18n/`.

### 7. Tests

* **PHPUnit** — write unit tests for your channel parser case, authorizer,
  and publisher integration. The publisher uses Symfony's
  `MockHttpClient`; the authorizer locator can be tested with anonymous
  classes (see `tests/Unit/Realtime/`).
* **Vitest** — mock `centrifuge` and `tokenApi`, exercise your component
  the same way `tests/unit/composables/useRealtimeChannel.spec.ts` does.

In test mode (`when@test`) the `RealtimePublisherInterface` is aliased
to `NullPublisher`, so feature code that publishes events never needs a
running Centrifugo to be testable.

---

## Configuration knobs

| Env variable | Purpose |
| --- | --- |
| `REALTIME_ENABLED` | Feature-flag for the entire frontend; `false` makes browsers stop opening WS connections. |
| `REALTIME_API_URL` | Internal HTTP publish URL (server → Centrifugo). |
| `REALTIME_API_KEY` | Shared secret protecting the publish endpoint. |
| `REALTIME_TOKEN_SECRET` | HMAC secret used by the backend to sign connection / subscription JWTs (must match Centrifugo's `token_hmac_secret_key`). |
| `REALTIME_PUBLIC_WS_URL` | Public WebSocket URL the browser dials (empty = same-origin via `/connection/websocket`). |
| `REALTIME_ALLOWED_ORIGINS` | Comma/space-separated origins Centrifugo accepts WS upgrades from. `*` in dev only; set explicit `https://…` origins in production. |
| `REALTIME_REDIS_ADDRESS` | Redis address for the Centrifugo engine (maps to `CENTRIFUGO_ENGINE_REDIS_ADDRESS`). Defaults to the local Redis on logical DB `/3`. |
| `REALTIME_ADMIN_PASSWORD` / `REALTIME_ADMIN_SECRET` | Credentials for the Centrifugo admin UI. The admin UI is **not** proxied publicly — reach it via the internal network only (SSH tunnel / localhost-bound port mapping). |

`REDIS_DSN` is required for cross-node Centrifugo fan-out and Symfony
infrastructure (cache, lock, rate-limiter). Treat Redis as mandatory
shared infrastructure.

### Composer dependencies introduced by this layer

| Package | Why |
| --- | --- |
| `firebase/php-jwt` | Signs the short-lived HS256 connection/subscription JWTs (`RealtimeTokenService`). Reference implementation Centrifugo documents against; we only use `JWT::encode`. |
| `predis/predis` | Pure-PHP Redis client backing cache, sessions, locks and `RedisService` — no PECL extension needed for web requests. |
| `symfony/redis-messenger` | Redis Streams transport for Messenger queues (requires `ext-redis` in the worker image — see `docs/DEVELOPMENT.md`). |

## Production deployment (multi-node)

The realtime layer is built for a horizontally scaled cluster (e.g. 3 web
nodes behind a load balancer, fronted by Cloudflare). The design goal: the
browser always connects **same-origin** (`/connection/websocket`), and any
node can serve any connection because all Centrifugo instances share state
through Redis.

### Topology: one Centrifugo per web node

Run a Centrifugo sidecar next to the backend on **every** node. Caddy
reverse-proxies `/connection/*` to the node-local Centrifugo (see
`_docker/backend/Caddyfile`). Because every Centrifugo points at the same
Redis engine, a publish issued by the PHP process on node A reaches a
browser whose WebSocket happens to live on node C.

```
Cloudflare → Load Balancer ──┬─ Node 1: Caddy → Centrifugo ─┐
                             ├─ Node 2: Caddy → Centrifugo ─┼─ shared Redis (engine, DB /3)
                             └─ Node 3: Caddy → Centrifugo ─┘
```

### Shared Redis

Point both PHP and Centrifugo at the same managed/shared Redis:

- `REDIS_DSN=redis://redis-prod.internal:6379` — cache, lock, rate-limiter,
  messenger (logical DB `/0`).
- `REALTIME_REDIS_ADDRESS=redis://redis-prod.internal:6379/3` — Centrifugo
  engine. Keep the dedicated `/3` suffix so engine keys never collide with
  Symfony's. For **Redis Cluster** drop the DB suffix (cluster has no
  numbered DBs) and rely on Centrifugo's key prefix instead.

### Secrets — identical on all nodes (never the `changeme_*` defaults)

`REALTIME_TOKEN_SECRET`, `REALTIME_API_KEY`, `REALTIME_ADMIN_PASSWORD` and
`REALTIME_ADMIN_SECRET` must be set to strong random values
(`openssl rand -hex 32`) and be **the same on every node**. The token
secret is security-critical: PHP signs the browser's short-lived connect
JWT with it, and every Centrifugo instance verifies it — a mismatch
rejects all connections.

Also set `REALTIME_ALLOWED_ORIGINS` to your real origin(s); never ship `*`
to production.

### Load balancer & Cloudflare checklist

- **Forward the WebSocket upgrade** — pass `Connection: Upgrade` /
  `Upgrade: websocket` through (explicit config on nginx/HAProxy; native on
  ALB/Cloudflare).
- **Raise idle timeouts** — WS connections are long-lived. Centrifugo pings
  every 25s (`client.ping_interval`), so keep the LB idle timeout well above
  that (≥ 60–120s recommended).
- **No sticky sessions required** — Redis shares history + presence, so any
  node can take over on reconnect.
- **Admin UI / HTTP API are internal-only** — Caddy proxies **only**
  `/connection/*` (the client WebSocket endpoint). The Centrifugo admin UI,
  HTTP server API and Prometheus endpoints are never exposed through the
  public proxy; the PHP backend publishes container-to-container via
  `REALTIME_API_URL`. To use the admin UI, tunnel to the node (e.g.
  `ssh -L 8401:localhost:8401 node1` with a localhost-bound port mapping
  for the Centrifugo container) — do not re-add a public route.

## Channel naming conventions

| Namespace | Use for | Auth model |
| --- | --- | --- |
| `widget:session.*` | One specific widget chat session. | Visitor proves possession of `(widgetId, sessionId)` OR operator owns the widget. |
| `widget:operators.*` | Operator notifications for a widget. | Authenticated user must own the widget. |
| `widgettyping:*` | Ephemeral typing previews for one session. | Same as `widget:session.*` (shared `WidgetSessionAccessGuard`). Browser-published. |
| `user:{id}` | Per-user notifications. | Authenticated user matches `id`. |
| `system:{topic}` | Public broadcasts (e.g. maintenance). | Open. |

When in doubt, **start with the most restrictive authorizer** and relax
later. Realtime channels are easy to add; rolling back a leak is hard.

## HTTP vs client-publish: when to use which

By default we **always** publish from the backend over the server API.
That keeps Centrifugo as a dumb fan-out layer and lets the PHP side
validate, persist, and rate-limit every message before it goes on the
wire (`WidgetRealtimeBroadcaster` is the canonical example).

There is exactly one feature today that bypasses this rule: typing
indicators. The `widgettyping:*` namespace is configured with
`allow_publish_for_subscriber: true`, which lets the browser fire frames
straight into Centrifugo without a PHP round-trip per keystroke. Use
that pattern only when **all** of these are true:

- The payload is **purely ephemeral** — no DB write would ever happen
  even on the HTTP path.
- Frequency is high enough that a per-event HTTP request is cost-prohibitive.
- The receiver can tolerate (and is coded to tolerate) malformed or
  hostile payloads — there is **no backend validation proxy** between
  the browser and the channel subscribers.

Security pre-conditions, all enforced today on `widgettyping`:

1. **Dedicated namespace.** Never enable client-publish on a namespace
   that also carries durable backend events (a visitor could otherwise
   impersonate a backend service or operator message).
2. **`allow_publish_for_subscriber: true` + `allow_subscribe_for_*: false`.**
   Subscribing requires our subscription JWT (so the
   `ChannelAuthorizerInterface` runs); publishing requires being a
   subscriber. The subscription token is therefore the single trust
   boundary for both subscribe AND publish.
3. **No history, no recovery.** `history_size: 0`, `force_recovery: false`.
   Replay would let an attacker who briefly subscribed read in-flight
   typing fragments forever.
4. **Receiver validates with Zod.** The publisher never sees the
   backend, so the consumer MUST treat every frame as untrusted input.
   See `frontend/src/services/realtime/widgetTypingChannel.ts` for the
   reference shape (`from`, `text`, `ts`, `cid`).
5. **Sender identity is a label, not a trust signal.** The `from` field
   is published by the client and can be forged. Use it only for UI
   hints (the worst-case is "operator typing…" being shown when no
   operator is present) — never for authorisation decisions.
6. **Echo prevention via per-subscription cid.** Centrifugo delivers
   publications back to the publisher; we drop those by tagging each
   frame with a random per-subscription id and filtering matches.

If you find yourself wanting any of the following, **don't** add a new
client-publish channel — keep the HTTP publish path and live with the
extra latency:

- Validation (length checks beyond a hard cap, profanity filter, etc.)
- Persistence
- Rate limiting that depends on user/widget context
- Cross-channel side-effects (e.g. updating a session row)
- Anything where "an attacker subscribed and started spamming" would
  cause user-visible damage rather than just UI noise.

## Anti-patterns

* ❌ Don't push raw user input through `publish()` without a Zod-typed
  payload contract. The frontend must be able to ignore unknown shapes
  safely.
* ❌ Don't use `system:*` for anything that should respect privacy — it's
  a public channel by design.
* ❌ Don't open a Centrifuge instance directly from a Vue component.
  Always go through the store / composable so subscriptions share the
  single connection.
* ❌ Don't add a polling fallback when WS fails. The connection layer
  reconnects with backoff; if it's truly down, the user sees the
  `ConnectionStatusBadge` in error state and your feature simply pauses
  until they refresh.
