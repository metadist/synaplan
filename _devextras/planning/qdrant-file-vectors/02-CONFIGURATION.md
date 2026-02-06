# Configuration System

## Overview

Vector storage provider selection via `.env` file, following Synaplan's existing configuration patterns.

---

## Environment Variables

### Backend `.env`

```bash
# =============================================================================
# VECTOR STORAGE CONFIGURATION
# =============================================================================

# Vector storage provider: 'mariadb' (default) or 'qdrant'
# MariaDB uses built-in VECTOR type (slower but no external dependencies)
# Qdrant uses the memories service (faster, requires qdrant-service)
VECTOR_STORAGE_PROVIDER=mariadb

# Qdrant service connection (only required if VECTOR_STORAGE_PROVIDER=qdrant)
# This is the same service used for user memories (synaplan-memories)
QDRANT_SERVICE_URL=http://host.docker.internal:8090
QDRANT_SERVICE_API_KEY=changeme-in-production

# Qdrant collection for document vectors (separate from memories)
# Default: user_documents (memories use: user_memories)
QDRANT_DOCUMENTS_COLLECTION=user_documents

# =============================================================================
# EXISTING CONFIGURATION (unchanged)
# =============================================================================

# Memory service (already exists for chat memories)
# QDRANT_SERVICE_URL=http://host.docker.internal:8090
# QDRANT_SERVICE_API_KEY=changeme-in-production
```

### Docker Compose Integration

```yaml
# docker-compose.yml (synaplan)
services:
  backend:
    environment:
      - VECTOR_STORAGE_PROVIDER=${VECTOR_STORAGE_PROVIDER:-mariadb}
      - QDRANT_SERVICE_URL=${QDRANT_SERVICE_URL:-}
      - QDRANT_SERVICE_API_KEY=${QDRANT_SERVICE_API_KEY:-}
      - QDRANT_DOCUMENTS_COLLECTION=${QDRANT_DOCUMENTS_COLLECTION:-user_documents}
```

---

## Symfony Configuration

### Services Configuration

```yaml
# config/services.yaml

parameters:
    vector_storage_provider: '%env(default:mariadb:VECTOR_STORAGE_PROVIDER)%'
    qdrant_service_url: '%env(default::QDRANT_SERVICE_URL)%'
    qdrant_service_api_key: '%env(default::QDRANT_SERVICE_API_KEY)%'
    qdrant_documents_collection: '%env(default:user_documents:QDRANT_DOCUMENTS_COLLECTION)%'

services:
    # Vector Storage Implementations
    App\Service\RAG\VectorStorage\MariaDBVectorStorage:
        arguments:
            $entityManager: '@doctrine.orm.entity_manager'

    App\Service\RAG\VectorStorage\QdrantVectorStorage:
        arguments:
            $qdrantServiceUrl: '%qdrant_service_url%'
            $qdrantApiKey: '%qdrant_service_api_key%'
            $collectionName: '%qdrant_documents_collection%'
            $httpClient: '@Symfony\Contracts\HttpClient\HttpClientInterface'
            $logger: '@logger'

    # Facade (auto-selects backend)
    App\Service\RAG\VectorStorage\VectorStorageFacade:
        arguments:
            $vectorStorageProvider: '%vector_storage_provider%'
            $mariadbStorage: '@App\Service\RAG\VectorStorage\MariaDBVectorStorage'
            $qdrantStorage: '@App\Service\RAG\VectorStorage\QdrantVectorStorage'
            $logger: '@logger'

    # Alias for interface
    App\Service\RAG\VectorStorage\VectorStorageInterface:
        alias: App\Service\RAG\VectorStorage\VectorStorageFacade
```

---

## Configuration Validation

### Startup Health Check

```php
// src/EventListener/VectorStorageHealthListener.php

namespace App\EventListener;

use App\Service\RAG\VectorStorage\VectorStorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 1000)]
final readonly class VectorStorageHealthListener
{
    private static bool $checked = false;

    public function __construct(
        private VectorStorageInterface $vectorStorage,
        private LoggerInterface $logger,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (self::$checked || !$event->isMainRequest()) {
            return;
        }
        self::$checked = true;

        $backend = $this->vectorStorage->getBackendType();
        $healthy = $this->vectorStorage->isHealthy();

        if (!$healthy) {
            $this->logger->error('Vector storage backend unhealthy', [
                'backend' => $backend,
            ]);
        } else {
            $this->logger->info('Vector storage backend healthy', [
                'backend' => $backend,
            ]);
        }
    }
}
```

