# Health Monitoring (Uptime Robot)

One endpoint that answers: **does the auth stack work?**

Response body contains only `STATUS:OK` or `STATUS:ERROR` — no details leaked. Diagnostics are logged server-side.

Configure Uptime Robot with keyword `STATUS:OK`, alert when keyword does **NOT** exist. That single rule catches 5xx, network errors and degraded responses.

## Endpoint

| Endpoint | What it checks | Suggested interval |
|---|---|---|
| `GET /api/health/probe` | DB reachable (via API-Key lookup), monitor user exists, email verified, token generation works | every **5 min**, alert after 2 consecutive fails |

## Auth

Standard API-Key authentication via `X-API-Key` header.

No custom tokens, no additional ENV vars required.

## Monitor user

Dedicated, isolated account — no admin rights, no real usage, only exists for this health check. User level `NEW`, email verified, no UI access needed.

## Setup

### 1. Create the monitor user (one-time SQL)

In the production database:

```sql
INSERT INTO BUSER (
    BCREATED, BINTYPE, BMAIL, BPW, BPROVIDERID,
    BUSERLEVEL, BEMAILVERIFIED, BUSERDETAILS, BPAYMENTDETAILS
) VALUES (
    DATE_FORMAT(NOW(), '%Y%m%d%H%i%s'),
    'WEB',
    'health-monitor@synaplan.internal',
    NULL,
    'health-monitor',
    'NEW',
    1,
    '{}',
    '{}'
);
```

Note: No password needed — authentication is done via API-Key only.

Verify:

```sql
SELECT BID, BMAIL, BEMAILVERIFIED FROM BUSER WHERE BPROVIDERID = 'health-monitor';
```

### 2. Create an API-Key for the monitor user

```sql
INSERT INTO BAPIKEYS (BOWNERID, BKEY, BSTATUS, BLASTUSED, BSCOPES, BCREATED, BNAME)
VALUES (
    (SELECT BID FROM BUSER WHERE BPROVIDERID = 'health-monitor'),
    CONCAT('sk_', LOWER(HEX(RANDOM_BYTES(32)))),
    'active',
    0,
    '[]',
    UNIX_TIMESTAMP(),
    'Uptime Robot'
);
```

Retrieve the generated key:

```sql
SELECT BKEY FROM BAPIKEYS WHERE BNAME = 'Uptime Robot'
  AND BOWNERID = (SELECT BID FROM BUSER WHERE BPROVIDERID = 'health-monitor');
```

One-time per environment. Galera replication propagates across the cluster.

### 3. Configure Uptime Robot

- **Type:** Keyword
- **URL:** `https://api.synaplan.com/api/health/probe`
- **Custom Header:** `X-API-Key: <key from step 2>`
- **Keyword Type:** Keyword does NOT exist
- **Keyword:** `STATUS:OK`
- **Interval:** 5 min
- **Alert threshold:** 2 consecutive failures

## Smoke test

```bash
curl -i -H "X-API-Key: sk_your-key-here" https://api.synaplan.com/api/health/probe
```

## Key rotation

1. Create a new API-Key (step 2 above, or via the admin UI)
2. Update Uptime Robot with the new key
3. Revoke the old key: `UPDATE BAPIKEYS SET BSTATUS = 'revoked' WHERE BKEY = 'old-key';`

## Removing the monitor user

```sql
DELETE FROM BAPIKEYS WHERE BOWNERID = (SELECT BID FROM BUSER WHERE BPROVIDERID = 'health-monitor');
DELETE FROM BUSER WHERE BPROVIDERID = 'health-monitor';
```
