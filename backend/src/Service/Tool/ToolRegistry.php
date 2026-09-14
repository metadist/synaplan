<?php

declare(strict_types=1);

namespace App\Service\Tool;

use App\Service\Tool\Exception\DuplicateToolNameException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ToolRegistry
{
    /**
     * @param iterable<ToolSourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('app.tool.source')]
        private iterable $sources,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<ToolDescriptor>
     */
    public function forUser(int $userId, array $context = []): array
    {
        $byName = [];
        $byCallName = [];
        foreach ($this->sources as $source) {
            foreach ($source->describe($userId, $context) as $descriptor) {
                if (isset($byName[$descriptor->name])) {
                    throw new DuplicateToolNameException($descriptor->name);
                }
                $callName = $descriptor->callName();
                if ($callName !== $descriptor->name && isset($byCallName[$callName])) {
                    throw new DuplicateToolNameException($callName);
                }
                $byName[$descriptor->name] = $descriptor;
                $byCallName[$callName] = $descriptor;
            }
        }

        return array_values($byName);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function get(int $userId, string $name, array $context = []): ?ToolDescriptor
    {
        foreach ($this->forUser($userId, $context) as $descriptor) {
            if ($descriptor->name === $name || $descriptor->callName() === $name || $this->matchesNamedMcp($descriptor, $name)) {
                return $descriptor;
            }
        }

        return null;
    }

    private function matchesNamedMcp(ToolDescriptor $descriptor, string $name): bool
    {
        if (1 !== preg_match('/^mcp:([^:]+):(.+)$/', $name, $wanted) || ctype_digit($wanted[1])) {
            return false;
        }
        $serverName = $descriptor->meta['serverName'] ?? null;
        $tool = $descriptor->meta['tool'] ?? null;

        return is_string($serverName) && $serverName === $wanted[1]
            && is_string($tool) && $tool === $wanted[2];
    }

    /**
     * Universe of registered names (no user filter). Used by
     * {@see \App\Service\Agent\Policy\RegistryToolPolicy}.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->forUser(0) as $descriptor) {
            $names[] = $descriptor->name;
        }

        return array_values(array_unique($names));
    }
}
