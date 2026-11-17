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
 * Fails closed: a stored policy that cannot be read, or a stored value that
 * is not one of the three outcomes, makes the affected tools ask (approve)
 * instead of silently falling back to the more permissive default.
 */
final class McpToolPermissions
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
            return SideEffect::Read === $class ? PolicyOutcome::Auto : PolicyOutcome::Approve;
        }
        if (!array_key_exists($tool, $stored)) {
            return $this->defaultFor($class);
        }

        return PolicyOutcome::tryFrom($stored[$tool]) ?? PolicyOutcome::Approve;
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
