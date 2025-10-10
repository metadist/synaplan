# Synaplan Symfony 7 Migration – Risiken & Gegenmaßnahmen

## Übersicht

Diese Dokumentation identifiziert **Risiken** der Migration und definiert konkrete **Gegenmaßnahmen** zur Risikominimierung.

**Risiko-Klassifizierung**:
- 🔴 **Hoch**: Blockiert Projekt, hohe Wahrscheinlichkeit
- 🟡 **Mittel**: Verzögerung möglich, mittlere Wahrscheinlichkeit
- 🟢 **Niedrig**: Geringer Impact, niedrige Wahrscheinlichkeit

---

## 1. Technische Risiken

### 1.1 Galera Write-Conflicts 🔴

**Risiko**: MariaDB Galera Cluster → Write-Conflicts bei gleichzeitigen Writes auf mehrere Nodes.

**Symptome**:
- Transactions schlagen fehl (Deadlock)
- Daten inkonsistent
- Performance-Degradation

**Auswirkung**:
- User sehen Fehler bei Message-Submit
- Duplicate Messages
- Datenverlust

**Wahrscheinlichkeit**: **Hoch** (Multi-Master Setup)

**Gegenmaßnahmen**:

#### Single-Writer Pattern
```yaml
# doctrine.yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:  # WRITE-ONLY
                url: '%env(DATABASE_WRITE_URL)%'  # Primary Node
                server_version: '11.7'
                
            read:     # READ-ONLY
                url: '%env(DATABASE_READ_URL)%'   # Replica Nodes (Round-Robin)
                server_version: '11.7'
```

**Config**:
```env
DATABASE_WRITE_URL=mysql://user:pass@galera-node1:3306/synaplan
DATABASE_READ_URL=mysql://user:pass@galera-loadbalancer:3306/synaplan
```

#### Idempotente Commands
```php
// Message-Commands mit Unique-Constraint
class ProcessMessageCommand
{
    private string $idempotencyKey;  // UUID
    
    // Handler prüft vor Processing:
    if ($this->repository->existsByIdempotencyKey($command->getIdempotencyKey())) {
        return; // Already processed
    }
}
```

#### Outbox-Pattern
```php
// Events asynchron via Outbox-Tabelle
#[ORM\Entity]
class OutboxEvent
{
    private string $eventType;
    private array $payload;
    private bool $processed = false;
    
    // Separate Worker konsumiert Outbox → externe Systeme
}
```

**Monitoring**:
- Galera-Status: `SHOW STATUS LIKE 'wsrep_%'`
- Write-Conflicts: `wsrep_local_cert_failures`
- Alert bei > 10 Conflicts/Minute

**Rollback-Plan**:
- Single-Node Fallback (Performance-Hit, aber stabil)

**Status**: ✅ Geplant

---

### 1.2 Provider-Limits & Quotas 🟡

**Risiko**: AI-Provider (Anthropic, OpenAI) haben Rate-Limits und Quotas.

**Symptome**:
- HTTP 429 Responses
- Provider temporär blockiert
- User-Requests schlagen fehl

**Auswirkung**:
- Schlechte User-Experience
- Token-Kosten steigen (Retries)
- Service-Downtime

**Wahrscheinlichkeit**: **Mittel** (Traffic-Spikes)

**Gegenmaßnahmen**:

#### Rate-Limiter (Token-Bucket)
```yaml
# rate_limiter.yaml
framework:
    rate_limiter:
        ai_anthropic:
            policy: 'token_bucket'
            limit: 50
            rate: { interval: '1 minute' }
            
        ai_openai:
            policy: 'token_bucket'
            limit: 100
            rate: { interval: '1 minute' }
```

**Code**:
```php
$limiter = $this->limiterFactory->create('ai_anthropic');
if (!$limiter->consume(1)->isAccepted()) {
    throw new RateLimitException("Anthropic rate limit exceeded");
}
```

#### Circuit-Breaker
```php
class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';
    
    public function execute(callable $callback, callable $fallback = null): mixed
    {
        if ($this->state === self::STATE_OPEN) {
            if (time() > $this->nextAttemptTime) {
                $this->state = self::STATE_HALF_OPEN;
            } else {
                return $fallback ? $fallback() : throw new CircuitOpenException();
            }
        }
        
        try {
            $result = $callback();
            $this->onSuccess();
            return $result;
        } catch (\Exception $e) {
            $this->onFailure();
            return $fallback ? $fallback() : throw $e;
        }
    }
}
```

