# Sprint 1 — Pair this computer

**Goal:** A signed-in user can mint a one-time pairing code and a future
desktop client can exchange it for a **scoped** API key bound to a device
row. Web UI lists and revokes devices.
**Depends on:** Sprint 0 (D0–D3). Checklist rows 3, 7, 8, 14, 18.
**Unlocks:** Sprint 2 (the extra client has something to call).
**Repos:** `synaplan/` only. The client is still a `curl` script in tests.
**Flag:** all new routes 404 when `DESKTOP_AGENT.ENABLED` is off.

---

## 0. Why this sprint exists

Hand-pasting a full-access API key into a daemon is how this product fails.
Pairing is the only supported way to get a desktop key. The key is shown
once, stored later in the OS keychain by the client (Sprint 2).

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Controller/ApiKeyController.php` | Key minting (`sk_` + 29 bytes) |
| `backend/src/Controller/McpServerConfigController.php` (or MCP servers Vue) | CRUD + OpenAPI style to copy |
| `frontend/src/components/config/APIKeysConfiguration.vue` | How keys are shown once |
| `frontend/src/composables/useNavItems.ts` | Add Channels child, not a new rail item |
| `frontend/src/components/config/McpServersConfiguration.vue` | Layout reference |
| `docs/MIGRATIONS.md` | Galera-safe `addSql` |
| Saved Tasks `guard()` 404-when-disabled | Copy that pattern |

---

## 2. Developer steps

### 2.1 Migration — `BDESKTOPDEVICES`

Galera-safe, own PR (D4):

```sql
CREATE TABLE IF NOT EXISTS BDESKTOPDEVICES (
  BID BIGINT NOT NULL AUTO_INCREMENT,
  BOWNERID BIGINT NOT NULL,
  BNAME VARCHAR(128) NOT NULL DEFAULT '',
  BAPIKEYID BIGINT NOT NULL,
  BSTATUS VARCHAR(16) NOT NULL DEFAULT 'active',
  BCAPABILITIES JSON NULL,
  BLASTSEEN BIGINT NOT NULL DEFAULT 0,
  BCREATED BIGINT NOT NULL,
  PRIMARY KEY (BID),
  KEY idx_desktop_owner (BOWNERID),
  KEY idx_desktop_apikey (BAPIKEYID)
);
```

No foreign key to `BAPIKEYS` required in v1 (delete key then mark device
`revoked`). No Schema API. Children-before-parent only if we later add jobs.

### 2.2 Pairing codes (Redis, not a table)

`POST /api/v1/desktop/pairing-codes` (session user, flag on):

- Generate 8 characters, Crockford base32 or digits+letters without `O0Il`.
- Store `desktop_pair:{code}` → `{userId, expiresAt}` in Redis, TTL 600 s.
- Rate-limit: 5 outstanding codes per user; 20 creates / hour.
- Response: `{ code, expiresAt }` — never log the code at info.

`POST /api/v1/desktop/pair` (**no session**; public-ish but rate-limited):

```json
{
  "code": "AB3K7Q2M",
  "deviceName": "Jan's laptop",
  "capabilities": ["skill.run"]
}
```

- Consume the Redis key (one-time).
- Mint `ApiKey` with scopes
  `["desktop:messages", "desktop:mcp", "desktop:files", "desktop:jobs"]`
  and name `Desktop — {deviceName}`.
- Insert `BDESKTOPDEVICES`.
- Return `{ deviceId, key, apiBaseUrl }` **once**.
- Wrong/expired code → 400, same message for both (no user enumeration).

### 2.3 Device CRUD

- `GET /api/v1/desktop/devices` — owner only; no key material; `keyPrefix`.
- `DELETE /api/v1/desktop/devices/{id}` — revoke key + `status=revoked`.
- `POST /api/v1/desktop/devices/{id}/heartbeat` — optional in this sprint;
  otherwise first check-in in Sprint 6 updates `BLASTSEEN`.

Full OpenAPI. Generate Zod schemas. 404 for another user’s id (not 403),
same as Saved Tasks.

### 2.4 Frontend — Channels → Desktop

- Route `/channels/desktop` (name `channels-desktop`).
- Nav: child of Channels via `useNavItems` **and** router. Hidden when
  flag off (`/api/v1/config/runtime` or capabilities endpoint — add a
  boolean `desktopAgentEnabled` on runtime config, default false).
- Page: short explanation (copy from [`11_ux_and_i18n.md`](./11_ux_and_i18n.md)),
  **Pair this computer** button → dialog shows code + expiry + “open
  Synaplan Desktop and enter this code”.
- Device table: name, last seen, status, **Revoke**.
- Four locales in the same PR as the Vue. Dark + V2 + 320px.
- Tokens only. `useDialog` for revoke.

No download button that pretends the extra repo already ships binaries
until Sprint 2 exists; a “the desktop app is a separate install” sentence
is enough.

### 2.5 Manual stand-in (until Sprint 2)

A `_devextras/testing/desktop/pair.sh` that:

1. Logs in as demo / uses a session cookie **or** calls pairing-codes via
   an existing test helper.
2. Exchanges the code.
3. Calls `GET /v1/models` with the new key (200).
4. Calls an admin route (403).

This is the Sprint 1 acceptance demo. It is not the product.

---

## 3. Tests

- Pairing: happy path, expired code, reused code, flag off → 404.
- Rate limit: sixth outstanding code fails.
- Device list is owner-scoped.
- Revoke: subsequent `/v1/models` with that key is 401.
- Restricted key cannot list *other* users’ devices.
- Frontend unit: page hidden when `desktopAgentEnabled` is false.
- i18n parity for the new namespace `desktop`.
- Unfiltered backend + frontend gates.

---

## 4. Exit criteria

1. Flag off: no nav item, pairing routes 404.
2. Flag on: user can create a code, exchange it, see the device, revoke it.
3. The minted key is restricted (Sprint 0 tests still pass with this key).
4. OpenAPI → Zod regenerated.
5. Invariants C1–C7 that this sprint can touch are named in the PR.
