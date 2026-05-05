# Redis für Synaplan – Umsetzung und Rest-Backlog

## Verantwortung

- **Widget-Echtzeit (`cache.widget_events`, SSE)** wird von einem anderen Mitarbeiter auf Redis umgestellt oder erweitert (inkl. ggf. Pub/Sub).
- Dieses Dokument beschreibt **alles andere**: gemeinsamer Cache für `cache.app`/Standard-Pools, Cluster-Locks, dokumentierte nächsten Schritte.

## Umgesetzt im Repo

| Thema | Details |
|--------|---------|
| **Docker Compose** | Service `redis` (`redis:7-alpine`), Volume `redis_data`, Backend wartet auf `redis` healthy, `REDIS_DSN` + `LOCK_DSN` Standard `redis://redis:6379` ([docker-compose.yml](../../docker-compose.yml), [docker-compose-minimal.yml](../../docker-compose-minimal.yml)) |
| **Symfony Cache** | `cache.app` und Pools `provider_status`, `model_config`, `user_config` → Redis (`predis`); **`cache.widget_events` bleibt Filesystem** ([backend/config/packages/cache.yaml](../../backend/config/packages/cache.yaml)) |
| **`APP_ENV=test`** | Alle Pools inkl. `widget_events` → `cache.adapter.array` (kein laufender Redis nötig) |
| **Abhängigkeit** | `predis/predis` für Cache/Lock/Session ohne `ext-redis`; zusätzlich `symfony/redis-messenger` (**phpredis** für Consumer) ([backend/composer.json](../../backend/composer.json)) |
| **Env-Vorlagen** | `REDIS_DSN`, erweiterte Lock-Hinweise ([backend/.env.example](../../backend/.env.example)); Placeholder `REDIS_DSN` in [backend/.env.test](../../backend/.env.test)) |
| **Operative Sichtbarkeit** | `GET /api/health` liefert `redis` mit PING (in `test` übersprungen); bei Ausfall **503** ([HealthController.php](../../backend/src/Controller/HealthController.php)) |
| **Model-Konfig-Cache** | [ModelConfigService.php](../../backend/src/Service/ModelConfigService.php) nutzt den Pool `cache.model_config` (Redis-TTL 3600 s; pro Eintrag weiterhin `expiresAfter(300)` im Code) |
| **PHP-Sessions** | `RedisSessionHandler` + Predis auf `REDIS_DSN`, Präfix `synaplan_sess_`, `gc_maxlifetime` 7 Tage ([framework.yaml](../../backend/config/packages/framework.yaml), [services.yaml](../../backend/config/services.yaml)); `APP_ENV=test` bleibt bei mock file |
| **Redis-Embedding-Cache** | `AiFacade::embed()` nutzt `cache.app` für Primary-Erfolgsfälle (`embed.v1.*`, TTL 7 Tage); Fallback-Pfad ohne Shared-Cache ([AiFacade.php](../../backend/src/AI/Service/AiFacade.php)) |
| **Messenger (Redis Streams)** | Vier Transports auf `'{REDIS_DSN}/{stream}'`: `async_ai_high`, `async_extract`, `async_index`, `failed` ([messenger.yaml](../../backend/config/packages/messenger.yaml)); `symfony/redis-messenger` (**phpredis**/`ext-redis`); Produktions-Image mit `pecl install redis` ([Dockerfile](../../_docker/backend/Dockerfile)); PHPUnit `APP_ENV=test` → `in-memory://` |

## Backlog nach Priorität und Aufwand

Sortierung: **Nutzen × Multi-Instance-Risiko** zuerst, dann Aufwand (`S` klein / `M` mittel / `L` groß).

