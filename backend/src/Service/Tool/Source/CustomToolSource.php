<?php

declare(strict_types=1);

namespace App\Service\Tool\Source;

use App\Entity\CustomTool;
use App\Repository\CustomToolRepository;
use App\Repository\ShareRepository;
use App\Service\Iam\ResourceKind\ToolResourceKind;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use App\Service\Tool\ToolSourceInterface;
use App\Service\Tool\ToolsConfig;

final readonly class CustomToolSource implements ToolSourceInterface
{
    public function __construct(
        private CustomToolRepository $tools,
        private ToolsConfig $toolsConfig,
        private ?ShareRepository $shares = null,
    ) {
    }

    public function source(): ToolSource
    {
        return ToolSource::Custom;
    }

    public function describe(int $userId, array $context = []): array
    {
        if ($userId < 1 || !$this->toolsConfig->isCustomHttpEnabled($userId)) {
            return [];
        }

        $owned = $this->tools->findEnabledByOwner($userId);
        $sharedIds = [];
        if (null !== $this->shares) {
            foreach ($this->shares->findForSubjects($userId, [], ToolResourceKind::KEY) as $share) {
                $resourceId = $share->getResourceId();
                if (ctype_digit($resourceId)) {
                    $sharedIds[] = (int) $resourceId;
                }
            }
        }
        $shared = $this->tools->findEnabledByIds($sharedIds);
        $descriptors = [];
        $seen = [];
        foreach ([...$owned, ...$shared] as $tool) {
            $id = (int) $tool->getId();
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $descriptors[] = $this->toDescriptor($tool, $userId);
        }

        return $descriptors;
    }

    private function toDescriptor(CustomTool $tool, int $userId): ToolDescriptor
    {
        $sideEffect = SideEffect::tryFrom($tool->getSideEffect()) ?? SideEffect::Write;
        $schema = $tool->getInputSchema() ?? ['type' => 'object', 'properties' => []];

        return new ToolDescriptor(
            name: $tool->registryName(),
            title: $tool->getTitle(),
            description: (string) $tool->getDescription(),
            inputSchema: $schema,
            sideEffect: $sideEffect,
            source: ToolSource::Custom,
            ownerId: $tool->getOwnerId(),
            meta: [
                'toolId' => $tool->getId(),
                'shared' => $tool->getOwnerId() !== $userId,
            ],
        );
    }
}
