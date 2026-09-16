<?php

declare(strict_types=1);

namespace App\Service\Tool\Source;

use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Skill\SkillCatalog;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use App\Service\Tool\ToolSourceInterface;

final readonly class SkillToolSource implements ToolSourceInterface
{
    /** Capabilities that change something outside the chat. Compose-reply only
     *  writes the assistant bubble, so it stays read and is not gated. */
    private const WRITE_CAPABILITIES = [
        Capability::EmailMe->value,
        Capability::SaveToFolder->value,
        Capability::CalendarEvent->value,
        Capability::McpAction->value,
    ];

    public static function nameFor(Capability $capability): string
    {
        return 'skill:'.$capability->value;
    }

    public function __construct(
        private SkillCatalog $skillCatalog,
    ) {
    }

    public function source(): ToolSource
    {
        return ToolSource::Skill;
    }

    public function describe(int $userId, array $context = []): array
    {
        $descriptors = [];
        foreach ($this->skillCatalog->descriptors() as $skill) {
            $capability = $skill->capability->value;
            $descriptors[] = new ToolDescriptor(
                name: self::nameFor($skill->capability),
                title: $capability,
                description: $skill->summary,
                inputSchema: ['type' => 'object', 'properties' => []],
                sideEffect: in_array($capability, self::WRITE_CAPABILITIES, true) ? SideEffect::Write : SideEffect::Read,
                source: ToolSource::Skill,
                ownerId: 0,
                meta: ['capability' => $capability],
            );
        }

        return $descriptors;
    }
}
