<?php

declare(strict_types=1);

namespace App\Service\Plugin;

/**
 * Represents a plugin's manifest.json content.
 */
final readonly class PluginManifest
{
    /**
     * @param string $name         The plugin internal name
     * @param string $version      Version of the plugin
     * @param string $description  Short description
     * @param array  $capabilities List of features enabled by the plugin
     * @param array  $config       Default configuration values
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $description,
        public array $capabilities = [],
        public array $config = [],
    ) {
    }

    /**
     * Create manifest from array (e.g. from JSON).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'] ?? 'unknown',
            $data['version'] ?? '1.0.0',
            $data['description'] ?? '',
            $data['capabilities'] ?? [],
            $data['config'] ?? [],
        );
    }
}
