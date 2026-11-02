# Audit and usage reporting — evidence for the auditor, costs for the controller

Row 37. Umbrella: [`20261102-enterprise-readiness/`](../20261102-enterprise-readiness/00_master_plan.md) (C5, C6).

Two people in a customer company ask for numbers Synaplan already has
but does not hand out:

- **The security team / auditor:** "Who signed in, who changed what, who exported data, and can it go into our SIEM (Splunk, Sentinel, Elastic, Graylog, Wazuh)?" ISO 27001, BSI C5 and NIS2 ask for logging that is complete, kept and protected against change.
- **The controller / department head:** "What does AI cost us per department this month, and can I have it as a spreadsheet?"

Both answers start from tables that exist: `BAUDITLOG` (one writer,
retention, admin list) and `BUSELOG` (tokens, cost, price snapshot,
latency, status per call). This track completes the events, adds export
and streaming, and reports usage by group. It adds no second log and no
second meter.

## 1. Findings (checked 2026-11-02 against `main`)

| # | Prio | Finding | Evidence |
| - | ---- | ------- | -------- |
| F1 | P1 | The audit log records sharing, groups, policies, federation, platform links, sign-up codes, approvals, impersonation and research settings. It does **not** record sign-in, failed sign-in, sign-out, password or mail changes, API key create/revoke, admin settings changes (BCONFIG), model and provider changes, user suspend/delete, data exports, widget publish/delete, or agent publish | action names passed to `Service/Iam/AuditLogWriter::record()` across `src/` (57 distinct; none of the listed) |
| F2 | P1 | Action names are free strings at each call site; no catalog, no severity, no category | `AuditLogWriter::record(int $actorId, string $action, …)` |
| F3 | P1 | No export and no streaming: `GET /api/v1/admin/audit` lists only, People → Audit shows a page at a time | `Controller/AdminAuditController.php:30`, `frontend/src/components/people/AuditTab.vue` |
| F4 | P2 | Rows can be changed or deleted by anyone with database access without trace — no tamper evidence | `Entity/AuditLogEntry.php` |
| F5 | P2 | The reaper deletes rows older than `IAM.AUDIT_RETENTION_DAYS` (default 365) even when no external copy exists | `Command/ReapAuditLogCommand.php:17-40` |
| F6 | P1 | Usage is reported per user and per account. There is no total per group, department or cost centre, and no export for an admin | `Service/Usage/AdminUsageService.php::users()`, `requests()`; `UsageStatsController::export` is per account |
| F7 | P2 | `BUSELOG` rows carry no group, so a person who moves department moves their history with them | `Entity/UseLog.php` |

## 2. What is fine — do not "fix" it

- One writer and one table. Every new event goes through `AuditLogWriter`.
- Retention as a BCONFIG value, and the reaper as a scheduled command.
- The request correlation id and the event ring (`docs/OBSERVABILITY.md`) are for debugging and stay separate from the audit log. An audit row may carry the correlation id in its subject.
- `BUSELOG` cost and price snapshot. Reports read them; they never recompute prices.
- Egress policy. Every sink call goes through `EgressHttpClient`, so an operator's allowlist decides where audit data may go.

## 3. Target model

### 3.1 An event catalog

