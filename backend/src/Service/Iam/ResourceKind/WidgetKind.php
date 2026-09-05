<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Entity\Widget;
use App\Repository\WidgetRepository;
use App\Service\Iam\Permission;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Widget identity is BWIDGETS.BID. Shareable permissions: read, edit, manage.
 * Public embed routes never consult this kind.
 */
final readonly class WidgetKind implements ShareableResourceKindInterface
{
    public const KEY = 'widget';
    public const LIST_CACHE_PREFIX = 'iam.widget_list.';

    public function __construct(
        private WidgetRepository $widgetRepository,
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function ownerId(string $resourceId): ?int
    {
        return $this->findWidget($resourceId)?->getOwnerId();
    }

    public function describe(string $resourceId): ResourceCard
    {
        $widget = $this->findWidget($resourceId);
        if (null === $widget) {
            return new ResourceCard($resourceId, $resourceId, 'widget');
        }

        return new ResourceCard(
            (string) $widget->getId(),
            $widget->getName(),
            'widget',
            [
                'ownerId' => $widget->getOwnerId(),
                'widgetId' => $widget->getWidgetId(),
            ],
        );
    }

    public function listOwnedBy(int $userId): iterable
    {
        foreach ($this->widgetRepository->findByOwnerId($userId) as $widget) {
            yield new ResourceCard(
                (string) $widget->getId(),
                $widget->getName(),
                'widget',
                [
                    'ownerId' => $widget->getOwnerId(),
                    'widgetId' => $widget->getWidgetId(),
                ],
            );
        }
    }

    public function onShareChanged(string $resourceId): void
    {
        $widget = $this->findWidget($resourceId);
        if (null === $widget) {
            return;
        }
        $this->cache->deleteItem(self::LIST_CACHE_PREFIX.$widget->getOwnerId());
    }

    public function supportedPermissions(): array
    {
        return [Permission::Read, Permission::Edit, Permission::Manage];
    }

    private function findWidget(string $resourceId): ?Widget
    {
        if ('' === $resourceId || !ctype_digit($resourceId)) {
            return null;
        }
        $widget = $this->widgetRepository->find((int) $resourceId);

        return $widget instanceof Widget ? $widget : null;
    }
}
