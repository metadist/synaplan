# Configuration pyramid — status and decision log

## Steps

| Step | State | Notes |
| ---- | ----- | ----- |
| Master plan written | done 2026-09-07 | Survey of `synaplan`, `synaplan-charts`, `synaplan-platform`, connector repos |
| Decision checklist (§0) ticked | open | 19 rows |
| Checked against the three primary targets | done 2026-09-07 | §4.6; added rows 16–19 (managed mode, offline preset, no artefact downloads, dev quick win split out) |
| Sprint files S0–S6 | not started | after the checklist |
| Technical plan review | not started | |

## Decisions

| Date | Decision | Where recorded |
| ---- | -------- | -------------- |
| — | — | — |

## Survey figures used in the plan (2026-09-07)

| Figure | Value | Source |
| ------ | ----- | ------ |
| Assigned variables in `backend/.env.example` | 134 (612 lines) | `rg -c '^[A-Z_0-9]+=' backend/.env.example` |
| Variables in `deploy/selfhost.env.example` | 44 (12 deployment-only) | same |
| Distinct env names read by `backend/config/**` | 106 | `%env(…)%` scan |
| `SystemConfigService` schema fields | 140 (~91 database, ~49 env) | `backend/src/Service/Admin/SystemConfigService.php` |
| Seeded `BCONFIG` keys / groups | ~149 / 26 | `backend/src/Seed/*ConfigSeeder.php` |
| Helm chart: value leaf paths / env names emitted | ~142 / ~30 | `charts/synaplan/values.yaml`, `templates/_helpers.tpl` |
| Platform `.env` keys / compose `environment:` keys / hard-coded | 86 / 51 / 14 | `synaplan-platform/docker-compose.yml` |
| Deployment targets | 7 | dev compose, `deploy/compose.yaml`, Elestio, AWS, Umbrel, platform, charts |
| Partner connection patterns | 6 | roaming key + `/addin/connect`; shared/provisioned `X-API-Key`; RFC 8693 exchange; in-process plugin; pairing code; runtime-config server URL |
