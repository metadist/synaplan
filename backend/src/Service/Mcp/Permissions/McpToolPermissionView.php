<?php

declare(strict_types=1);

namespace App\Service\Mcp\Permissions;

use App\Entity\McpServerConfig;
use App\Service\Tool\Policy\GroupPolicyProviderInterface;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;

/**
 * The permission list of one connection, as the Permissions tab shows it.
 */
final readonly class McpToolPermissionView
{
    public function __construct(
        private McpToolPermissions $permissions,
        private McpToolPolicyStore $store,
        private GroupPolicyProviderInterface $groupPolicy,
    ) {
    }

    /**
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}> $tools
     *
     * @return array<string, SideEffect> name to class
     */
    public function classes(array $tools): array
    {
        $classes = [];
        foreach ($tools as $tool) {
            $classes[$tool['name']] = $this->classOf($tool['annotations']);
        }

        return $classes;
    }

    /**
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}> $tools
     *
     * @return list<array<string, mixed>>
     */
    public function rows(McpServerConfig $server, int $userId, array $tools, bool $shared): array
    {
        $seen = $this->store->seen($server);
        $rows = [];
        foreach ($tools as $tool) {
            $rows[] = $this->row($server, $userId, $tool, $shared, $seen);
        }

        return $rows;
    }

    /**
     * @param array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>} $tool
     * @param list<string>                                                                                                   $seen
     *
     * @return array<string, mixed>
     */
    private function row(McpServerConfig $server, int $userId, array $tool, bool $shared, array $seen): array
    {
        $name = $tool['name'];
        $annotations = $tool['annotations'];
        $class = $this->classOf($annotations);
        $unusable = $this->permissions->isUnusable($server, $name);
        $properties = is_array($tool['inputSchema']['properties'] ?? null) ? array_keys($tool['inputSchema']['properties']) : [];
        $outcome = $this->permissions->outcome($server, $name, $class);
        $default = $unusable ? PolicyOutcome::Block : $this->permissions->defaultFor($class);

        return [
            'name' => $name,
            'title' => $name,
            'description' => $tool['description'],
            'input_count' => count($properties),
            'input_names' => array_map('strval', $properties),
            'category' => $unusable ? 'unavailable' : $class->value,
            'outcome' => $outcome->value,
            'default_outcome' => $default->value,
            'hinted' => array_key_exists('readOnlyHint', $annotations) || array_key_exists('destructiveHint', $annotations),
            'is_new' => !$shared && !in_array($name, $seen, true),
            'locked_reason' => $this->lockedReason($server, $userId, $name, $class, $unusable, $shared),
        ];
    }

    private function lockedReason(McpServerConfig $server, int $userId, string $name, SideEffect $class, bool $unusable, bool $shared): ?string
    {
        if ($unusable) {
            return 'unavailable';
        }
        if ($shared) {
            return 'shared';
        }
        $descriptor = new ToolDescriptor(
            name: sprintf('mcp:%d:%s', (int) $server->getId(), $name),
            title: $name,
            description: '',
            inputSchema: [],
            sideEffect: $class,
            source: ToolSource::Mcp,
            ownerId: $userId,
        );

        return PolicyOutcome::Block === $this->groupPolicy->outcomeFor($userId, $descriptor, $class) ? 'group_policy' : null;
    }

    /**
     * @param array<string, mixed> $annotations
     */
    private function classOf(array $annotations): SideEffect
    {
        return SideEffect::fromHints(
            isset($annotations['readOnlyHint']) ? (bool) $annotations['readOnlyHint'] : null,
            isset($annotations['destructiveHint']) ? (bool) $annotations['destructiveHint'] : null,
        );
    }
}
