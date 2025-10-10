# Synaplan Symfony 7 Migration – Database Schema & Doctrine Mapping

## Übersicht

Diese Dokumentation beschreibt die **Doctrine-Entity-Mapping-Strategie** für die bestehenden MariaDB-Tabellen, Read/Write-Splitting, Indexierungs-Strategie und Outbox-Pattern.

---

## 1. Bestehendes Schema (Baseline)

### Tabellen-Übersicht

| Tabelle | Zweck | Zeilen (Prod) | Primär-Index |
|---------|-------|---------------|--------------|
| **BUSER** | User-Accounts | ~50k | BID (bigint) |
| **BMESSAGES** | Chat-Messages | ~5M | BID (bigint) |
| **BMESSAGEMETA** | Message-Metadata (JSON) | ~5M | BID (bigint) |
| **BPROMPTS** | System-Prompts | ~200 | BID (bigint) |
| **BMODELS** | AI-Modelle | ~50 | BID (bigint) |
| **BCONFIG** | Key-Value Config | ~1k | BID (bigint) |
| **BRAG** | Vector-Embeddings (RAG) | ~2M | BID (bigint) |
| **BAPIKEYS** | API-Keys für externe Zugriffe | ~100 | BID (bigint) |
| **BSESSIONS** | PHP-Sessions | ~10k | BID (varchar) |
| **BTOKENS** | JWT/OAuth Tokens | ~20k | BID (bigint) |
| **BUSELOG** | Usage-Tracking | ~10M | BID (bigint) |
| **BRATELIMITS_CONFIG** | Rate-Limit-Regeln | ~50 | BID (bigint) |
| **BPAYMENTS** | Payment-History | ~1k | BID (bigint) |
| **BSUBSCRIPTIONS** | User-Subscriptions | ~500 | BID (bigint) |
| **BTRANSLATE** | Übersetzungs-Cache | ~5k | BID (bigint) |
| **BLISTS** | Listen/Tags | ~200 | BID (bigint) |
| **BSOCIAL** | Social-Logins | ~100 | BID (bigint) |
| **BWAIDS** | WhatsApp-IDs | ~50 | BID (bigint) |
| **BWAPHONES** | WhatsApp-Nummern | ~50 | BID (bigint) |
| **BMAILS** | E-Mail-Handler-Config | ~10 | BID (bigint) |
| **BPROMPT2MODEL** | Prompt→Model Mapping | ~500 | BID (bigint) |
| **BPROMPTMETA** | Prompt-Metadata | ~200 | BID (bigint) |
| **BCAPABILITIES** | AI-Capabilities | ~100 | BID (bigint) |

**Total**: ~23 Tabellen, ~20 Millionen Zeilen (geschätzt)

---

## 2. Doctrine-Entity-Mapping

### 2.1 Naming-Strategy

**Problem**: Tabellen haben `B`-Prefix (BUSER, BMESSAGES), Spalten ebenfalls (BID, BMAIL).

**Lösung**: Custom Naming Strategy

```php
// src/Doctrine/CustomNamingStrategy.php
namespace App\Doctrine;

use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;

class CustomNamingStrategy extends UnderscoreNamingStrategy
{
    public function classToTableName(string $className): string
    {
        // User → BUSER, Message → BMESSAGES
        $tableName = parent::classToTableName($className);
        
        if (!str_starts_with($tableName, 'b_')) {
            $tableName = 'b' . $tableName;
        }
        
        return strtoupper($tableName);
    }

    public function propertyToColumnName(string $propertyName, ?string $className = null): string
    {
        // id → BID, email → BMAIL
        $columnName = parent::propertyToColumnName($propertyName);
        
        if (!str_starts_with($columnName, 'b')) {
            $columnName = 'b' . $columnName;
        }
        
        return strtoupper($columnName);
    }
}
```

**Config**:
```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        naming_strategy: App\Doctrine\CustomNamingStrategy
```

---

### 2.2 Core Entities