#### Fallback-Chain
```php
// Automatisches Fallback: Anthropic → OpenAI → Ollama
$providers = ['anthropic', 'openai', 'ollama'];

foreach ($providers as $providerName) {
    try {
        $provider = $this->registry->getChatProvider($providerName);
        return $provider->simplePrompt($prompt);
    } catch (RateLimitException $e) {
        $this->logger->warning("Provider $providerName rate-limited, trying next");
        continue;
    }
}

throw new AllProvidersFailedException();
```

#### Provider-Quota Tracking
```php
// Track Provider-Usage
class ProviderMetrics
{
    public function trackRequest(string $provider, bool $success): void
    {
        $key = "provider:$provider:requests";
        $this->redis->incr($key);
        $this->redis->expire($key, 3600); // Sliding window
        
        if ($this->redis->get($key) > $this->getLimit($provider)) {
            // Alert + Circuit-Open
        }
    }
}
```

**Monitoring**:
- Provider-Request-Count (Prometheus Counter)
- Rate-Limit-Exceeded-Events (Alert)
- Circuit-Breaker-State (Dashboard)

**Status**: ✅ Geplant

---

### 1.3 Token-Kosten Explosion 💸 🟡

**Risiko**: Hohe API-Kosten durch Dev/Test ohne Mocks.

**Symptome**:
- Hohe Rechnungen (Anthropic, OpenAI)
- Budget überschritten
- Team blockt Features wegen Kosten

**Auswirkung**:
- Projekt zu teuer
- Management stoppt Entwicklung

**Wahrscheinlichkeit**: **Mittel** (ohne Maßnahmen)

**Gegenmaßnahmen**:

#### TestProvider (Token-frei)
```env
# .env.test
AI_DEFAULT_PROVIDER=test
```

```php
// TestProvider gibt Fake-Responses
class TestProvider implements ChatProviderInterface
{
    public function simplePrompt(string $prompt, array $options = []): string
    {
        return "Test response to: $prompt";
    }
}
```

**CI/CD**: Nutzt immer TestProvider (keine echten API-Calls).

#### Lokale Mocks (Wiremock)
```yaml
# tests/fixtures/anthropic-mock.yml
request:
  method: POST
  url: /v1/messages
response:
  status: 200
  body: >
    {"id": "msg_123", "content": [{"text": "Mocked response"}]}
```

#### Sampling (Production)
```php
// Nur 10% der Requests zu echtem Provider
if (rand(1, 100) <= 10) {
    $response = $this->realProvider->chat($prompt);
} else {
    $response = $this->cachedResponseProvider->getRandomResponse();
}
```

#### Budget-Alerts
```bash
# Anthropic API Dashboard
# Alert: Daily spend > $50
# Action: Circuit-Breaker öffnen + Fallback zu Ollama
```

**Monitoring**:
- Token-Usage (per Provider, per User)
- Cost-Dashboard (Grafana)
- Budget-Alerts (Slack)

**Status**: ✅ Geplant

---

### 1.4 Performance-Degradation (N+1 Queries) 🟡

**Risiko**: Doctrine ORM → N+1 Query-Problem bei Relations.

**Symptome**:
- Langsame API-Response (> 2s)
- DB-Überlastung
- High CPU auf DB-Server

**Auswirkung**:
- User-Experience schlecht
- Timeout-Errors
- Skalierung unmöglich

**Wahrscheinlichkeit**: **Mittel** (typisches ORM-Problem)

**Gegenmaßnahmen**:

#### Eager Loading
```php
// BAD: N+1 Query
$messages = $this->messageRepository->findAll();
foreach ($messages as $message) {
    echo $message->getUser()->getName();  // Lazy Load → N Queries
}

// GOOD: Eager Loading
$messages = $this->messageRepository->createQueryBuilder('m')
    ->leftJoin('m.user', 'u')
    ->addSelect('u')
    ->getQuery()
    ->getResult();
```

#### Query-Profiling (Dev)
```yaml
# config/packages/dev/doctrine.yaml
doctrine:
    dbal:
        profiling_collect_backtrace: '%kernel.debug%'
        
    orm:
        enable_profiler: true
```