---

## Admin API Endpoint

### Get Current Configuration

```php
// src/Controller/Admin/VectorStorageConfigController.php

#[Route('/api/v1/admin/vector-storage')]
final class VectorStorageConfigController extends AbstractController
{
    public function __construct(
        private VectorStorageInterface $vectorStorage,
    ) {}

    /**
     * Get current vector storage configuration and health status.
     */
    #[Route('/status', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function status(): JsonResponse
    {
        return $this->json([
            'provider' => $this->vectorStorage->getBackendType(),
            'healthy' => $this->vectorStorage->isHealthy(),
            'configured_via' => 'environment',
        ]);
    }

    /**
     * Test vector storage connection.
     */
    #[Route('/test', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function test(): JsonResponse
    {
        $startTime = microtime(true);
        $healthy = $this->vectorStorage->isHealthy();
        $duration = (microtime(true) - $startTime) * 1000;

        return $this->json([
            'success' => $healthy,
            'backend' => $this->vectorStorage->getBackendType(),
            'response_time_ms' => round($duration, 2),
        ]);
    }
}
```

---

## Configuration Decision Flow

```
Application Start
       │
       ▼
Read VECTOR_STORAGE_PROVIDER from .env
       │
       ├─► "qdrant"
       │      │
       │      ▼
       │   Check QDRANT_SERVICE_URL is set
       │      │
       │      ├─► Not set → Log warning, fall back to mariadb
       │      │
       │      └─► Set → Initialize QdrantVectorStorage
       │                    │
       │                    ▼
       │              Test connection (isHealthy)
       │                    │
       │                    ├─► Fail → Log error (continue with qdrant anyway)
       │                    │
       │                    └─► Success → Log info
       │
       └─► "mariadb" (or default)
              │
              ▼
         Initialize MariaDBVectorStorage
              │
              ▼
         Ready (uses existing BRAG table)
```

---

## Environment Profiles

### Development (default)

```bash
# .env.local
VECTOR_STORAGE_PROVIDER=mariadb
# No external dependencies needed
```

### Development with Qdrant

```bash
# .env.local
VECTOR_STORAGE_PROVIDER=qdrant
QDRANT_SERVICE_URL=http://localhost:8090
QDRANT_SERVICE_API_KEY=dev-key
```

### Production (MariaDB)

```bash
# .env.prod.local
VECTOR_STORAGE_PROVIDER=mariadb
# Uses built-in MariaDB vectors
```

### Production (Qdrant - recommended for scale)

```bash
# .env.prod.local
VECTOR_STORAGE_PROVIDER=qdrant
QDRANT_SERVICE_URL=http://memories-service:8080
QDRANT_SERVICE_API_KEY=production-api-key-here
QDRANT_DOCUMENTS_COLLECTION=user_documents
```

---

## Migration Between Backends

### Switching from MariaDB to Qdrant

1. Deploy qdrant-service with `user_documents` collection
2. Set `VECTOR_STORAGE_PROVIDER=qdrant` in `.env`
3. Restart backend containers
4. New uploads go to Qdrant
5. Existing vectors remain in MariaDB (search still works via mixed results OR re-vectorize)

### Re-vectorization Command

```php
// bin/console app:vectors:migrate --source=mariadb --target=qdrant

// Migrates all vectors from MariaDB to Qdrant
// - Reads chunks from BRAG table
// - Re-embeds (optional, or copies existing vectors)
// - Stores in Qdrant user_documents collection
// - Optionally cleans up BRAG table after verification
```

---

## Fallback Behavior

| Scenario | Behavior |
|----------|----------|
| Qdrant configured but unreachable | Log error, operations fail (no silent fallback) |
| MariaDB configured | Always works (built-in) |
| Invalid provider value | Fall back to `mariadb` with warning |
| Missing QDRANT_SERVICE_URL | Fall back to `mariadb` with warning |