#### User Entity (BUSER)

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\UserRepository;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'BUSER')]
#[ORM\Index(columns: ['BMAIL'], name: 'idx_user_email')]
#[ORM\Index(columns: ['BUSERLEVEL'], name: 'idx_user_level')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BCREATED', length: 20)]
    private string $created;

    #[ORM\Column(name: 'BINTYPE', length: 4, options: ['default' => 'WEB'])]
    private string $type = 'WEB';

    #[ORM\Column(name: 'BMAIL', length: 128, unique: true)]
    private string $email;

    #[ORM\Column(name: 'BPW', length: 64)]
    private string $password;

    #[ORM\Column(name: 'BPROVIDERID', length: 32)]
    private string $providerId = '';

    #[ORM\Column(name: 'BUSERLEVEL', length: 32, options: ['default' => 'NEW'])]
    private string $userLevel = 'NEW';

    #[ORM\Column(name: 'BUSERDETAILS', type: 'json')]
    private array $userDetails = [];

    #[ORM\Column(name: 'BPAYMENTDETAILS', type: 'json')]
    private array $paymentDetails = [];

    // Relations
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Message::class)]
    private Collection $messages;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: ApiKey::class)]
    private Collection $apiKeys;

    // Getters/Setters...
}
```

---

#### Message Entity (BMESSAGES)

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\MessageRepository;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'BMESSAGES')]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_message_user')]
#[ORM\Index(columns: ['BTRACKID'], name: 'idx_message_track')]
#[ORM\Index(columns: ['BTOPIC'], name: 'idx_message_topic')]
#[ORM\Index(columns: ['BDIRECT'], name: 'idx_message_direction')]
#[ORM\Index(columns: ['BUNIXTIMES'], name: 'idx_message_time')]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'BUSERID', referencedColumnName: 'BID')]
    private User $user;

    #[ORM\Column(name: 'BTRACKID', type: 'bigint')]
    private int $trackingId;

    #[ORM\Column(name: 'BPROVIDX', length: 96)]
    private string $providerIndex = '';

    #[ORM\Column(name: 'BUNIXTIMES', type: 'bigint')]
    private int $unixTimestamp;

    #[ORM\Column(name: 'BDATETIME', length: 20)]
    private string $dateTime;

    #[ORM\Column(name: 'BMESSTYPE', length: 4, options: ['default' => 'WEB'])]
    private string $messageType = 'WEB';

    #[ORM\Column(name: 'BFILE', type: 'boolean')]
    private bool $hasFile = false;

    #[ORM\Column(name: 'BFILEPATH', length: 255)]
    private string $filePath = '';

    #[ORM\Column(name: 'BFILETYPE', length: 8)]
    private string $fileType = '';

    #[ORM\Column(name: 'BTOPIC', length: 16, options: ['default' => 'UNKNOWN'])]
    private string $topic = 'UNKNOWN';

    #[ORM\Column(name: 'BLANG', length: 2, options: ['default' => 'NN'])]
    private string $language = 'NN';

    #[ORM\Column(name: 'BTEXT', type: 'text')]
    private string $text = '';

    #[ORM\Column(name: 'BDIRECT', length: 3, options: ['default' => 'OUT'])]
    private string $direction = 'OUT';

    #[ORM\Column(name: 'BSTATUS', length: 24)]
    private string $status = '';

    #[ORM\Column(name: 'BFILETEXT', type: 'text')]
    private string $fileText = '';

    // Getters/Setters...
}
```

---

