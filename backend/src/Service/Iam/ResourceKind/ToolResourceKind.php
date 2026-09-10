<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Entity\CustomTool;
use App\Repository\CustomToolRepository;
use App\Service\Iam\Exception\ShareNotAllowedException;
use App\Service\Iam\Permission;
use App\Service\Tool\ToolsConfig;

final readonly class ToolResourceKind implements ShareableResourceKindInterface
{
    public const KEY = 'tool';

    public function __construct(
        private CustomToolRepository $tools,
        private ToolsConfig $toolsConfig,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function ownerId(string $resourceId): ?int
    {
        return $this->find($resourceId)?->getOwnerId();
    }

    public function describe(string $resourceId): ResourceCard
    {
        $tool = $this->find($resourceId);
        if (null === $tool) {
            return new ResourceCard($resourceId, $resourceId, 'tool');
        }

        return new ResourceCard(
            (string) $tool->getId(),
            $tool->getTitle(),
            'tool',
            [
                'ownerId' => $tool->getOwnerId(),
                'sideEffect' => $tool->getSideEffect(),
            ],
        );
    }

    public function listOwnedBy(int $userId): iterable
    {
        if (!$this->toolsConfig->isCustomHttpEnabled($userId)) {
            return;
        }
        foreach ($this->tools->findByOwner($userId) as $tool) {
            $id = $tool->getId();
            if (null === $id) {
                continue;
            }
            yield $this->describe((string) $id);
        }
    }

    public function onShareChanged(string $resourceId): void
    {
    }

    public function supportedPermissions(): array
    {
        return [Permission::Read, Permission::Use, Permission::Edit];
    }

    public function assertShareable(string $resourceId): void
    {
        $tool = $this->find($resourceId);
        if (null === $tool) {
            throw new ShareNotAllowedException('This tool cannot be shared');
        }
        if (!$tool->isEnabled()) {
            throw new ShareNotAllowedException('Turn the tool on before sharing it');
        }
    }

    private function find(string $resourceId): ?CustomTool
    {
        if ('' === $resourceId || !ctype_digit($resourceId)) {
            return null;
        }
        $tool = $this->tools->find((int) $resourceId);

        return $tool instanceof CustomTool ? $tool : null;
    }
}
