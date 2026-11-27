<?php

declare(strict_types=1);

namespace App\Service\Project\Item;

use App\Entity\ProjectItem;
use App\Repository\ProjectItemRepository;

/**
 * The JSON shape of project items. An all-day time is a calendar day
 * (`2026-11-30`); any other time is ISO 8601 in UTC. A task's due time is
 * `dueAt`; `startAt` and `endAt` belong to a date.
 */
final readonly class ProjectItemPresenter
{
    public function __construct(private ProjectItemRepository $items)
    {
    }

    /**
     * @param list<ProjectItem> $items
     *
     * @return list<array<string, mixed>>
     */
    public function items(int $projectId, array $items): array
    {
        $parentIds = array_values(array_unique(array_filter(array_map(static fn (ProjectItem $i): ?int => $i->getParentId(), $items))));
        $parents = $this->items->uuidsByIds($projectId, $parentIds);

        return array_map(fn (ProjectItem $item): array => $this->shape($item, $parents), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public function item(ProjectItem $item): array
    {
        return $this->items($item->getProjectId(), [$item])[0];
    }

    /**
     * @param array<int, string> $parents
     *
     * @return array<string, mixed>
     */
    private function shape(ProjectItem $item, array $parents): array
    {
        $isTask = ProjectItem::KIND_TASK === $item->getKind();
        $parentId = $item->getParentId();

        return [
            'id' => $item->getUuid(),
            'kind' => $item->getKind(),
            'state' => $item->getState(),
            'parentId' => null === $parentId ? null : ($parents[$parentId] ?? null),
            'sort' => $item->getSort(),
            'visibility' => $item->getVisibility(),
            'waitingSince' => $item->getWaitingSince()?->format(\DateTimeInterface::ATOM),
            'title' => $item->getTitle(),
            'detail' => $item->getDetail(),
            'startAt' => $isTask ? null : $this->time($item->getStartAt(), $item->isAllDay()),
            'endAt' => $isTask ? null : $this->time($item->getEndAt(), $item->isAllDay()),
            'dueAt' => $isTask ? $this->time($item->getEndAt(), $item->isAllDay()) : null,
            'allDay' => $item->isAllDay(),
            'rrule' => $item->getRrule(),
            'contact' => $item->getContact(),
            'amount' => $item->getAmount(),
            'estimated' => $item->isEstimated(),
            'sourceKind' => $item->getSourceKind(),
            'createdAt' => $item->getCreated()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $item->getUpdated()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function time(?\DateTimeImmutable $at, bool $allDay): ?string
    {
        if (null === $at) {
            return null;
        }
        $utc = $at->setTimezone(new \DateTimeZone('UTC'));

        return $allDay ? $utc->format('Y-m-d') : $utc->format(\DateTimeInterface::ATOM);
    }
}
