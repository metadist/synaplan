<?php

namespace App\AI\Interface;

interface ProviderMetadataInterface
{
    /**
     * Provider-Name: 'anthropic', 'openai', 'ollama', 'test'.
     */
    public function getName(): string;

    /**
     * Display name for UI: 'Anthropic', 'OpenAI', 'Ollama', etc.
     */
    public function getDisplayName(): string;

    /**
     * Short description for UI status page.
     */
    public function getDescription(): string;

    /**
     * Unterstützte Capabilities: ['chat', 'vision', 'embedding', ...].
     */
    public function getCapabilities(): array;

    /**
     * Default-Modelle pro Capability.
     */
    public function getDefaultModels(): array;

    /**
     * Provider-Status (Health-Check)
     * Returns: ['healthy' => bool, 'error' => string|null].
     */
    public function getStatus(): array;

    /**
     * Provider ist verfügbar?
     */
    public function isAvailable(): bool;

    /**
     * Get environment variables required for this provider
     * Returns: ['ENV_VAR_NAME' => ['required' => bool, 'hint' => string]].
     */
    public function getRequiredEnvVars(): array;
}
