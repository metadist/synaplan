# Sprint A2 — Pair this computer

**Phase A (`synaplan/`), sprint 2 of 3.** Steps `DS5`–`DS10`.

**Goal:** A signed-in user can mint a one-time pairing code and a future
desktop client can exchange it for a **scoped** API key bound to a device
row. Web UI lists and revokes devices.
**Depends on:** Sprint A1 (`DS1`–`DS4`). Checklist rows 3, 7, 8, 14, 18.
**Unlocks:** Sprint A3 (a job needs a device to belong to) and, later,
Sprint B1 (the client has something to call).
**Repos:** `synaplan/` only. **The “client” in this sprint and the next one is
a shell script** (`DS10`, extended in Sprint A3) — the real client does not
exist until Phase B.
**Flag:** all new routes 404 when `DESKTOP_AGENT.ENABLED` is off.

---

## 0. Why this sprint exists

Hand-pasting a full-access API key into a daemon is how this product fails.
Pairing is the only supported way to get a desktop key. The key is shown
once, stored later in the OS keychain by the client (Sprint B1).

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

Galera-safe, own PR (`DS5`):

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
`revoked`). No Schema API. `BDESKTOPJOBS` arrives one sprint later
(`DS11`) and references `BDEVICEID` — when a migration deletes devices,
delete job rows first (no `ON DELETE CASCADE`).

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
  otherwise the first check-in in Sprint A3 updates `BLASTSEEN`.

Full OpenAPI. Generate Zod schemas. 404 for another user’s id (not 403),
same as Saved Tasks.

### 2.4 Frontend — Channels → Desktop

- Route `/channels/desktop` (name `channels-desktop`).
- Nav: child of Channels via `useNavItems` **and** router. Hidden when
  flag off (`/api/v1/config/runtime` or capabilities endpoint — add a
  boolean `desktopAgentEnabled` on runtime config, default false).
- Page: short explanation (copy from [`12_ux_and_i18n.md`](./12_ux_and_i18n.md)),
  **Pair this computer** button → dialog shows code + expiry + “open
  Synaplan Desktop and enter this code”.
- Device table: name, last seen, status, **Revoke**.
- Five locales in the same PR as the Vue. Dark + V2 + 320px.
- Tokens only. `useDialog` for revoke.

**No download button.** In the server-first order the client does not exist
yet, so the page must not imply a binary is available: one sentence that
Synaplan Desktop is a separate install, plus (until Phase B ships) the
“not available yet” variant in [`12_ux_and_i18n.md`](./12_ux_and_i18n.md) §3.1.
Do not link to a release page that 404s.

### 2.5 Harness, not a client (`DS10`)

The whole of Phase A is verified by a script, because there is no client to
verify with. `_devextras/testing/desktop/pair.sh`:

1. Logs in as demo / uses a session cookie **or** calls pairing-codes via
   an existing test helper.
2. Exchanges the code.
3. Calls `GET /v1/models` with the new key (200).
4. Calls an admin route (403).

This is the Sprint A2 acceptance demo. Sprint A3 extends the same folder
into `fake-device.sh` (check-in + report). It is a test tool, not a shipped
reference daemon (master plan §12).

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
3. The minted key is restricted (Sprint A1 tests still pass with this key).
4. OpenAPI → Zod regenerated.
5. Invariants C1–C7 that this sprint can touch are named in the PR.
