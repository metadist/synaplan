<?php

declare(strict_types=1);

namespace App\Service\Agent\Policy;

use App\Service\Runtime\RuntimeProfile;

/**
 * Resolves which tools an assistant may call. The runtime depends on this
 * port only; adapters map either today's prompt-meta flags or (later) the
 * track-4 tool registry.
 */
interface ToolPolicySourceInterface
{
    /**
     * @return list<string>|null tool names the assistant may call; null = unrestricted
     */
    public function allowedTools(RuntimeProfile $profile): ?array;

    public function isAllowed(RuntimeProfile $profile, string $toolName): bool;

    /**
     * Prompt-meta-shaped flags written onto {@see RuntimeProfile::$toolFlags}.
     *
     * @param array<string, mixed> $tools agent.v1 tools section
     *
     * @return array<string, mixed>
     */
    public function flagsFromDefinition(array $tools): array;
}