**Symfony Profiler**: Zeigt alle Queries mit Timing.

#### Doctrine Query-Cache
```yaml
doctrine:
    orm:
        result_cache_driver:
            type: pool
            pool: cache.app
```

#### Query-Monitoring
```bash
# Prometheus Metric
doctrine_query_count_total
doctrine_query_duration_seconds
```

**Alert**: Wenn Query-Count > 100 für einen Request.

**Status**: ✅ Geplant

---

### 1.5 Widget-Kompatibilität bricht 🔴

**Risiko**: Legacy-Widget-Embeds funktionieren nicht mehr mit neuem Backend.

**Symptome**:
- Widget lädt nicht
- CORS-Errors
- Session-Probleme (SameSite=None)
- API-Calls schlagen fehl

**Auswirkung**:
- **Kritisch**: Externe Kunden-Websites brechen
- Support-Tickets
- Reputationsschaden

**Wahrscheinlichkeit**: **Hoch** (Breaking-Change-Risiko)

**Gegenmaßnahmen**:

#### Legacy-Adapter-Controller
```php
// Mappt alte action-Parameter zu neuen Endpoints
#[Route('/api.php')]
class LegacyApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $action = $request->get('action');
        
        return match($action) {
            'messageNew' => $this->messageService->create($request),
            'againOptions' => $this->againLogic->getOptions($request),
            default => new JsonResponse(['error' => 'Unknown action'], 404)
        };
    }
}
```

#### Session-Bridge
```php
// PHP-Session → Symfony Security
class LegacySessionBridge
{
    public function authenticate(Request $request): ?UserInterface
    {
        session_start();
        
        if (isset($_SESSION['USERPROFILE'])) {
            return $this->userRepository->find($_SESSION['USERPROFILE']['BID']);
        }
        
        return null;
    }
}
```

#### Contract-Tests
```php
class WidgetCompatibilityTest extends WebTestCase
{
    public function testLegacyMessageNewEndpoint(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api.php', [
            'action' => 'messageNew',
            'message' => 'Test'
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('tracking_id', $response);
    }
}
```

#### Monitoring
- Widget-Request-Count (per Domain)
- Error-Rate für `/api.php`
- Alert bei > 1% Errors

**Rollback-Plan**: Nginx zurück auf Legacy-PHP (< 5 Min).

**Status**: ✅ Geplant, P0-Priorität

---

## 2. Organisatorische Risiken

### 2.1 Team-Onboarding 🟡

**Risiko**: Team ist nicht vertraut mit Symfony/Doctrine.

**Symptome**:
- Langsame Entwicklung
- Bugs durch Unwissenheit
- Frustration

**Auswirkung**:
- Projekt-Verzögerung (Wochen)
- Qualitätsprobleme

**Wahrscheinlichkeit**: **Mittel**

**Gegenmaßnahmen**:

#### Dokumentation
- [README.md](./README.md): 30-Min-Setup
- [ARCHITECTURE.md](./ARCHITECTURE.md): System-Overview
- [INTERFACES.md](./INTERFACES.md): Provider-Contracts
- [SEQUENCE_DIAGRAMS.md](./SEQUENCE_DIAGRAMS.md): Request-Flows

#### Pair-Programming
- Senior mit Junior
- Code-Reviews (alle PRs)
- Wöchentliche Architecture-Sessions

#### Training
- Symfony-Workshop (2 Tage)
- Doctrine-Basics (1 Tag)
- Messenger-Patterns (1 Tag)

#### Clear Interfaces
- Starke Interface-Kontrakte
- Contract-Tests zeigen Expected-Behavior
- TestProvider als Referenz-Implementierung

**Status**: ✅ Geplant

---

### 2.2 Scope Creep 🟡

**Risiko**: Zusätzliche Features während Migration.

**Symptome**:
- "Können wir auch Feature X hinzufügen?"
- Migration dauert länger als geplant
- Team überlastet

**Auswirkung**:
- Go-Live verzögert
- Budget überschritten

**Wahrscheinlichkeit**: **Hoch** (typisch bei Projekten)

**Gegenmaßnahmen**:

#### Klare Scope-Definition
**Woche 1-7**: NUR Migration (kein New-Features)

