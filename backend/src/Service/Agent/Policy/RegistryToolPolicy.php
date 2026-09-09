<?php

declare(strict_types=1);

namespace App\Service\Agent\Policy;

use App\Service\Runtime\RuntimeProfile;

/**
 * Track-4 adapter. When `App\Tools\ToolRegistry` exists it evaluates
 * allow/deny against registry names; otherwise it behaves like
 * {@see LegacyFlagToolPolicy} so both adapters stay interchangeable.
 */
final class RegistryToolPolicy implements ToolPolicySourceInterface
{
    public function __construct(
        private readonly LegacyFlagToolPolicy $legacy,
        private readonly ?object $registry = null,
    ) {
    }

    public function flagsFromDefinition(array $tools): array
    {
        return $this->legacy->flagsFromDefinition($tools);
    }

    public function allowedTools(RuntimeProfile $profile): ?array
    {
        $names = $this->registryNames();
        if (null === $names) {
            return $this->legacy->allowedTools($profile);
        }

        $deny = $this->stringList($profile->toolFlags['deny'] ?? []);
        $allow = $this->stringList($profile->toolFlags['allow'] ?? []);
        if ([] === $allow && [] === $deny) {
            return null;
        }

        $universe = [] !== $allow ? $allow : $names;

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

        return $this->legacy->isAllowed($profile, $toolName);
    }

    /**
     * @return list<string>|null
     */
    private function registryNames(): ?array
    {
        if (null === $this->registry || !is_callable([$this->registry, 'names'])) {
            return null;
        }

        $names = $this->registry->names();

        return is_array($names) ? array_values(array_filter($names, 'is_string')) : null;
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
