# Health Monitoring (Uptime Robot)

One endpoint that answers: **does the auth stack work?**

Response body contains only `STATUS:OK` or `STATUS:ERROR` — no details leaked. Diagnostics are logged server-side.

Configure Uptime Robot with keyword `STATUS:OK`, alert when keyword does **NOT** exist. That single rule catches 5xx, network errors and degraded responses.

## Endpoint

| Endpoint | What it checks | Suggested interval |
|---|---|---|
| `GET /api/health/login` | DB reachable, monitor user exists, password hash valid, email verified, token generation works | every **5 min**, alert after 2 consecutive fails |

## Auth

Header (preferred): `X-Health-Monitor-Token: <TOKEN>`
Query param (fallback): `?monitor=<TOKEN>`

To rotate: change the ENV var, restart, update Uptime Robot.

## Monitor user

Dedicated, isolated account — no admin rights, no real usage, only exists for this health check. User level `NEW`, no UI access needed.

## Setup

### 1. Generate the token

```bash
openssl rand -hex 32
```

### 2. Pick a strong password for the monitor user

```bash
openssl rand -base64 32
```

### 3. Set ENV vars in production

```bash
HEALTH_MONITOR_TOKEN=<token from step 1>
HEALTH_MONITOR_USER_EMAIL=health-monitor@synaplan.internal
HEALTH_MONITOR_USER_PASSWORD=<password from step 2>
```

### 4. Create the monitor user (one-time SQL)

Generate the bcrypt hash:

```bash
docker compose exec backend php -r 'echo password_hash("<password from step 2>", PASSWORD_BCRYPT), "\n";'
```

Then in the production database:

```sql
INSERT INTO BUSER (
    BCREATED, BINTYPE, BMAIL, BPW, BPROVIDERID,
    BUSERLEVEL, BEMAILVERIFIED, BUSERDETAILS, BPAYMENTDETAILS
) VALUES (
    DATE_FORMAT(NOW(), '%Y%m%d%H%i%s'),
    'WEB',
    'health-monitor@synaplan.internal',
    '<bcrypt hash from above>',
    'health-monitor',
    'NEW',
    1,
    '{}',
    '{}'
);
```

Verify:

```sql
SELECT BID, BMAIL, BEMAILVERIFIED FROM BUSER WHERE BPROVIDERID = 'health-monitor';
```

One-time per environment. Galera replication propagates across the cluster.

### 5. Configure Uptime Robot

- **Type:** Keyword
- **URL:** `https://api.synaplan.com/api/health/login`
- **Custom Header:** `X-Health-Monitor-Token: <token from step 1>`
- **Keyword Type:** Keyword does NOT exist
- **Keyword:** `STATUS:OK`
- **Interval:** 5 min
- **Alert threshold:** 2 consecutive failures

## Smoke test

```bash
curl -i -H "X-Health-Monitor-Token: <token>" https://api.synaplan.com/api/health/login
```

## Removing the monitor user

```sql
DELETE FROM BUSER WHERE BPROVIDERID = 'health-monitor';
```
