<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Service\ConfigService;

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
    ) {
    }

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
        return 'qdrant' === $this->getProvider();
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
