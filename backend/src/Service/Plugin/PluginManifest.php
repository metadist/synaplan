<?php

declare(strict_types=1);

namespace App\Service\Plugin;

/**
 * Represents a plugin's manifest.json content.
 */
final readonly class PluginManifest
{
    /**
     * @param string                                                                        $name         The plugin internal name
     * @param string                                                                        $version      Version of the plugin
     * @param string                                                                        $description  Short description
     * @param array<int, string>                                                            $capabilities List of features enabled by the plugin
     * @param array<string, mixed>                                                          $config       Default configuration values
     * @param array<int, array{command: string, endpoint: string, description: string}>    $chatCommands Slash-commands this plugin registers in the chat composer
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $description,
        public array $capabilities = [],
        public array $config = [],
        public array $chatCommands = [],
    ) {
    }

    /**
     * Create manifest from array (e.g. from JSON).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'] ?? 'unknown',
            $data['version'] ?? '1.0.0',
            $data['description'] ?? '',
            $data['capabilities'] ?? [],
            $data['config'] ?? [],
            self::normalizeChatCommands($data['chatCommands'] ?? []),
        );
    }

    /**
     * Keep only well-formed chat-command entries, so a malformed manifest can
     * never inject partial/incorrect commands into the composer.
     *
     * @param mixed $raw
     *
     * @return array<int, array{command: string, endpoint: string, description: string}>
     */
    private static function normalizeChatCommands(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $commands = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $command = ltrim((string) ($entry['command'] ?? ''), '/');
            $endpoint = (string) ($entry['endpoint'] ?? '');
            if ('' === $command || '' === $endpoint) {
                continue;
            }
            $commands[] = [
                'command' => $command,
                'endpoint' => '/' === $endpoint[0] ? $endpoint : '/'.$endpoint,
                'description' => (string) ($entry['description'] ?? ''),
            ];
        }

        return $commands;
    }
}