#### RagDocument Entity (BRAG) – Vector Search

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'BRAG')]
#[ORM\Index(columns: ['BUID'], name: 'idx_rag_user')]
#[ORM\Index(columns: ['BMID'], name: 'idx_rag_message')]
#[ORM\Index(columns: ['BGROUPKEY'], name: 'idx_rag_group')]
#[ORM\Index(columns: ['BTYPE'], name: 'idx_rag_type')]
class RagDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BMID', type: 'bigint')]
    private int $messageId;

    #[ORM\Column(name: 'BGROUPKEY', length: 64)]
    private string $groupKey;

    #[ORM\Column(name: 'BTYPE', type: 'integer')]
    private int $fileType;

    #[ORM\Column(name: 'BSTART', type: 'integer')]
    private int $startLine;

    #[ORM\Column(name: 'BEND', type: 'integer')]
    private int $endLine;

    // MariaDB 11.7 Vector Type (1536 dimensions)
    #[ORM\Column(name: 'BEMBED', type: 'string', columnDefinition: 'VECTOR(1536)')]
    private string $embedding;

    // Getters/Setters...
    
    public function getEmbeddingArray(): array
    {
        // Parse "VEC_FromText('[0.1, 0.2, ...]')"
        return json_decode($this->embedding, true);
    }
    
    public function setEmbeddingArray(array $vector): void
    {
        $this->embedding = '[' . implode(', ', $vector) . ']';
    }
}
```

**Custom DQL Function für Vector-Search**:
```php
// src/Doctrine/DQL/VectorDistanceFunction.php
namespace App\Doctrine\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;

class VectorDistanceFunction extends FunctionNode
{
    public $column;
    public $vector;

    public function getSql(\Doctrine\ORM\Query\SqlWalker $sqlWalker): string
    {
        return sprintf(
            'VEC_DISTANCE(%s, VEC_FromText(%s))',
            $this->column->dispatch($sqlWalker),
            $this->vector->dispatch($sqlWalker)
        );
    }
    
    // parse() implementation...
}
```

**Config**:
```yaml
doctrine:
    orm:
        dql:
            string_functions:
                VEC_DISTANCE: App\Doctrine\DQL\VectorDistanceFunction
```

**Query**:
```php
$queryVector = '[0.123, -0.456, ...]';

$qb = $this->createQueryBuilder('r')
    ->where('r.userId = :userId')
    ->andWhere('VEC_DISTANCE(r.embedding, :vector) < :threshold')
    ->setParameter('userId', $userId)
    ->setParameter('vector', $queryVector)
    ->setParameter('threshold', 0.3)
    ->orderBy('VEC_DISTANCE(r.embedding, :vector)', 'ASC')
    ->setMaxResults(10);

$results = $qb->getQuery()->getResult();
```

---

### 2.3 Weitere Entities (Auswahl)

#### ApiKey Entity (BAPIKEYS)

```php
#[ORM\Entity]
#[ORM\Table(name: 'BAPIKEYS')]
#[ORM\Index(columns: ['BKEY'], name: 'idx_apikey_key')]
#[ORM\Index(columns: ['BOWNERID'], name: 'idx_apikey_owner')]
class ApiKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BKEY', length: 64, unique: true)]
    private string $key;

    #[ORM\Column(name: 'BSTATUS', length: 16)]
    private string $status = 'active';

    #[ORM\Column(name: 'BLASTUSED', type: 'bigint')]
    private int $lastUsed = 0;

    #[ORM\Column(name: 'BSCOPES', type: 'json')]
    private array $scopes = [];
}
```

#### Config Entity (BCONFIG)

```php
#[ORM\Entity]
#[ORM\Table(name: 'BCONFIG')]
#[ORM\Index(columns: ['BOWNERID', 'BGROUP', 'BSETTING'], name: 'idx_config_lookup')]
class Config
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BGROUP', length: 64)]
    private string $group;

    #[ORM\Column(name: 'BSETTING', length: 64)]
    private string $setting;

    #[ORM\Column(name: 'BVALUE', type: 'text')]
    private string $value;
}
```

---

## 3. Read/Write Connection Splitting

### 3.1 Config

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:  # WRITE
                url: '%env(resolve:DATABASE_WRITE_URL)%'
                driver: 'pdo_mysql'
                server_version: '11.7'
                charset: utf8mb4
                options:
                    !php/const PDO::ATTR_PERSISTENT: true
                
            read:     # READ
                url: '%env(resolve:DATABASE_READ_URL)%'
                driver: 'pdo_mysql'
                server_version: '11.7'
                charset: utf8mb4
                options:
                    !php/const PDO::ATTR_PERSISTENT: true

    orm:
        default_entity_manager: default
        entity_managers:
            default:
                connection: default
                naming_strategy: App\Doctrine\CustomNamingStrategy
                mappings:
                    App:
                        is_bundle: false
                        dir: '%kernel.project_dir%/src/Entity'
                        prefix: 'App\Entity'
                        alias: App
                        
            read:
                connection: read
                naming_strategy: App\Doctrine\CustomNamingStrategy
                mappings:
                    App:
                        is_bundle: false
                        dir: '%kernel.project_dir%/src/Entity'
                        prefix: 'App\Entity'
                        alias: App
```

