<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Repository\ConfigRepository;

final readonly class VectorStorageConfig
{
    private const DEFAULT_PROVIDER = 'mariadb';
    private const ALLOWED_PROVIDERS = ['mariadb', 'qdrant'];

    public function __construct(
        private ConfigRepository $configRepository,
        private ?string $envProvider = null,
        private ?string $qdrantServiceUrl = null,
        private ?string $qdrantApiKey = null,
        private ?string $qdrantDocumentsCollection = null,
    ) {
    }

    public function getProvider(): string
    {
        // 1. Check BCONFIG for runtime override (ownerId=0 = global system setting)
        try {
            $runtimeOverride = $this->configRepository->getValue(0, 'system', 'vector_storage_provider');
            if (null !== $runtimeOverride && in_array($runtimeOverride, self::ALLOWED_PROVIDERS, true)) {
                return $runtimeOverride;
            }
        } catch (\Throwable) {
            // DB may not be available during container compilation
        }

        // 2. Check .env
        if (null !== $this->envProvider && '' !== $this->envProvider
            && in_array($this->envProvider, self::ALLOWED_PROVIDERS, true)) {
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
        return $this->qdrantServiceUrl ?? '';
    }

    public function getQdrantApiKey(): string
    {
        return $this->qdrantApiKey ?? '';
    }

    public function getQdrantDocumentsCollection(): string
    {
        return $this->qdrantDocumentsCollection ?? 'user_documents';
    }
}