| Prio | Thema | Nutzen | Aufwand | Owner / Status |
|------|--------|--------|---------|----------------|
| 1 | `cache.app` + Lock auf Redis | Idempotenz (Stripe/WhatsApp), CircuitBreaker, JWKS, Health-Caches, Cron-Locks clusterweit | **Done** | Dieses Team |
| 2 | **Widget `cache.widget_events` → Redis** | SSE konsistent über mehrere Backend-Hosts | **M**–**L** (ggf. Pub/Sub) | Anderer MA |
| 3 | **PHP-Sessions in Redis** | Load Balancing ohne Sticky Sessions | **Done** | Dieses Team |
| 4 | **Messenger `doctrine://` → `redis://`** | Weniger Messenger-Druck auf Galera/MySQL bei sehr hohem Queue-Volumen | **Done** (Streams + phpredis im Docker-Backend; Monitoring bei Bedarf) | Dieses Team |
| 5 | **Embedding über Requests (Primary-Pfad)** | Wiederholte Texte/Modelle ohne erneuten Provider-Call (7 Tage TTL, Key `embed.v1.*`); **Fallback bleibt absichtlich ohne Shared-Cache**, damit keine Fremdvektoren unter dem Primary-Key landen | **Done** (einzelne `embed()`, nicht `embedBatch`) | Dieses Team |

**Deployments:** Bereits in `messenger_messages` (MySQL) liegende Jobs werden **nicht** automatisch nach Redis übernommen — vor Produktions-Cutover Queue konsumieren/leeren oder Wartungsfenster einplanen.

**Hinweis `embedBatch()`:** weiterhin ohne Shared-Redis-Cache; bei Bedarf später pro-Chunks oder semantisch ähnlich wie `embed()` aufbauen.

**Nicht empfohlen:** Symfony-/Router-Compile-Caches in Redis teilen (`var/cache` bleibt pro Node).

## Betroffene Codepfade (ohne Widget)

Nutzen automatisch Redis über `cache.app`: u. a. [StripeWebhookController.php](../../backend/src/Controller/StripeWebhookController.php), [WhatsAppService.php](../../backend/src/Service/WhatsAppService.php) (Dedupe-Marker zuzüglich Lock), [CircuitBreaker.php](../../backend/src/Service/CircuitBreaker.php), [JwtValidator.php](../../backend/src/Service/JwtValidator.php), [QdrantClientDirect.php](../../backend/src/Service/VectorSearch/QdrantClientDirect.php).

Lock über `LOCK_DSN`: [WhatsAppService.php](../../backend/src/Service/WhatsAppService.php), [CrawlWidgetUrlsCommand.php](../../backend/src/Command/CrawlWidgetUrlsCommand.php), [ProcessMailHandlersCommand.php](../../backend/src/Command/ProcessMailHandlersCommand.php).

Messenger: gleiche Redis-Instanz wie `REDIS_DSN`; Worker z. B. `php bin/console messenger:consume async_ai_high async_extract async_index …` (**phpredis** erforderlich).

## Lokaler Betrieb ohne Docker

- Redis starten (`redis-server` oder kleiner Docker-Container auf `6379`).
- `REDIS_DSN=redis://127.0.0.1:6379`; optional `LOCK_DSN` gleicher DSN oder `flock` nur wenn keine verteilten Worker. PHP-Sessions landen ebenfalls in Redis unter Präfix `synaplan_sess_`.
- Messenger-Consumer ohne Docker: **`ext-redis`** installieren (`pecl install redis`), nicht nur Predis über Composer.

## Risiken (kurz)

- Redis-Ausfall: Cache, Locks, **Messenger-Warteschlangen**, **und eingeloggte Web-Sessions** sind betroffen, bis Redis wieder erreichbar ist (kein Fallback in dieser Ausbaustufe).
- Prod: eigene HA-Strategie (Replikation/Sentinel/managed Redis) abstimmen.

## Akzeptanz (für diese Iteration)

- Compose: Backend start nur wenn Redis healthy und `redis-cli ping` OK.
- `GET /api/health` → Feld `redis`; bei lebendigem Redis `available: true`; sonst **503** (außer PHPUnit, dort `skipped`).
- Nach OpenAPI-Änderung: `make -C frontend generate-schemas` mit laufendem Stack (Backend muss `http://localhost:8000` oder `http://backend/api/doc.json` liefern können).
- Widget-Verhalten unverändert bis der delegierte Teil umgesetzt ist (Pool weiterhin filesystem im Nicht-`test`-Betrieb).

---

*Siehe konkrete Konfiguration in den verlinkten Dateien; keine doppelte Code-Doku hier.*
