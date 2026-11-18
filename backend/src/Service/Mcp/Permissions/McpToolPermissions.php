<?php

declare(strict_types=1);

namespace App\Service\Mcp\Permissions;

use App\Entity\McpServerConfig;
use App\Service\Mcp\Notion\NotionToolHints;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;

/**
 * The connection's own choice per tool: auto, approve or block.
 *
 * `outcome = override[tool] ?? default(class)`. Tools the owner never touched
 * follow the class default (read and write auto, destructive approve). A tool
 * that cannot work through a tool call is block and cannot be changed.
 *
 * Fails closed: a stored policy that cannot be read makes every tool of the
 * connection ask (approve), read tools included, because it may hide a Block.
 * A stored value that is not one of the three outcomes makes that tool ask
 * instead of silently falling back to the more permissive default.
 */
final readonly class McpToolPermissions
{
    public function defaultFor(SideEffect $class): PolicyOutcome
    {
        return SideEffect::Destructive === $class ? PolicyOutcome::Approve : PolicyOutcome::Auto;
    }

    public function isUnusable(McpServerConfig $server, string $tool): bool
    {
        return NotionToolHints::isUnusable($server->getUrl(), $tool);
    }

    public function outcome(McpServerConfig $server, string $tool, SideEffect $class): PolicyOutcome
    {
        if ($this->isUnusable($server, $tool)) {
            return PolicyOutcome::Block;
        }
        $stored = $this->stored($server);
        if (null === $stored) {
            return PolicyOutcome::Approve;
        }
        if (!array_key_exists($tool, $stored)) {
            return $this->defaultFor($class);
        }

        return PolicyOutcome::tryFrom($stored[$tool]) ?? PolicyOutcome::Approve;
    }

    /**
     * Outcome for a catalog entry, classed by the server's own hints.
     *
     * @param array{name: string, annotations: array<string, mixed>} $tool
     */
    public function outcomeForTool(McpServerConfig $server, array $tool): PolicyOutcome
    {
        return $this->outcome($server, $tool['name'], self::classOf($tool['annotations']));
    }

    /**
     * True when the owner set this tool to Block. Needs no class, because no
     * class defaults to Block. Unusable tools are not counted: the app itself
     * still runs them for file transfers, only the AI may not.
     */
    public function isBlockedByOwner(McpServerConfig $server, string $tool): bool
    {
        return PolicyOutcome::Block === ($this->overrides($server)[$tool] ?? null);
    }

    /**
     * @param array<string, mixed> $annotations
     */
    public static function classOf(array $annotations): SideEffect
    {
        return SideEffect::fromHints(
            isset($annotations['readOnlyHint']) ? (bool) $annotations['readOnlyHint'] : null,
            isset($annotations['destructiveHint']) ? (bool) $annotations['destructiveHint'] : null,
        );
    }

    /**
     * The explicit overrides, name to outcome. Values that are not a valid
     * outcome are dropped here; {@see outcome()} treats them as approve.
     *
     * @return array<string, PolicyOutcome>
     */
    public function overrides(McpServerConfig $server): array
    {
        $overrides = [];
        foreach ($this->stored($server) ?? [] as $tool => $value) {
            $outcome = PolicyOutcome::tryFrom($value);
            if (null !== $outcome) {
                $overrides[$tool] = $outcome;
            }
        }

        return $overrides;
    }

    /**
     * @return array<string, string>|null null when the stored policy is unreadable
     */
    private function stored(McpServerConfig $server): ?array
    {
        $raw = trim($server->getToolPolicy());
        if ('' === $raw || '{}' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $tools = $decoded['tools'] ?? [];
        if (!is_array($tools)) {
            return null;
        }
        $stored = [];
        foreach ($tools as $name => $value) {
            $stored[(string) $name] = is_string($value) ? $value : '';
        }

        return $stored;
    }
}