**.env**:
```env
# Write-Connection: Galera Master Node
DATABASE_WRITE_URL=mysql://synaplan:pass@galera-node1.internal:3306/synaplan?serverVersion=11.7

# Read-Connection: Load-Balancer über alle Nodes
DATABASE_READ_URL=mysql://synaplan:pass@galera-loadbalancer.internal:3306/synaplan?serverVersion=11.7
```

---

### 3.2 Usage in Services

```php
// Writes
class MessageService
{
    public function __construct(
        private EntityManagerInterface $em  // default = write
    ) {}

    public function createMessage(array $data): Message
    {
        $message = new Message();
        // ... set properties
        
        $this->em->persist($message);
        $this->em->flush();
        
        return $message;
    }
}

// Reads
class MessageQueryService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.read_entity_manager')]
        private EntityManagerInterface $readEm
    ) {}

    public function findRecentByUser(int $userId, int $limit = 10): array
    {
        return $this->readEm->getRepository(Message::class)
            ->createQueryBuilder('m')
            ->where('m.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('m.unixTimestamp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
```

---

## 4. Indexierungs-Strategie

### 4.1 Query-Analyse

**Top-Queries** (von Legacy-System):

```sql
-- 1. User-Login (häufig)
SELECT * FROM BUSER WHERE BMAIL = ?;

-- 2. Recent Messages per User
SELECT * FROM BMESSAGES 
WHERE BUSERID = ? 
ORDER BY BUNIXTIMES DESC 
LIMIT 10;

-- 3. Message by Tracking-ID
SELECT * FROM BMESSAGES 
WHERE BTRACKID = ?;

-- 4. Config Lookup
SELECT BVALUE FROM BCONFIG 
WHERE BOWNERID = ? 
  AND BGROUP = ? 
  AND BSETTING = ?;

-- 5. API-Key Validation
SELECT * FROM BAPIKEYS 
WHERE BKEY = ? 
  AND BSTATUS = 'active';

-- 6. RAG Vector Search
SELECT * FROM BRAG 
WHERE BUID = ? 
  AND VEC_DISTANCE(BEMBED, VEC_FromText(?)) < 0.3 
ORDER BY VEC_DISTANCE(BEMBED, VEC_FromText(?)) ASC 
LIMIT 10;
```

---

### 4.2 Index-Definitionen

**BUSER**:
```sql
CREATE INDEX idx_user_email ON BUSER(BMAIL);
CREATE INDEX idx_user_level ON BUSER(BUSERLEVEL);
CREATE INDEX idx_user_provider ON BUSER(BPROVIDERID);
```

**BMESSAGES**:
```sql
CREATE INDEX idx_message_user_time ON BMESSAGES(BUSERID, BUNIXTIMES DESC);
CREATE INDEX idx_message_track ON BMESSAGES(BTRACKID);
CREATE INDEX idx_message_topic ON BMESSAGES(BTOPIC);
CREATE INDEX idx_message_direction ON BMESSAGES(BDIRECT);
CREATE INDEX idx_message_status ON BMESSAGES(BSTATUS);

-- Composite für häufige Query
CREATE INDEX idx_message_user_direction_time 
ON BMESSAGES(BUSERID, BDIRECT, BUNIXTIMES DESC);
```

