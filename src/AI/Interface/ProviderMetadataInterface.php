<?php

namespace App\AI\Interface;

interface ProviderMetadataInterface
{
    /**
     * Provider-Name: 'anthropic', 'openai', 'ollama', 'test'
     */
    public function getName(): string;

    /**
     * Unterstützte Capabilities: ['chat', 'vision', 'embedding', ...]
     */
    public function getCapabilities(): array;

    /**
     * Default-Modelle pro Capability
     */
    public function getDefaultModels(): array;

    /**
     * Provider-Status (Health-Check)
     */
    public function getStatus(): array;

    /**
     * Provider ist verfügbar?
     */
    public function isAvailable(): bool;
}

