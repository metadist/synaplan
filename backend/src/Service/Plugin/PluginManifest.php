<?php

declare(strict_types=1);

namespace App\Service\Plugin;

use App\Plug\PlugPorts;
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
     * @param array<int, array{command: string, endpoint: string, description: string, tool?: array{sideEffect?: string}|null}> $chatCommands  Slash-commands this plugin registers in the chat composer
     * @param list<array{key: string, dataType: string, labelKey: string, permissions: list<Permission>}> $resourceKinds Shareable kinds declared in provides.resourceKinds
     * @param list<array{port: string, class: string, key: string}>                                       $plugs         Plug adapters declared in provides.plugs
     * @param list<string>                                                                                $agentPacks    Relative bundle globs declared in provides.agents
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $description,
        public array $capabilities = [],
        public array $config = [],
        public array $chatCommands = [],
        public array $resourceKinds = [],
        public array $plugs = [],
        public array $agentPacks = [],
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
            self::normalizePlugs($provides['plugs'] ?? null, self::namespaceOf($data, $name)),
            self::normalizeAgentPacks($provides['agents'] ?? null),
        );
    }

    /**
     * The PHP namespace a plugin's classes live under — explicit in the
     * manifest, or derived the same way {@see \App\Kernel} derives it.
     *
     * @param array<string, mixed> $data
     */
    private static function namespaceOf(array $data, string $name): string
    {
        $explicit = $data['namespace'] ?? null;
        if (is_string($explicit) && '' !== trim($explicit)) {
            return $explicit;
        }
        $id = (string) ($data['id'] ?? $name);

        return 'Plugin\\'.ucfirst($id);
    }

    /**
     * Validate `provides.plugs`: a known port, a class under the plugin's own
     * namespace, and a well-formed key. A manifest without `provides.plugs` is
     * valid (back-compat). Rejecting here is what lets the boot-time check
     * assume every declaration is structurally sound.
     *
     * @return list<array{port: string, class: string, key: string}>
     */
    private static function normalizePlugs(mixed $raw, string $namespace): array
    {
        if (null === $raw) {
            return [];
        }
        if (!is_array($raw)) {
            throw new InvalidPluginManifestException('provides.plugs', 'must be an array');
        }

        $prefix = rtrim($namespace, '\\').'\\';
        $plugs = [];
        foreach ($raw as $index => $entry) {
            $field = sprintf('provides.plugs[%s]', (string) $index);
            if (!is_array($entry)) {
                throw new InvalidPluginManifestException($field, 'must be an object');
            }

            $port = (string) ($entry['port'] ?? '');
            if (!PlugPorts::isValidPort($port)) {
                throw new InvalidPluginManifestException($field.'.port', 'must be one of: '.implode(', ', array_keys(PlugPorts::TAG_BY_PORT)));
            }

            $class = (string) ($entry['class'] ?? '');
            if ('' === $class || !str_starts_with($class, $prefix)) {
                throw new InvalidPluginManifestException($field.'.class', 'must be a class under the plugin namespace '.$prefix);
            }

            $key = (string) ($entry['key'] ?? '');
            if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/', $key)) {
                throw new InvalidPluginManifestException($field.'.key', 'must be lowercase letters, digits, "-" or "_"');
            }

            $plugs[] = ['port' => $port, 'class' => $class, 'key' => $key];
        }

        return $plugs;
    }

    /**
     * Relative JSON globs such as `agents/*.json`. Reject path traversal.
     *
     * @return list<string>
     */
    private static function normalizeAgentPacks(mixed $raw): array
    {
        if (null === $raw) {
            return [];
        }
        if (!is_array($raw)) {
            throw new InvalidPluginManifestException('provides.agents', 'must be an array');
        }

        $packs = [];
        foreach ($raw as $index => $entry) {
            $field = sprintf('provides.agents[%s]', (string) $index);
            if (!is_string($entry) || '' === trim($entry)) {
                throw new InvalidPluginManifestException($field, 'must be a relative *.json glob');
            }
            $path = str_replace('\\', '/', trim($entry));
            if (str_starts_with($path, '/') || str_contains($path, '..')) {
                throw new InvalidPluginManifestException($field, 'must stay inside the plugin directory');
            }
            if (!str_ends_with($path, '.json')) {
                throw new InvalidPluginManifestException($field, 'must end with .json');
            }
            $packs[] = $path;
        }

        return $packs;
    }

    /**
     * Keep only well-formed chat-command entries, so a malformed manifest can
     * never inject partial/incorrect commands into the composer.
     *
     * @return array<int, array{command: string, endpoint: string, description: string, tool?: array{sideEffect?: string}|null}>
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
            $tool = null;
            if (is_array($entry['tool'] ?? null)) {
                $sideEffect = $entry['tool']['sideEffect'] ?? null;
                $tool = is_string($sideEffect) && '' !== $sideEffect
                    ? ['sideEffect' => $sideEffect]
                    : $entry['tool'];
            }
            $commands[] = [
                'command' => $command,
                'endpoint' => '/' === $endpoint[0] ? $endpoint : '/'.$endpoint,
                'description' => (string) ($entry['description'] ?? ''),
                'tool' => $tool,
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
