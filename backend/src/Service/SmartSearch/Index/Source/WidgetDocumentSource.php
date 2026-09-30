<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index\Source;

use App\Entity\Widget;
use App\Repository\WidgetRepository;
use App\Service\SmartSearch\Index\ResolvedItem;
use App\Service\SmartSearch\Index\SearchDocument;
use App\Service\SmartSearch\Index\SearchDocumentSourceInterface;

/**
 * Chat widgets by name. The ref id is the public widget id the editor route uses.
 */
final readonly class WidgetDocumentSource implements SearchDocumentSourceInterface
{
    public const KIND = 'widget';
    private const TRACKED_FIELDS = ['name', 'status'];

    public function __construct(
        private WidgetRepository $widgets,
    ) {
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function isEnabledFor(int $userId): bool
    {
        return true;
    }

    public function refFor(object $entity, array $changeSet): ?array
    {
        if (!$entity instanceof Widget) {
            return null;
        }
        if ([] !== $changeSet && [] === array_intersect(self::TRACKED_FIELDS, array_keys($changeSet))) {
            return null;
        }

        return ['userId' => $entity->getOwnerId(), 'refId' => $entity->getWidgetId()];
    }

    public function build(int $userId, string $refId): ?SearchDocument
    {
        $widget = $this->widgets->findOneBy(['widgetId' => $refId, 'ownerId' => $userId]);

        return $widget instanceof Widget ? $this->document($widget) : null;
    }

    public function allForUser(int $userId): iterable
    {
        foreach ($this->widgets->findBy(['ownerId' => $userId]) as $widget) {
            yield $this->document($widget);
        }
    }

    public function resolve(int $userId, array $refIds): array
    {
        if ([] === $refIds) {
            return [];
        }

        $resolved = [];
        foreach ($this->widgets->findBy(['ownerId' => $userId, 'widgetId' => $refIds]) as $widget) {
            $resolved[$widget->getWidgetId()] = new ResolvedItem(
                title: $widget->getName(),
                route: '/channels/widgets/'.rawurlencode($widget->getWidgetId()),
            );
        }

        return $resolved;
    }

    private function document(Widget $widget): SearchDocument
    {
        return new SearchDocument(
            userId: $widget->getOwnerId(),
            kind: self::KIND,
            refId: $widget->getWidgetId(),
            title: $widget->getName(),
            body: '',
            updated: $widget->getUpdated(),
        );
    }
}
