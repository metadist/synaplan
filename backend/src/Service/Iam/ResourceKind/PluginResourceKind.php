<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Entity\PluginData;
use App\Repository\PluginDataRepository;
use App\Service\Iam\Permission;

/**
 * Generic adapter for a plugin-declared kind. Resource id is plugin_data.id.
 */
final readonly class PluginResourceKind implements ShareableResourceKindInterface
{
    /**
     * @param list<Permission> $permissions
     */
    public function __construct(
        private string $kindKey,
        private string $dataType,
        private array $permissions,
        private PluginDataRepository $pluginDataRepository,
    ) {
    }

    public function key(): string
    {
        return $this->kindKey;
    }

    public function ownerId(string $resourceId): ?int
    {
        return $this->findRow($resourceId)?->getUserId();
    }

    public function describe(string $resourceId): ResourceCard
    {
        $row = $this->findRow($resourceId);
        if (null === $row) {
            return new ResourceCard($resourceId, $resourceId, 'plugin');
        }
        $data = $row->getData();
        $name = $data['name'] ?? $data['label'] ?? null;
        $label = is_string($name) && '' !== trim($name) ? trim($name) : (string) $row->getId();

        return new ResourceCard(
            (string) $row->getId(),
            $label,
            'plugin',
            ['ownerId' => $row->getUserId(), 'dataType' => $row->getDataType()],
        );
    }

    public function listOwnedBy(int $userId): iterable
    {
        $pluginId = $this->pluginId();
        foreach ($this->pluginDataRepository->findAllByType($userId, $pluginId, $this->dataType) as $row) {
            $data = $row->getData();
            $name = $data['name'] ?? $data['label'] ?? null;
            $label = is_string($name) && '' !== trim($name) ? trim($name) : (string) $row->getId();
            yield new ResourceCard(
                (string) $row->getId(),
                $label,
                'plugin',
                ['ownerId' => $row->getUserId(), 'dataType' => $row->getDataType()],
            );
        }
    }

    public function onShareChanged(string $resourceId): void
    {
    }

    public function supportedPermissions(): array
    {
        return $this->permissions;
    }

    public function dataType(): string
    {
        return $this->dataType;
    }

    private function pluginId(): string
    {
        $pos = strpos($this->kindKey, ':');

        return false === $pos ? $this->kindKey : substr($this->kindKey, 0, $pos);
    }

    private function findRow(string $resourceId): ?PluginData
    {
        if ('' === $resourceId || !ctype_digit($resourceId)) {
            return null;
        }
        $row = $this->pluginDataRepository->find((int) $resourceId);
        if (!$row instanceof PluginData) {
            return null;
        }
        if ($row->getPluginName() !== $this->pluginId() || $row->getDataType() !== $this->dataType) {
            return null;
        }

        return $row;
    }
}
