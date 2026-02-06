# 01 - Configuration System

## Overview

Vector storage provider selection via `.env` with optional runtime override in `BCONFIG`.

---

## Environment Variables

### synaplan backend (.env)

```env
###> Vector Storage ###
# Provider: 'mariadb' (default) or 'qdrant'
VECTOR_STORAGE_PROVIDER=mariadb

# Qdrant service connection (required if using qdrant provider)
# Note: QDRANT_SERVICE_URL may already exist for memories - reuse it
QDRANT_SERVICE_URL=http://memories-service:8090
QDRANT_SERVICE_API_KEY=changeme-in-production

# Collection name for documents (separate from memories collection)
QDRANT_DOCUMENTS_COLLECTION=user_documents
###< Vector Storage ###
```

### qdrant-service (.env)

```env
###> Existing ###
QDRANT_URL=http://qdrant:6334
QDRANT_COLLECTION_NAME=user_memories
SERVICE_API_KEY=your-api-key
VECTOR_SIZE=1024
###< Existing ###

###> New for Documents ###
# Documents collection - created automatically on startup
QDRANT_DOCUMENTS_COLLECTION_NAME=user_documents
###< New for Documents ###
```

---

## Configuration Hierarchy

Priority order (highest to lowest):

1. **Runtime Override** - `BCONFIG` table (BGROUP='SYSTEM', BSETTING='vector_storage_provider')
2. **Environment Variable** - `VECTOR_STORAGE_PROVIDER` in `.env`
3. **Default** - `mariadb`

```php
// VectorStorageConfig.php
final readonly class VectorStorageConfig
{
    private const DEFAULT_PROVIDER = 'mariadb';
    private const ALLOWED_PROVIDERS = ['mariadb', 'qdrant'];

    public function __construct(
        private ConfigService $configService,
        private string $envProvider,
        private string $qdrantServiceUrl,
        private string $qdrantApiKey,
        private string $qdrantDocumentsCollection,
    ) {}

    public function getProvider(): string
    {
        // 1. Check BCONFIG for runtime override
        $runtimeOverride = $this->configService->getSystemSetting('vector_storage_provider');
        if ($runtimeOverride && in_array($runtimeOverride, self::ALLOWED_PROVIDERS, true)) {
            return $runtimeOverride;
        }

        // 2. Check .env
        if (in_array($this->envProvider, self::ALLOWED_PROVIDERS, true)) {
            return $this->envProvider;
        }

        // 3. Default
        return self::DEFAULT_PROVIDER;
    }

    public function isQdrantEnabled(): bool
    {
        return $this->getProvider() === 'qdrant';
    }

    public function getQdrantServiceUrl(): string
    {
        return $this->qdrantServiceUrl;
    }

    public function getQdrantApiKey(): string
    {
        return $this->qdrantApiKey;
    }

    public function getQdrantDocumentsCollection(): string
    {
        return $this->qdrantDocumentsCollection;
    }
}
```

---

## Symfony Service Configuration

### services.yaml

```yaml
# Vector Storage Configuration
App\Service\RAG\VectorStorage\VectorStorageConfig:
    arguments:
        $envProvider: '%env(default::VECTOR_STORAGE_PROVIDER)%'
        $qdrantServiceUrl: '%env(default::QDRANT_SERVICE_URL)%'
        $qdrantApiKey: '%env(default::QDRANT_SERVICE_API_KEY)%'
        $qdrantDocumentsCollection: '%env(default:user_documents:QDRANT_DOCUMENTS_COLLECTION)%'

# Vector Storage Facade (replaces direct VectorizationService/VectorSearchService usage)
App\Service\RAG\VectorStorage\VectorStorageFacade:
    arguments:
        $mariaDbStorage: '@App\Service\RAG\VectorStorage\MariaDBVectorStorage'
        $qdrantStorage: '@App\Service\RAG\VectorStorage\QdrantVectorStorage'
        $config: '@App\Service\RAG\VectorStorage\VectorStorageConfig'
```

---

## BCONFIG Table Usage

Runtime override for admin configuration:

```sql
-- Set system-wide vector storage provider
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE, BUPDATED)
VALUES (0, 'SYSTEM', 'vector_storage_provider', 'qdrant', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE BVALUE = 'qdrant', BUPDATED = UNIX_TIMESTAMP();

-- Clear override (fall back to .env)
DELETE FROM BCONFIG
WHERE BOWNERID = 0 AND BGROUP = 'SYSTEM' AND BSETTING = 'vector_storage_provider';
```

---

## Admin UI Configuration

Add to System Settings page:

```vue
<!-- frontend/src/views/admin/SystemSettings.vue -->
<template>
  <div class="space-y-6">
    <!-- Existing settings... -->

    <!-- Vector Storage Section -->
    <section class="border rounded-lg p-6 dark:border-gray-700">
      <h3 class="text-lg font-semibold mb-4">{{ $t('admin.vectorStorage.title') }}</h3>

      <div class="space-y-4">
        <!-- Provider Selection -->
        <div>
          <label class="block text-sm font-medium mb-2">
            {{ $t('admin.vectorStorage.provider') }}
          </label>
          <select
            v-model="vectorProvider"
            class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800"
          >
            <option value="mariadb">MariaDB Vector (Default)</option>
            <option value="qdrant">Qdrant (via Memories Service)</option>
          </select>
          <p class="text-sm text-gray-500 mt-1">
            {{ $t('admin.vectorStorage.providerHelp') }}
          </p>
        </div>

        <!-- Qdrant Connection Test -->
        <div v-if="vectorProvider === 'qdrant'" class="space-y-3">
          <div class="flex items-center gap-3">
            <button
              @click="testQdrantConnection"
              class="btn-secondary"
              :disabled="testingConnection"
            >
              {{ testingConnection ? $t('common.testing') : $t('admin.vectorStorage.testConnection') }}
            </button>
            <span v-if="connectionStatus" :class="connectionStatusClass">
              {{ connectionStatus }}
            </span>
          </div>

          <!-- Connection Details (read-only, from .env) -->
          <div class="text-sm text-gray-500">
            <p>{{ $t('admin.vectorStorage.serviceUrl') }}: {{ qdrantServiceUrl || $t('common.notConfigured') }}</p>
            <p>{{ $t('admin.vectorStorage.collection') }}: {{ qdrantCollection }}</p>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
```

