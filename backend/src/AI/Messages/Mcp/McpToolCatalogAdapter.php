<?php

declare(strict_types=1);

namespace App\AI\Messages\Mcp;

use App\Service\Mcp\McpToolRegistry;

/**
 * Maps {@see McpToolRegistry::catalogForUser()} to Anthropic `tools[]`.
 *
 * Names are namespaced `mcp__{serverId}__{tool}` (Claude Code convention) so
 * tools stay unique across servers. Anthropic constrains names to
 * `^[a-zA-Z0-9_-]{1,128}$` — sanitise and clamp.
 *
 * @phpstan-type AnthropicTool array{
 *     name: string,
 *     description: string,
 *     input_schema: array<string, mixed>
 * }
 * @phpstan-type DispatchEntry array{
 *     serverId: int,
 *     tool: string,
 *     annotations: array<string, mixed>
 * }
 * @phpstan-type CatalogSnapshot array{
 *     tools: list<AnthropicTool>,
 *     dispatch: array<string, DispatchEntry>
 * }
 */
final readonly class McpToolCatalogAdapter
{
    public const NAME_PREFIX = 'mcp__';
    private const MAX_NAME_LEN = 128;

    public function __construct(
        private McpToolRegistry $toolRegistry,
    ) {
    }

    /**
     * Build a deterministic Anthropic tools list + reverse dispatch map.
     * Sorted by namespaced name so the cache prefix is session-stable.
     *
     * @return CatalogSnapshot
     */
    public function toAnthropicTools(int $userId, bool $includeMutating = false): array
    {
        /** @var array<string, array{tool: AnthropicTool, dispatch: DispatchEntry}> $byName */
        $byName = [];

        foreach ($this->toolRegistry->catalogForUser($userId) as $entry) {
            $server = $entry['server'];
            $serverId = (int) $server->getId();
            if ($serverId <= 0 || !$server->isEnabled()) {
                continue;
            }

            foreach ($entry['tools'] as $tool) {
                if (!$includeMutating && $this->isMutatingTool($tool['annotations'])) {
                    continue;
                }

                $namespaced = $this->namespace($serverId, $tool['name']);
                $schema = $tool['inputSchema'];
                if ([] === $schema) {
                    $schema = ['type' => 'object', 'properties' => []];
                } elseif (!isset($schema['type'])) {
                    $schema['type'] = 'object';
                }

                $byName[$namespaced] = [
                    'tool' => [
                        'name' => $namespaced,
                        'description' => $tool['description'],
                        'input_schema' => $schema,
                    ],
                    'dispatch' => [
                        'serverId' => $serverId,
                        'tool' => $tool['name'],
                        'annotations' => $tool['annotations'],
                    ],
                ];
            }
        }

        ksort($byName, \SORT_STRING);

        $tools = [];
        $dispatch = [];
        foreach ($byName as $name => $row) {
            $tools[] = $row['tool'];
            $dispatch[$name] = $row['dispatch'];
        }

        return ['tools' => $tools, 'dispatch' => $dispatch];
    }

    public function namespace(int $serverId, string $toolName): string
    {
        $sanitizedTool = preg_replace('/[^a-zA-Z0-9_-]/', '_', $toolName) ?? 'tool';
        $name = self::NAME_PREFIX.$serverId.'__'.$sanitizedTool;
        if (strlen($name) > self::MAX_NAME_LEN) {
            $name = substr($name, 0, self::MAX_NAME_LEN);
        }

        return $name;
    }

    /**
     * @return array{serverId: int, tool: string}|null
     */
    public function parseNamespaced(string $name): ?array
    {
        if (!str_starts_with($name, self::NAME_PREFIX)) {
            return null;
        }

        $rest = substr($name, strlen(self::NAME_PREFIX));
        $parts = explode('__', $rest, 2);
        if (2 !== count($parts) || !is_numeric($parts[0]) || '' === $parts[1]) {
            return null;
        }

        return [
            'serverId' => (int) $parts[0],
            'tool' => $parts[1],
        ];
    }

    public function isOurs(string $toolName): bool
    {
        return str_starts_with($toolName, self::NAME_PREFIX);
    }

    /**
     * @param array<string, mixed> $annotations
     */
    public function isMutatingTool(array $annotations): bool
    {
        if (false === ($annotations['readOnlyHint'] ?? null)) {
            return true;
        }

        return true === ($annotations['destructiveHint'] ?? null);
    }
}