**Scope**:
- ✅ Symfony-Setup
- ✅ Provider-Interfaces
- ✅ Doctrine Entities
- ✅ Messenger-Setup
- ✅ Widget-Compat

**Out-of-Scope (Woche 1-7)**:
- ❌ Neue AI-Features
- ❌ UI-Redesign
- ❌ Performance-Tuning (außer kritisch)
- ❌ Neue Integrationen

#### Feature-Freeze
- Alle neuen Features in Backlog
- Priorisierung nach Migration

#### Daily Standups
- Focus: Migration-Blockers
- Keine Feature-Diskussionen

**Status**: ⚠️ Risiko vorhanden

---

### 2.3 Unklare Requirements 🟡

**Risiko**: Partner-Anforderungen nicht vollständig bekannt.

**Symptome**:
- Späte Änderungen
- Rework nötig
- Stress im Team

**Auswirkung**:
- Verzögerung
- Code-Rewrites

**Wahrscheinlichkeit**: **Mittel**

**Gegenmaßnahmen**:

#### Requirement-Gathering (Pre-Migration)
**Inputs benötigt**:
- [ ] DB-Schema + Top-Queries
- [ ] API-Endpoints + Response-Formate
- [ ] AI-Provider/Modelle + Limits
- [ ] File-Flows (Max-Größen, Typen)
- [ ] Security/OIDC-Vorgaben
- [ ] Non-funktionale Ziele (SLAs)

#### Stakeholder-Meetings
- Wöchentlich: Status-Update
- Blocker sofort eskalieren
- Change-Requests schriftlich

#### Flexible Architektur
- Strategy-Pattern → Provider austauschbar
- Config-driven → Änderungen ohne Code
- Feature-Flags → Schrittweise Rollouts

**Status**: ⚠️ Inputs sammeln

---

## 3. Infrastruktur-Risiken

### 3.1 Docker/Deployment-Probleme 🟡

**Risiko**: Docker-Setup auf Hetzner unterscheidet sich von Dev.

**Symptome**:
- "Works on my machine"
- Prod-Deployment schlägt fehl
- Environment-Unterschiede

**Auswirkung**:
- Go-Live verzögert
- Hotfixes nötig

**Wahrscheinlichkeit**: **Mittel**

**Gegenmaßnahmen**:

#### Identische Environments
```yaml
# docker-compose.yml (Dev)
# docker-compose.prod.yml (Prod)
# Nur Unterschiede: Secrets, Volumes, Replicas
```

#### Environment-Parity
- Dev: PHP 8.3, MariaDB 11.7, Redis 7
- Prod: PHP 8.3, MariaDB 11.7, Redis 7

#### Infrastructure as Code
```hcl
# terraform/main.tf
resource "hcloud_server" "web" {
  name        = "synaplan-web-${count.index}"
  image       = "ubuntu-22.04"
  server_type = "cx21"
  count       = 3
}
```

#### Staging-Environment
- Identisch zu Prod (kleinerer Server)
- Deploy zu Staging vor Prod
- Smoke-Tests auf Staging

**Status**: ✅ Geplant

---

### 3.2 Database-Migration-Fehler 🔴

**Risiko**: Doctrine-Migrations schlagen fehl oder korrumpieren Daten.

**Symptome**:
- Migration-Fehler
- Daten inkonsistent
- Rollback nicht möglich

**Auswirkung**:
- **Kritisch**: Datenverlust
- Downtime
- Customer-Impact

**Wahrscheinlichkeit**: **Niedrig** (mit Vorsicht vermeidbar)

**Gegenmaßnahmen**:

#### Staging-First
```bash
# 1. Test auf Staging mit Prod-Daten-Copy
mysqldump prod_db | mysql staging_db
php bin/console doctrine:migrations:migrate --no-interaction

# 2. Verify
php bin/console doctrine:schema:validate

# 3. Smoke-Tests
./vendor/bin/phpunit tests/Integration/
```

#### Backups vor Migration
```bash
# Automatisches Backup-Script
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump synaplan > backups/pre_migration_$DATE.sql
```

#### Dry-Run Migrations
```php
// Doctrine-Migration mit Transaction
class Version20251010000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ...');
    }
    
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ... (ROLLBACK)');
    }
}
```

#### Rollback-Plan dokumentiert
```bash
# Rollback-Befehl
php bin/console doctrine:migrations:migrate prev
```

