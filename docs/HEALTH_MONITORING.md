> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 2: Administration, Security & Billing (2/9)**  
> **← Previous:** [2.1 Day-2 Operations & Governance](ADMIN.md) · **Next:** [2.3 Observability & Redacted Error Ring →](OBSERVABILITY.md)

# Health Monitoring (Load Balancers & Uptime Robot)

> **In this guide:** Which of Synaplan's three health endpoints to use for container orchestrators, load balancers, and external uptime monitors, and how to provision a dedicated low-privilege API key for `/api/health/probe`.

---

## Choosing the Right Health Endpoint

Synaplan exposes three health endpoints for three different consumers:

| Endpoint | Authentication | What It Exercises | Who Should Call It |
| -------- | -------------- | ----------------- | ------------------ |
| `GET /api/health/ready` | Public | Pings Redis only (no DB, no subprocess, no provider call). Returns HTTP `503` if Redis is down. | Load balancers, Kubernetes readiness probes, and the capacity watchdog. |
| `GET /api/health` | Public | Checks Redis, AI provider readiness, and local Whisper (cached 5 min on success / 1 min on failure). | Operator diagnostics and container health checks. |
| `GET /api/health/probe` | `X-API-Key: sk_…` | Exercises the full authentication stack: MariaDB lookup, monitor user existence, `BEMAILVERIFIED=1` gate, and token generation. Returns plain `STATUS:OK` or `STATUS:ERROR`. | External uptime monitors (Uptime Robot, Better Stack, Pingdom) every **5 minutes**. |

The rest of this guide covers setting up **`GET /api/health/probe`** for Uptime Robot. Because its response body contains only `STATUS:OK` or `STATUS:ERROR`, no internal details are ever leaked; detailed failure reasons are logged server-side.

---

## Setting Up `/api/health/probe`

### 1. Create the Dedicated Monitor User (One-Time SQL)

The probe authenticates via a dedicated, isolated user account (`NEW` level, email verified, `NULL` password so it can never log in via the browser UI). Run this once in the production database:

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

Verify the row was created:

```sql
SELECT BID, BMAIL, BEMAILVERIFIED FROM BUSER WHERE BPROVIDERID = 'health-monitor';
```

### 2. Mint an API Key for the Monitor User

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

On a multi-node Galera cluster, run this once; replication propagates the user and key across all nodes.

### 3. Smoke-Test from the Command Line

```bash
curl -i -H "X-API-Key: sk_your-key-here" https://api.synaplan.com/api/health/probe
```

Expect `HTTP/2 200` and `STATUS:OK`.

### 4. Configure Uptime Robot

| Setting | Value |
| ------- | ----- |
| **Monitor Type** | Keyword |
| **URL** | `https://api.synaplan.com/api/health/probe` |
| **Custom HTTP Header** | `X-API-Key: <key from step 2>` |
| **Keyword** | `STATUS:OK` |
| **Alert When** | Keyword does **NOT** exist |
| **Check Interval** | 5 minutes |
| **Alert Threshold** | 2 consecutive failures |

---

## Key Rotation & Teardown

### Rotating the Monitor API Key

1. Insert a new API key using the SQL in step 2 (or via the admin UI).
2. Update the `X-API-Key` header in Uptime Robot.
3. Revoke the old key:

```sql
UPDATE BAPIKEYS SET BSTATUS = 'revoked' WHERE BKEY = 'sk_old-key';
```

### Removing the Monitor User

```sql
DELETE FROM BAPIKEYS WHERE BOWNERID = (SELECT BID FROM BUSER WHERE BPROVIDERID = 'health-monitor');
DELETE FROM BUSER WHERE BPROVIDERID = 'health-monitor';
```

### Note on Research Runs

Research does not add a check to `/api/health/probe`. The **Operate → System status** page shows how many research runs are currently in progress when research is enabled, and stalled runs are recovered automatically by `app:research:reap` on the scheduler tick. See [Deep & Automatic Research](RESEARCH.md).

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 2: Administration, Security & Billing (2/9)**  
> **← Previous:** [2.1 Day-2 Operations & Governance](ADMIN.md) · **Next:** [2.3 Observability & Redacted Error Ring →](OBSERVABILITY.md)
