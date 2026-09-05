<?php

declare(strict_types=1);

namespace App\Service\Plugin;

use App\Service\Iam\Permission;

/**
 * Represents a plugin's manifest.json content.
 */
final readonly class PluginManifest
{
    /**
     * @param string                                                                                      $name          The plugin internal name
     * @param string                                                                                      $version       Version of the plugin
     * @param string                                                                                      $description   Short description
     * @param array<int, string>                                                                          $capabilities  List of features enabled by the plugin
     * @param array<string, mixed>                                                                        $config        Default configuration values
     * @param array<int, array{command: string, endpoint: string, description: string}>                   $chatCommands  Slash-commands this plugin registers in the chat composer
     * @param list<array{key: string, dataType: string, labelKey: string, permissions: list<Permission>}> $resourceKinds Shareable kinds declared in provides.resourceKinds
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $description,
        public array $capabilities = [],
        public array $config = [],
        public array $chatCommands = [],
        public array $resourceKinds = [],
    ) {
    }

    /**
     * Create manifest from array (e.g. from JSON).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = (string) ($data['name'] ?? 'unknown');
        $provides = is_array($data['provides'] ?? null) ? $data['provides'] : [];

        return new self(
            $name,
            $data['version'] ?? '1.0.0',
            $data['description'] ?? '',
            $data['capabilities'] ?? [],
            $data['config'] ?? [],
            self::normalizeChatCommands($data['chatCommands'] ?? []),
            self::normalizeResourceKinds($provides['resourceKinds'] ?? [], $name),
        );
    }

    /**
     * Keep only well-formed chat-command entries, so a malformed manifest can
     * never inject partial/incorrect commands into the composer.
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

    /**
     * @return list<array{key: string, dataType: string, labelKey: string, permissions: list<Permission>}>
     */
    private static function normalizeResourceKinds(mixed $raw, string $pluginId): array
    {
        if (null === $raw) {
            return [];
        }
        if (!is_array($raw)) {
            throw new InvalidPluginManifestException('provides.resourceKinds', 'must be an array');
        }

        $kinds = [];
        foreach ($raw as $index => $entry) {
            $field = sprintf('provides.resourceKinds[%s]', (string) $index);
            if (!is_array($entry)) {
                throw new InvalidPluginManifestException($field, 'must be an object');
            }
            $key = (string) ($entry['key'] ?? '');
            $prefix = $pluginId.':';
            if ('' === $key || !str_starts_with($key, $prefix) || $key === $prefix) {
                throw new InvalidPluginManifestException($field.'.key', 'must be {pluginId}:{name}');
            }
            $dataType = (string) ($entry['dataType'] ?? '');
            if ('' === $dataType) {
                throw new InvalidPluginManifestException($field.'.dataType', 'is required');
            }
            $permissions = [];
            $rawPermissions = $entry['permissions'] ?? [];
            if (!is_array($rawPermissions) || [] === $rawPermissions) {
                throw new InvalidPluginManifestException($field.'.permissions', 'must be a non-empty list');
            }
            foreach ($rawPermissions as $permissionRaw) {
                $permission = is_string($permissionRaw) ? Permission::tryFrom($permissionRaw) : null;
                if (null === $permission) {
                    throw new InvalidPluginManifestException($field.'.permissions', 'must be a subset of read, use, edit, manage');
                }
                $permissions[] = $permission;
            }
            $kinds[] = [
                'key' => $key,
                'dataType' => $dataType,
                'labelKey' => (string) ($entry['labelKey'] ?? $key),
                'permissions' => $permissions,
            ];
        }

        return $kinds;
    }
}
