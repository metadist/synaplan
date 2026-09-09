<?php

declare(strict_types=1);

namespace App\Service\Agent\Policy;

use App\Service\Runtime\RuntimeProfile;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Default tool policy: maps agent.v1 `tools.*` onto the prompt-meta flags
 * ChatHandler / MessageProcessor / the gateway loops already honour.
 */
#[AsAlias(id: ToolPolicySourceInterface::class)]
final class LegacyFlagToolPolicy implements ToolPolicySourceInterface
{
    public const KNOWN_TOOLS = [
        'web_search',
        'rag_search',
        'mcp_fetch',
        'mcp_action',
    ];

    public function flagsFromDefinition(array $tools): array
    {
        $flags = [
            'tool_internet' => (bool) ($tools['internet'] ?? true),
            'tool_files' => (bool) ($tools['files'] ?? true),
        ];
        $servers = $tools['mcpServers'] ?? [];
        if (is_array($servers) && [] !== $servers) {
            $flags['tool_mcp'] = true;
            $flags['mcp_servers'] = array_values(array_map('intval', $servers));
        }
        $allow = $this->stringList($tools['allow'] ?? []);
        $deny = $this->stringList($tools['deny'] ?? []);
        if ([] !== $allow) {
            $flags['allow'] = $allow;
        }
        if ([] !== $deny) {
            $flags['deny'] = $deny;
        }

        return $flags;
    }

    public function allowedTools(RuntimeProfile $profile): ?array
    {
        $deny = $this->stringList($profile->toolFlags['deny'] ?? []);
        $allow = $this->stringList($profile->toolFlags['allow'] ?? []);
        if ([] === $allow && [] === $deny) {
            return null;
        }

        $universe = [] !== $allow ? $allow : self::KNOWN_TOOLS;
        if ([] === $deny) {
            return $universe;
        }

        return array_values(array_filter(
            $universe,
            static fn (string $name): bool => !in_array($name, $deny, true),
        ));
    }

    public function isAllowed(RuntimeProfile $profile, string $toolName): bool
    {
        $deny = $this->stringList($profile->toolFlags['deny'] ?? []);
        if (in_array($toolName, $deny, true)) {
            return false;
        }

        $allow = $this->stringList($profile->toolFlags['allow'] ?? []);
        if ([] !== $allow) {
            return in_array($toolName, $allow, true);
        }

        return match ($toolName) {
            'web_search' => (bool) ($profile->toolFlags['tool_internet'] ?? true),
            'rag_search' => (bool) ($profile->toolFlags['tool_files'] ?? true),
            'mcp_fetch', 'mcp_action' => (bool) ($profile->toolFlags['tool_mcp'] ?? false),
            default => true,
        };
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($item): bool => is_string($item) && '' !== $item));
    }
}