**Status**: ✅ Geplant, Critical-Path

---

### 3.3 Redis-Ausfall 🟡

**Risiko**: Redis (Cache/Queue) fällt aus.

**Symptome**:
- Cache-Misses (Slow-Down)
- Queue-Messages verloren
- Session-Verlust

**Auswirkung**:
- Performance-Degradation
- User müssen neu einloggen
- Messages nicht verarbeitet

**Wahrscheinlichkeit**: **Niedrig** (Redis stabil)

**Gegenmaßnahmen**:

#### Graceful Degradation
```php
try {
    $cachedData = $this->cache->get($key);
} catch (CacheException $e) {
    $this->logger->warning('Cache unavailable, fetching from DB');
    $cachedData = $this->repository->findById($id);
}
```

#### Redis-Persistence
```conf
# redis.conf
save 900 1       # Save after 900s if 1 key changed
save 300 10      # Save after 300s if 10 keys changed
save 60 10000    # Save after 60s if 10000 keys changed

appendonly yes
appendfsync everysec
```

#### Redis-Cluster (Prod)
- Master + 2 Replicas
- Automatic Failover (Sentinel)

#### Queue-Fallback (Doctrine)
```yaml
# messenger.yaml
transports:
    async:
        dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
        failure_transport: failed
        
    failed:
        dsn: 'doctrine://default?queue_name=failed'  # DB-basiert
```

**Monitoring**:
- Redis-Health-Check
- Alert bei Down

**Status**: ✅ Geplant

---

## 4. Security-Risiken

### 4.1 API-Key-Leakage 🔴

**Risiko**: API-Keys (Anthropic, OpenAI) landen in Git oder Logs.

**Symptome**:
- Keys committed
- Keys in Logs sichtbar
- Unbefugte Nutzung

**Auswirkung**:
- **Kritisch**: Kosten-Explosion
- Security-Breach

**Wahrscheinlichkeit**: **Niedrig** (mit Vorsichtsmaßnahmen)

**Gegenmaßnahmen**:

#### .gitignore
```
# .gitignore
.env
.env.local
.env.*.local
```

#### .env.example (Placeholders)
```env
# .env.example
ANTHROPIC_API_KEY=sk-ant-YOUR_KEY_HERE
OPENAI_API_KEY=sk-YOUR_KEY_HERE
```

#### Pre-Commit-Hook
```bash
# .git/hooks/pre-commit
#!/bin/bash
if git diff --cached | grep -E 'sk-ant-|sk-proj-'; then
    echo "ERROR: API-Key detected in commit!"
    exit 1
fi
```

#### Log-Sanitization
```php
// Monolog-Processor
class SanitizeProcessor
{
    public function __invoke(array $record): array
    {
        $record['message'] = preg_replace(
            '/sk-(ant|proj)-[a-zA-Z0-9]+/',
            'sk-***-REDACTED',
            $record['message']
        );
        return $record;
    }
}
```

#### Secret-Management (Prod)
```bash
# Vault / AWS Secrets Manager / K8s Secrets
# Keine Keys in .env-Dateien auf Server
```

**Status**: ✅ Geplant

---

### 4.2 CORS-Fehler (Widget) 🟡

**Risiko**: Widget-iframes blockiert durch CORS-Policy.

**Symptome**:
- Console-Errors: "CORS policy"
- API-Calls schlagen fehl
- Widget funktioniert nicht

**Auswirkung**:
- Widget unbenutzbar
- Customer-Complaints

**Wahrscheinlichkeit**: **Mittel**

**Gegenmaßnahmen**:

#### CORS-Header konfigurieren
```yaml
# config/packages/nelmio_cors.yaml
nelmio_cors:
    defaults:
        origin_regex: true
        allow_origin: ['*']  # Oder: Whitelist spezifischer Domains
        allow_methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']
        allow_headers: ['Content-Type', 'Authorization', 'X-Requested-With']
        expose_headers: ['X-Total-Count']
        max_age: 3600
    paths:
        '^/api/': ~
```

#### SameSite=None für Cookies
```php
// Session-Config (für widget.php Compat)
session_set_cookie_params([
    'secure' => true,      // Nur HTTPS
    'httponly' => true,
    'samesite' => 'None'   // Erlaubt Cross-Site Cookies
]);
```

