<?php

declare(strict_types=1);

namespace App\Service\Project\Item;

use App\Entity\ProjectItem;
use App\Repository\ProjectItemRepository;
use App\Service\Project\ProjectException;

/**
 * Writes read fields onto an item and keeps its times consistent: an end
 * never before its start, a date always with a start, and the tasks of a
 * date moved along when the date moves.
 */
final readonly class ProjectItemChanger
{
    private const UTC = 'UTC';

    public function __construct(private ProjectItemRepository $items)
    {
    }

    /**
     * @param array<string, mixed> $fields as returned by ProjectItemInput::read()
     */
    public function apply(ProjectItem $item, array $fields): void
    {
        foreach ($fields as $field => $value) {
            match ($field) {
                'title' => $item->setTitle((string) $value),
                'detail' => $item->setDetail(self::text($value)),
                'contact' => $item->setContact(self::text($value)),
                'state' => $item->setState((string) $value),
                'parentId' => $item->setParentId(\is_int($value) ? $value : null),
                'sort' => $item->setSort(\is_int($value) ? $value : null),
                'startAt' => $item->setStartAt($value instanceof \DateTimeImmutable ? $value : null),
                'endAt' => $item->setEndAt($value instanceof \DateTimeImmutable ? $value : null),
                'allDay' => $item->setAllDay(true === $value),
                'rrule' => $item->setRrule(self::text($value)),
                'amount' => $item->setAmount(self::text($value)),
                'estimated' => $item->setEstimated(true === $value),
                'waiting' => $item->setWaitingSince(true === $value ? ($item->getWaitingSince() ?? new \DateTimeImmutable()) : null),
                default => null,
            };
        }
    }

    public function checkTimes(ProjectItem $item): void
    {
        if (ProjectItem::KIND_DATE === $item->getKind() && null === $item->getStartAt()) {
            throw ProjectException::invalid('a date needs startAt');
        }
        $start = $item->getStartAt();
        $end = $item->getEndAt();
        if (null !== $start && null !== $end && $end < $start) {
            throw ProjectException::invalid('endAt must not be before startAt');
        }
    }

    /**
     * Switching all-day on or off changes what a stored time means, so the
     * times the item has must be sent again with the switch.
     *
     * @param array<string, mixed> $fields
     */
    public function assertAllDayKeepsTimes(ProjectItem $item, array $fields): void
    {
        if (!\array_key_exists('allDay', $fields) || $fields['allDay'] === $item->isAllDay()) {
            return;
        }
        $names = ProjectItem::KIND_TASK === $item->getKind() ? ['endAt' => 'dueAt'] : ['startAt' => 'startAt', 'endAt' => 'endAt'];
        foreach ($names as $field => $name) {
            $has = 'startAt' === $field ? $item->getStartAt() : $item->getEndAt();
            if (null !== $has && !\array_key_exists($field, $fields)) {
                throw ProjectException::invalid('send '.$name.' again when allDay changes');
            }
        }
    }

    /**
     * Moves the due times of the tasks under a date by as much as the date
     * moved: a timed task by the exact time, an all-day task by whole days.
     *
     * @return array<int, array<string, mixed>> child id => its fields before the move
     */
    public function moveChildren(ProjectItem $date, ?\DateTimeImmutable $oldStart, \DateTimeZone $zone): array
    {
        $newStart = $date->getStartAt();
        if (null === $oldStart || null === $newStart || $oldStart == $newStart) {
            return [];
        }
        $seconds = $newStart->getTimestamp() - $oldStart->getTimestamp();
        $days = $this->dayShift($oldStart, $newStart, $date->isAllDay() ? new \DateTimeZone(self::UTC) : $zone);

        $moved = [];
        foreach ($this->items->findChildren($date->getProjectId(), (int) $date->getId()) as $child) {
            $due = $child->getEndAt();
            if (null === $due || ProjectItem::KIND_TASK !== $child->getKind()) {
                continue;
            }
            $before = ProjectItemSnapshot::of($child);
            $child->setEndAt($child->isAllDay() ? $due->modify(\sprintf('%+d days', $days)) : $due->modify(\sprintf('%+d seconds', $seconds)));
            $moved[(int) $child->getId()] = $before;
        }

        return $moved;
    }

    private function dayShift(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $zone): int
    {
        $utc = new \DateTimeZone(self::UTC);
        $a = new \DateTimeImmutable($from->setTimezone($zone)->format('Y-m-d'), $utc);
        $b = new \DateTimeImmutable($to->setTimezone($zone)->format('Y-m-d'), $utc);

        return intdiv($b->getTimestamp() - $a->getTimestamp(), 86400);
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