**BCONFIG**:
```sql
CREATE UNIQUE INDEX idx_config_lookup 
ON BCONFIG(BOWNERID, BGROUP, BSETTING);
```

**BAPIKEYS**:
```sql
CREATE UNIQUE INDEX idx_apikey_key ON BAPIKEYS(BKEY);
CREATE INDEX idx_apikey_owner ON BAPIKEYS(BOWNERID);
CREATE INDEX idx_apikey_status ON BAPIKEYS(BSTATUS);
```

**BRAG** (Vector Search):
```sql
CREATE INDEX idx_rag_user ON BRAG(BUID);
CREATE INDEX idx_rag_message ON BRAG(BMID);
CREATE INDEX idx_rag_group ON BRAG(BGROUPKEY);

-- Vector-Index (MariaDB 11.7)
CREATE VECTOR INDEX idx_rag_embed ON BRAG(BEMBED);
```

---

### 4.3 Query-Optimization

**N+1 Problem vermeiden**:
```php
// BAD: N+1 Query
$messages = $repo->findAll();
foreach ($messages as $message) {
    echo $message->getUser()->getName();  // Lazy Load → N queries
}

// GOOD: Eager Loading
$messages = $repo->createQueryBuilder('m')
    ->leftJoin('m.user', 'u')
    ->addSelect('u')  // Fetch User in same query
    ->getQuery()
    ->getResult();
```

**Batch-Processing**:
```php
// Process 10k messages
$batchSize = 100;
$count = 0;

foreach ($messages as $message) {
    $this->em->persist($message);
    
    if (++$count % $batchSize === 0) {
        $this->em->flush();
        $this->em->clear();  // Detach entities
    }
}

$this->em->flush();
$this->em->clear();
```

---

## 5. Redis 2nd Level Cache

### 5.1 Config

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        second_level_cache:
            enabled: true
            region_cache_driver:
                type: pool
                pool: cache.app
            regions:
                default:
                    lifetime: 3600
                config_cache:
                    lifetime: 86400
                user_cache:
                    lifetime: 1800
```

```yaml
# config/packages/cache.yaml
framework:
    cache:
        app: cache.adapter.redis
        default_redis_provider: '%env(REDIS_URL)%'
```

---

### 5.2 Entity-Config

```php
#[ORM\Entity]
#[ORM\Cache(usage: 'READ_WRITE', region: 'config_cache')]
class Config
{
    // ...
}

#[ORM\Entity]
#[ORM\Cache(usage: 'READ_ONLY', region: 'user_cache')]
class User
{
    // ...
}
```

**Query-Cache**:
```php
$query = $repo->createQueryBuilder('c')
    ->where('c.ownerId = :ownerId')
    ->setParameter('ownerId', $userId)
    ->getQuery()
    ->useResultCache(true, 3600, 'config_user_' . $userId);

