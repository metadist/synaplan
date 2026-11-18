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
 *
 * A write never loosens what applies now: a stored value that is not one of
 * the three outcomes, or a policy that cannot be read at all, makes the tool
 * ask ({@see McpToolPermissions::outcome()}); that Approve is written out
 * explicitly instead of being dropped back to the more permissive default.
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
        $state = $this->read($server, $classes);
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
        $state = $this->read($server, $classes);
        foreach ($classes as $name => $class) {
            if ($class === $category && !$this->permissions->isUnusable($server, (string) $name)) {
                $this->put($state['tools'], (string) $name, $class, $outcome);
            }
        }
        $this->write($server, $state);
    }

    /**
     * Marks every tool in `$classes` as seen. The newest names are kept when
     * the list would grow past its cap.
     *
     * @param array<string, SideEffect> $classes the tools the server offers, name to class
     */
    public function markSeen(McpServerConfig $server, array $classes): void
    {
        $state = $this->read($server, $classes);
        $names = array_map('strval', array_keys($classes));
        $state['seen'] = array_slice(array_values(array_unique([...array_diff($state['seen'], $names), ...$names])), -self::MAX_SEEN);
        $this->write($server, $state);
    }

    /**
     * @return list<string>
     */
    public function seen(McpServerConfig $server): array
    {
        return $this->read($server, [])['seen'];
    }

    /**
     * True while the one-time "writes no longer ask" notice is still open.
     */
    public function hasNotice(McpServerConfig $server): bool
    {
        return $this->read($server, [])['notice'];
    }

    /**
     * Closes the notice. Nothing is written when it is not open.
     */
    public function dismissNotice(McpServerConfig $server): void
    {
        $state = $this->read($server, []);
        if (!$state['notice']) {
            return;
        }
        $state['notice'] = false;
        $this->write($server, $state);
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
     * @param array<string, SideEffect> $classes
     *
     * @return array{tools: array<string, string>, seen: list<string>, notice: bool}
     */
    private function read(McpServerConfig $server, array $classes): array
    {
        $raw = trim($server->getToolPolicy());
        $decoded = '' === $raw ? [] : json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['tools'] ?? [])) {
            return ['tools' => $this->askingNow($server, $classes), 'seen' => [], 'notice' => false];
        }
        $tools = [];
        foreach ($decoded['tools'] ?? [] as $name => $value) {
            $outcome = is_string($value) ? PolicyOutcome::tryFrom($value) : null;
            $tools[(string) $name] = ($outcome ?? PolicyOutcome::Approve)->value;
        }
        $seen = [];
        foreach (is_array($decoded['seen'] ?? null) ? $decoded['seen'] : [] as $name) {
            if (is_string($name)) {
                $seen[] = $name;
            }
        }

        return ['tools' => $tools, 'seen' => $seen, 'notice' => true === ($decoded['notice'] ?? null)];
    }

    /**
     * What an unreadable policy means today, written out: every tool that is
     * not a read tool asks.
     *
     * @param array<string, SideEffect> $classes
     *
     * @return array<string, string>
     */
    private function askingNow(McpServerConfig $server, array $classes): array
    {
        $tools = [];
        foreach ($classes as $name => $class) {
            $name = (string) $name;
            if (SideEffect::Read !== $class && !$this->permissions->isUnusable($server, $name)) {
                $this->put($tools, $name, $class, PolicyOutcome::Approve);
            }
        }

        return $tools;
    }

    /**
     * @param array{tools: array<string, string>, seen: list<string>, notice: bool} $state
     */
    private function write(McpServerConfig $server, array $state): void
    {
        $policy = ['v' => 1, 'tools' => (object) $state['tools'], 'seen' => $state['seen']];
        if ($state['notice']) {
            $policy['notice'] = true;
        }
        $server->setToolPolicy(json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->servers->save($server);
    }
}