#### Testing
```javascript
// Test von externer Domain
fetch('https://synaplan.com/api/v1/health')
  .then(r => r.json())
  .then(console.log)
  .catch(console.error);
```

**Status**: ✅ Geplant

---

## 5. Business-Risiken

### 5.1 Go-Live-Termin nicht eingehalten 🟡

**Risiko**: Migration dauert länger als 7 Tage.

**Auswirkung**:
- Management unzufrieden
- Budget-Überschreitung
- Opportunity-Cost

**Wahrscheinlichkeit**: **Mittel**

**Gegenmaßnahmen**:

#### Puffer-Tage einplanen
- Plan: 7 Tage
- Realistisch: 10 Tage
- Buffer: 3 Tage

#### MVP-Scope
- Fokus auf P0-Deliverables
- P1/P2 nach Go-Live

#### Daily Standup + Blocker-Tracking
- Blocker sofort eskalieren
- Hilfe von außen (Freelancer, Support)

#### Strangler-Pattern (Risiko-Reduzierung)
- Kein Big-Bang
- Schrittweise Migration
- Rollback jederzeit möglich

**Status**: ⚠️ Zeitplan ambitioniert

---

### 5.2 Budget-Überschreitung 💸 🟡

**Risiko**: Kosten (AI-Tokens, Infra, Time) höher als geplant.

**Auswirkung**:
- Projekt gestoppt
- Features reduziert

**Wahrscheinlichkeit**: **Niedrig** (mit Kostenkontrolle)

**Gegenmaßnahmen**:

#### Cost-Tracking
- Wöchentliche Cost-Reviews
- Dashboard: Token-Usage, Server-Kosten

#### Hetzner-Fokus (statt AWS)
- Faktor 5-10x günstiger
- Dedicated Server statt Cloud

#### Ollama für unkritische Tasks
- Lokale Inference
- Keine Token-Kosten

#### Budget-Alerts
- Alert bei > 80% Budget
- Eskalation an Management

**Status**: ✅ Geplant

---

## Risiko-Matrix

| Risiko | Wahrscheinlichkeit | Impact | Priorität | Status |
|--------|-------------------|--------|-----------|--------|
| Galera Write-Conflicts | Hoch | Hoch | 🔴 P0 | ✅ Mitigiert |
| Provider Rate-Limits | Mittel | Mittel | 🟡 P1 | ✅ Mitigiert |
| Token-Kosten | Mittel | Hoch | 🟡 P1 | ✅ Mitigiert |
| N+1 Queries | Mittel | Mittel | 🟡 P1 | ✅ Geplant |
| Widget-Compat bricht | Hoch | Hoch | 🔴 P0 | ✅ Mitigiert |
| Team-Onboarding | Mittel | Mittel | 🟡 P1 | ✅ Geplant |
| Scope Creep | Hoch | Mittel | 🟡 P1 | ⚠️ Beobachten |
| Unklare Requirements | Mittel | Mittel | 🟡 P1 | ⚠️ Inputs fehlen |
| Docker-Deployment | Mittel | Niedrig | 🟢 P2 | ✅ Geplant |
| DB-Migration-Fehler | Niedrig | Hoch | 🔴 P0 | ✅ Mitigiert |
| Redis-Ausfall | Niedrig | Mittel | 🟡 P1 | ✅ Mitigiert |
| API-Key-Leakage | Niedrig | Hoch | 🔴 P0 | ✅ Mitigiert |
| CORS-Fehler | Mittel | Mittel | 🟡 P1 | ✅ Geplant |
| Go-Live-Delay | Mittel | Mittel | 🟡 P1 | ⚠️ Buffer |
| Budget-Überschreitung | Niedrig | Hoch | 🟡 P1 | ✅ Tracking |

---

## Eskalations-Pfad

**Level 1 – Team-intern**:
- Daily Standup
- Slack-Channel: #synaplan-migration

**Level 2 – Tech Lead**:
- Blocker > 1 Tag
- Architektur-Entscheidungen

**Level 3 – Management**:
- Go-Live-Risiko
- Budget-Überschreitung
- Externe Hilfe benötigt

---

**Review**: Wöchentlich
**Owner**: Tech Lead
**Status**: 🟡 Risiken identifiziert, Maßnahmen geplant