`AuditAction` (enum, `App\Model\Audit\`). Each case carries its category
(`auth`, `account`, `admin`, `data`, `sharing`, `integration`, `ai`), its
severity (`info`, `notice`, `warning`) and the subject keys it may carry.
A secret value is never a subject key. An architecture test fails when
`record()` is called with a string that is not a catalog value.

New events (F1):

| Category | Events |
| -------- | ------ |
| auth | `auth.sign_in` (method: password / oidc / saml / link / api_key first use), `auth.sign_in_failed` (reason, counted per address and minute, at most one row per minute and address), `auth.sign_out`, `auth.mfa_enabled`, `auth.mfa_disabled`, `auth.mfa_reset` |
| account | `user.suspend`, `user.restore`, `user.delete_requested`, `user.erased`, `user.mail_changed`, `user.password_changed`, `api_key.created`, `api_key.revoked`, `identity.linked` (row 35) |
| admin | `config.changed` (key, old → new; values of secret keys replaced by `•••`), `model.changed` (model key, field), `provider.key_set` / `provider.key_removed` (never the key), `egress.mode_changed`, `module.toggled` |
| data | `export.chat`, `export.usage`, `export.audit`, `files.bulk_download`, `memory.deleted_all` |
| integration | `widget.published`, `widget.deleted`, `agent.published`, `scim.*` and `saml.*` (row 35), `audit_sink.*` (this row) |

### 3.2 Tamper evidence

Two new columns on `BAUDITLOG`: `BPREVHASH`, `BHASH`, with
`BHASH = sha256(BPREVHASH ‖ canonical row)`. Writes are serialized by a
named lock (Redis, with a database fallback), so the chain is linear
across the web nodes. `app:audit:verify` walks the chain and names the
first broken row. People → Audit shows "Chain intact up to {date}" (D2).
Migration with raw idempotent `addSql`; rows from before the migration
start the chain with a marker.

### 3.3 Export

People → Audit → **Export**: date range, category filter, format CSV or
JSON Lines. Up to 100,000 rows the export is generated in the request;
larger ranges become a background job with a download link in the inbox.
The export itself is audited (`export.audit`).

### 3.4 Streaming to a SIEM

`BAUDIT_SINKS`:

| Column | Meaning |
| ------ | ------- |
| `BKIND` | `https` (JSON batch, HMAC-SHA256 signature header), `splunk_hec`, `syslog_tls` (RFC 5424 over TCP + TLS, port 6514) |
| `BTARGET` | URL or host:port |
| `BSECRET` | token / signing secret, encrypted at rest like other credentials |
| `BFILTER` | category list |
| `BFORMAT` | `synaplan.v1` or `ocsf` (D3) |
| `BCURSOR` | last delivered `BAUDITLOG.BID` |
| `BSTATE` | `active` / `paused` / `failing` |
| `BLASTERROR`, `BLASTOK` | last delivery result |

- **Delivery is an outbox on the log itself.** A scheduled job (scheduler lane, every 10 s) reads up to 500 rows after `BCURSOR` per sink, sends one batch and advances the cursor only on success. This is at-least-once; the event id `{installId}:{BID}` lets the SIEM drop duplicates. Nothing is lost while a SIEM is down, because the rows are still in `BAUDITLOG`.
- **Backoff and honesty.** After a failure: retry with backoff (30 s → 30 min) and respect `Retry-After`. After 6 h of failures the sink is `failing`. Admin → People → Audit → Sinks then shows "Delivery to Splunk stopped on {date}: the server refused the token. Events are kept and will be sent when it works again.", and the admins get one mail.
- **Retention respects sinks.** The reaper never deletes a row that an active sink has not yet delivered (fixes F5), and says so in its output.
- **Test button.** "Send a test event" delivers one `audit_sink.test` event and shows the result in one sentence.
- **Metrics.** Sink lag (seconds behind) and failures are recorded with the existing Redis counters and appear on the capacity page of row 22 when it ships.

`synaplan.v1` is one JSON object per event:

```json
{
  "id": "inst_7f3:184223",
  "time": "2026-11-02T09:14:03Z",
  "action": "auth.sign_in",
  "category": "auth",
  "severity": "info",
  "actor": {"id": 42, "mail": "ada@example.org"},
  "resource": {"kind": "user", "id": "42"},
  "subject": {"method": "saml"},
  "ip": "203.0.113.4",
  "install": "synaplan.example.org",
  "hash": "…"
}
```

### 3.5 Usage by group

- **Attribution at write time (F7).** New nullable `BUSELOG.BGROUPID`: the person's *primary* group at the time of the call (D4: the first directory group, else the first manual group, else null). History stays where it happened. Migration with raw idempotent `addSql`, no backfill of old rows by default (D5).
- **Report.** Admin → Usage → **By group**: period, kind (chat, media, speech, embeddings, research), model. Each row shows the group, people active, requests, tokens and cost. It links to the group's people and, when row 23 ships, to its budget.
- **Optional cost centre.** A text field on the group (`BCOSTCENTER`). It is shown in the report and the CSV.
- **Export.** CSV per period, one row per group × model × kind, with cost in the install currency. The export is audited (`export.usage`).
- **API.** `GET /api/v1/admin/usage/groups` with OpenAPI annotations, so a customer's BI tool can pull it with an admin API key.

## 4. Decisions

- [ ] **D1 (AE1).** The event list of §3.1, and the failed-sign-in rate rule (one row per minute and address).
- [ ] **D2 (AE3).** Hash chain on `BAUDITLOG` (§3.2), yes or no. It costs one lock per write; audit writes are rare compared with chat traffic.
- [ ] **D3 (AE5).** Formats: `synaplan.v1` always, OCSF (Authentication 3002, Account Change 3001, API Activity 6003) as a second option in AE5 or later.
- [ ] **D4 (AE6).** The primary-group rule for attribution.
- [ ] **D5 (AE6).** No backfill of `BGROUPID` for old usage rows (the report starts at the migration date and says so). The alternative backfills using today's membership — that would be wrong for people who moved.
- [ ] **D6 (AE5).** Syslog over plain UDP or TCP is not offered; TLS only.

## 5. Journeys

| Id | Journey | Profile |
| -- | ------- | ------- |
| J-AE-1 | An auditor's admin opens People → Audit, filters "Sign-ins, last 30 days", exports CSV and opens it in a spreadsheet: one row per sign-in with method and address; the export itself appears as the newest row | Admin |
| J-AE-2 | The security team adds a Splunk HEC sink with URL and token, presses **Send a test event**, and sees "Splunk accepted the test event." Then they search Splunk for `sourcetype=synaplan:audit` and find their own sign-in within a minute | Operator |
| J-AE-3 | The Splunk token is revoked on the Splunk side. Six hours later the sink row says delivery stopped and why; admins got one mail. A new token is pasted; the backlog arrives, and the row returns to "Delivering, 0 s behind" | Operator |
| J-AE-4 | The controller opens Admin → Usage → By group, picks "October", sees "Sales — 41 people — 1,204 € — GPT-6.1 Sol 71 %" and downloads the CSV for the cost-centre booking | Admin |
| J-AE-5 | An admin runs `app:audit:verify` after a restore; the output says "Chain intact: 184,223 events from 2026-11-15 to 2027-01-04" | Operator |

## 6. Steps

| Step | Commit subject | Release class | Depends on | Gate |
| ---- | -------------- | ------------- | ---------- | ---- |
| AE1 | `refactor(iam): one catalog of audit events` — `AuditAction` enum with category, severity and allowed subject keys; every existing call site moved; architecture test; no behaviour change | backend-only | — | `make ci-local` (deptrac: enum in `App\Model\`) |
| AE2 | `feat(iam): record sign-ins, account changes, admin settings and exports in the audit log` — the §3.1 events with redaction of secrets; failed sign-in rate rule; People → Audit filters by category; five locales for the row labels (`AuditRowLabels`) | backend-only + ota-candidate (labels, filter) | D1, AE1 | `make ci-local && make test-e2e` |
| AE3 | `feat(iam): tamper-evident audit chain` — `BPREVHASH`/`BHASH` (migration), serialized writer, `app:audit:verify`, "Chain intact up to" line | backend-only + ota-candidate | D2, AE1 | `make ci-local` |
| AE4 | `feat(admin): export the audit log as CSV or JSON Lines` — range + category, inline up to 100,000 rows, background job with inbox link beyond, `export.audit` event; J-AE-1 | ota-candidate + backend-only | AE2 | `make ci-local && make test-e2e` |
| AE5 | `feat(iam): stream audit events to a SIEM` — `BAUDIT_SINKS` (migration, encrypted secret, eraser not needed — install data), HTTPS + HMAC, Splunk HEC, syslog over TLS, outbox cursor job on the scheduler, backoff, `failing` state + admin mail, test event, reaper respects undelivered rows, Redis counters; Sinks tab under People → Audit; `docs/AUDIT.md` (event catalog, `synaplan.v1` schema, Splunk / Sentinel / Elastic / Graylog setup, chain verification); J-AE-2, J-AE-3 | backend-only + ota-candidate | D3, D6, AE2 | `make ci-local && make test-e2e`; a local Splunk (`splunk/splunk` container, not committed) and `rsyslog` TLS listener walk logged in STATUS |
| AE6 | `feat(usage): attribute usage to a group and report cost per group` — `BUSELOG.BGROUPID` + `BCOSTCENTER` (migration), primary-group rule, `GET /api/v1/admin/usage/groups` (OpenAPI → `make -C frontend generate-schemas`), By group tab, CSV export; J-AE-4 | backend-only + ota-candidate | D4, D5 | `make ci-local && make test-e2e` |
| AE7 | `feat(synaplan): audit sinks and retention in the chart` in `synaplan-charts` — `audit.retentionDays`, an optional sink bootstrapped from values (kind, target, `secretRef`) for GitOps installs, NetworkPolicy egress note; README template | — (chart) | AE5 | `make all` |

AE2, AE4, AE5 and AE6 carry the five exit bullets of `docs/UX_CONTRACT.md`
§6 before their first `.vue`:

- **Journeys walked:** J-AE-1 to J-AE-4.
- **Ten-second findability:** the export and the sinks sit under People → Audit, where admins already read the log; the group report is a tab under Admin → Usage.
- **Consequence copy in five locales:** removing a sink ("Synaplan stops sending events to Splunk. Events stay in Synaplan for 365 days."); pausing a sink.
- **Empty, error and flag-off states:** no sinks yet → "Send audit events to your security tools." + **Add a destination**; the failing state in one sentence with the recovery; no group usage yet → "Usage by group starts on {date}, when groups were first recorded."
- **Dark, V2 and 320 px:** tables scroll inside their card.

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| Audit writes on every sign-in add load on Galera | One insert per sign-in is small next to chat traffic; failed sign-ins are rate-folded; measured in AE2 with `make perf-runtime` |
| The hash-chain lock becomes a bottleneck across web nodes | Redis lock held for one insert; fallback `GET_LOCK`; measured; D2 can be declined |
| Personal data in the SIEM (mail, address) | Documented in a new `docs/AUDIT.md` (AE5) and the TOMs (ER1); the subject never carries message content or file content |
| A sink is used to exfiltrate data | Only admins create sinks; creation is audited and mails all admins; egress policy applies; only audit rows are sent, never content |
| Group attribution is wrong for people in many groups | D4 rule is visible in the report header ("Usage is counted for each person's first directory group"); cost centre on the group lets controllers correct the mapping without code |

## 8. Non-goals

- A built-in SIEM, dashboards for security analytics, or alerting rules.
- Logging message or file content in the audit log.
- Invoicing per department (the report is the basis; billing stays Stripe / customer ERP).

## 9. Change log

| Date | Change |
| ---- | ------ |
| 2026-11-02 | Plan written from SIEM delivery practice (Splunk HEC, RFC 5424 over TLS, at-least-once with event ids), GitHub's audit-log streaming as a reference UX, and a read of the audit and usage code on `main`. No code changed. |
