<?php

declare(strict_types=1);

namespace App\Service\Mcp\Permissions;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;

/**
 * Writes a connection's per-tool choices into `BTOOLPOLICY`.
 *
 * Only a choice that differs from the class default is stored, so a tool the
 * owner never touched keeps following the default. Every name is checked
 * against the tools the server really offers; a tool that cannot work through
 * a tool call stays Block and cannot be changed.
 */
final readonly class McpToolPolicyStore
{
    private const MAX_SEEN = 1000;

    public function __construct(
        private McpServerConfigRepository $servers,
        private McpToolPermissions $permissions,
    ) {
    }

    /**
     * @param array<string, PolicyOutcome|null> $changes null puts the tool back to its default
     * @param array<string, SideEffect>         $classes the tools the server offers, name to class
     */
    public function setTools(McpServerConfig $server, array $changes, array $classes): void
    {
        $state = $this->read($server);
        foreach ($changes as $name => $outcome) {
            $name = (string) $name;
            $class = $this->classOf($server, $name, $classes);
            $this->put($state['tools'], $name, $class, $outcome);
        }
        $this->write($server, $state);
    }

    /**
     * @param array<string, SideEffect> $classes
     */
    public function setCategory(McpServerConfig $server, SideEffect $category, ?PolicyOutcome $outcome, array $classes): void
    {
        $state = $this->read($server);
        foreach ($classes as $name => $class) {
            if ($class === $category && !$this->permissions->isUnusable($server, (string) $name)) {
                $this->put($state['tools'], (string) $name, $class, $outcome);
            }
        }
        $this->write($server, $state);
    }

    /**
     * @param list<string> $names
     */
    public function markSeen(McpServerConfig $server, array $names): void
    {
        $state = $this->read($server);
        $state['seen'] = array_slice(array_values(array_unique([...$state['seen'], ...$names])), 0, self::MAX_SEEN);
        $this->write($server, $state);
    }

    /**
     * @return list<string>
     */
    public function seen(McpServerConfig $server): array
    {
        return $this->read($server)['seen'];
    }

    /**
     * @param array<string, SideEffect> $classes
     */
    private function classOf(McpServerConfig $server, string $name, array $classes): SideEffect
    {
        if (!array_key_exists($name, $classes)) {
            throw new McpToolPermissionException(sprintf('Unknown tool "%s"', $name));
        }
        if ($this->permissions->isUnusable($server, $name)) {
            throw new McpToolPermissionException(sprintf('Tool "%s" cannot be changed', $name));
        }

        return $classes[$name];
    }

    /**
     * @param array<string, string> $tools
     */
    private function put(array &$tools, string $name, SideEffect $class, ?PolicyOutcome $outcome): void
    {
        if (null === $outcome || $outcome === $this->permissions->defaultFor($class)) {
            unset($tools[$name]);

            return;
        }
        $tools[$name] = $outcome->value;
    }

    /**
     * @return array{tools: array<string, string>, seen: list<string>}
     */
    private function read(McpServerConfig $server): array
    {
        $decoded = json_decode($server->getToolPolicy(), true);
        $tools = [];
        $seen = [];
        if (is_array($decoded)) {
            foreach (is_array($decoded['tools'] ?? null) ? $decoded['tools'] : [] as $name => $value) {
                if (is_string($value) && null !== PolicyOutcome::tryFrom($value)) {
                    $tools[(string) $name] = $value;
                }
            }
            foreach (is_array($decoded['seen'] ?? null) ? $decoded['seen'] : [] as $name) {
                if (is_string($name)) {
                    $seen[] = $name;
                }
            }
        }

        return ['tools' => $tools, 'seen' => $seen];
    }

    /**
     * @param array{tools: array<string, string>, seen: list<string>} $state
     */
    private function write(McpServerConfig $server, array $state): void
    {
        $server->setToolPolicy(json_encode(['v' => 1, 'tools' => (object) $state['tools'], 'seen' => $state['seen']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->servers->save($server);
    }
}