---

## API Endpoints for Admin

```php
// backend/src/Controller/Admin/SystemSettingsController.php

#[Route('/api/v1/admin/vector-storage/status', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
public function getVectorStorageStatus(): JsonResponse
{
    $config = $this->vectorStorageConfig;

    $status = [
        'current_provider' => $config->getProvider(),
        'env_provider' => $_ENV['VECTOR_STORAGE_PROVIDER'] ?? 'mariadb',
        'runtime_override' => $this->configService->getSystemSetting('vector_storage_provider'),
        'qdrant_configured' => !empty($config->getQdrantServiceUrl()),
    ];

    // Test Qdrant connection if configured
    if ($status['qdrant_configured']) {
        try {
            $health = $this->qdrantClient->health();
            $status['qdrant_status'] = 'connected';
            $status['qdrant_collection_exists'] = $this->qdrantClient->collectionExists(
                $config->getQdrantDocumentsCollection()
            );
        } catch (\Exception $e) {
            $status['qdrant_status'] = 'error';
            $status['qdrant_error'] = $e->getMessage();
        }
    }

    return $this->json($status);
}

#[Route('/api/v1/admin/vector-storage/provider', methods: ['PUT'])]
#[IsGranted('ROLE_ADMIN')]
public function setVectorStorageProvider(Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true);
    $provider = $data['provider'] ?? null;

    if (!in_array($provider, ['mariadb', 'qdrant'], true)) {
        return $this->json(['error' => 'Invalid provider'], Response::HTTP_BAD_REQUEST);
    }

    // Validate Qdrant is available before switching
    if ($provider === 'qdrant') {
        if (!$this->qdrantClient->isAvailable()) {
            return $this->json([
                'error' => 'Qdrant service is not available',
                'details' => 'Configure QDRANT_SERVICE_URL in .env and ensure the service is running'
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    // Store in BCONFIG
    $this->configService->setSystemSetting('vector_storage_provider', $provider);

    return $this->json([
        'success' => true,
        'provider' => $provider,
    ]);
}

#[Route('/api/v1/admin/vector-storage/test-qdrant', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
public function testQdrantConnection(): JsonResponse
{
    try {
        $health = $this->qdrantClient->health();
        $collectionInfo = $this->qdrantClient->getCollectionInfo(
            $this->vectorStorageConfig->getQdrantDocumentsCollection()
        );

        return $this->json([
            'success' => true,
            'status' => 'connected',
            'collection' => $collectionInfo,
        ]);
    } catch (\Exception $e) {
        return $this->json([
            'success' => false,
            'status' => 'error',
            'error' => $e->getMessage(),
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
```

---

## Default Configuration Strategy

**Conservative default:** MariaDB remains default to avoid breaking existing installations.

```php
// If no configuration is set, system uses MariaDB
// Admin must explicitly enable Qdrant after:
// 1. Deploying qdrant-service (synaplan-memories)
// 2. Configuring QDRANT_SERVICE_URL
// 3. Testing connection via admin UI
// 4. Switching provider via admin UI or .env
```

---

## Configuration Validation

On application startup:

```php
// VectorStorageConfigValidator.php
final class VectorStorageConfigValidator implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'onKernelRequest'];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $provider = $this->config->getProvider();

        if ($provider === 'qdrant') {
            // Validate Qdrant is configured
            if (empty($this->config->getQdrantServiceUrl())) {
                $this->logger->warning(
                    'Vector storage set to qdrant but QDRANT_SERVICE_URL not configured. Falling back to mariadb.'
                );
                // Could auto-fallback or throw exception based on strictness preference
            }
        }
    }
}
```

---

## i18n Keys

```json
// frontend/src/i18n/en.json
{
  "admin": {
    "vectorStorage": {
      "title": "Vector Storage",
      "provider": "Storage Provider",
      "providerHelp": "Choose where to store document vectors for RAG search. MariaDB is built-in, Qdrant requires the memories service.",
      "testConnection": "Test Connection",
      "serviceUrl": "Service URL",
      "collection": "Collection",
      "connected": "Connected",
      "error": "Connection Error"
    }
  }
}
```

```json
// frontend/src/i18n/de.json
{
  "admin": {
    "vectorStorage": {
      "title": "Vektorspeicher",
      "provider": "Speicheranbieter",
      "providerHelp": "Wählen Sie, wo Dokumentvektoren für die RAG-Suche gespeichert werden. MariaDB ist integriert, Qdrant erfordert den Memories-Service.",
      "testConnection": "Verbindung testen",
      "serviceUrl": "Service-URL",
      "collection": "Sammlung",
      "connected": "Verbunden",
      "error": "Verbindungsfehler"
    }
  }
}
```