$results = $query->getResult();
```

---

## 6. Outbox-Pattern für Events

### 6.1 Outbox-Entity

```php
#[ORM\Entity]
#[ORM\Table(name: 'outbox_events')]
#[ORM\Index(columns: ['processed', 'created_at'], name: 'idx_outbox_pending')]
class OutboxEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $eventType;

    #[ORM\Column(type: 'string', length: 64)]
    private string $aggregateId;

    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'boolean')]
    private bool $processed = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $processedAt = null;
}
```

---

### 6.2 Usage

```php
// MessageService
public function createMessage(array $data): Message
{
    $message = new Message();
    // ... set properties
    
    $this->em->persist($message);
    
    // Create Outbox Event (same transaction)
    $event = new OutboxEvent();
    $event->setEventType('message.created');
    $event->setAggregateId((string)$message->getId());
    $event->setPayload([
        'message_id' => $message->getId(),
        'user_id' => $message->getUserId(),
        'text' => $message->getText()
    ]);
    $event->setCreatedAt(new \DateTime());
    
    $this->em->persist($event);
    $this->em->flush();  // Atomic: Message + Event
    
    return $message;
}
```

---

### 6.3 Outbox-Worker

```php
// src/Message/Handler/OutboxEventHandler.php
#[AsMessageHandler]
class OutboxEventHandler
{
    public function __invoke(ProcessOutboxEventsCommand $command): void
    {
        $events = $this->eventRepository->findPendingEvents(100);
        
        foreach ($events as $event) {
            try {
                $this->dispatchEvent($event);
                
                $event->setProcessed(true);
                $event->setProcessedAt(new \DateTime());
                $this->em->flush();
            } catch (\Exception $e) {
                $this->logger->error('Outbox event failed', [
                    'event_id' => $event->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    private function dispatchEvent(OutboxEvent $event): void
    {
        match($event->getEventType()) {
            'message.created' => $this->indexer->indexMessage($event->getPayload()),
            'file.uploaded' => $this->webhookSender->send($event->getPayload()),
            default => $this->logger->warning('Unknown event type')
        };
    }
}
```

**Cron**:
```bash
# Every minute
* * * * * php bin/console messenger:consume outbox -vv --time-limit=60
```

---

## 7. Database-Partitionierung (optional, Woche 4+)

**Strategie**: User-basierte Partitionierung für große Tabellen (BMESSAGES, BRAG).

```sql
-- Partition BMESSAGES by User-Range
ALTER TABLE BMESSAGES 
PARTITION BY RANGE (BUSERID) (
    PARTITION p0 VALUES LESS THAN (1000),
    PARTITION p1 VALUES LESS THAN (10000),
    PARTITION p2 VALUES LESS THAN (50000),
    PARTITION p3 VALUES LESS THAN (MAXVALUE)
);
```

**Benefits**:
- Query-Performance (Partition Pruning)
- Backup/Restore per Partition
- Archivierung alter Partitions

**Nachteile**:
- Komplexität
- Maintenance-Overhead

**Entscheidung**: Erst bei > 50M Messages implementieren.

---

## 8. Migrations-Strategie

### 8.1 Initial Baseline

```bash
# 1. Import existing SQL-Schema
cat dev/db-loadfiles/*.sql | mysql -u synaplan -p synaplan

# 2. Generate Doctrine Entities from DB
php bin/console doctrine:mapping:import "App\Entity" attribute --path=src/Entity

# 3. Create initial migration (no changes)
php bin/console doctrine:migrations:diff
# → Version20251010000000_InitialBaseline.php

# 4. Mark as executed (no actual DB changes)
php bin/console doctrine:migrations:version --add --all
```

---

### 8.2 Zukünftige Änderungen

**NUR über Doctrine-Migrations**:
```bash
# 1. Änderung in Entity
# 2. Migration generieren
php bin/console doctrine:migrations:diff

# 3. Migration prüfen
cat migrations/Version20251011123456.php

# 4. Dry-Run auf Staging
php bin/console doctrine:migrations:migrate --dry-run

# 5. Execute auf Staging
php bin/console doctrine:migrations:migrate

# 6. Verify
php bin/console doctrine:schema:validate

# 7. Rollout auf Prod (mit Backup!)
```

---

## Zusammenfassung

**Doctrine-Setup**:
- ✅ Custom Naming Strategy für `B`-Prefix
- ✅ Read/Write Connection Splitting
- ✅ 23 Entities gemappt
- ✅ Relations definiert

**Performance**:
- ✅ Indexes basierend auf Query-Patterns
- ✅ 2nd Level Cache (Redis)
- ✅ Query-Optimization (Eager Loading, Batch-Flush)
- ✅ Partitionierung (optional, später)

**Resilience**:
- ✅ Outbox-Pattern für Events
- ✅ Vector-Search (MariaDB 11.7)
- ✅ Migrations-Strategie

---

**Status**: ✅ Konzept finalisiert
**Review**: Tag 6

