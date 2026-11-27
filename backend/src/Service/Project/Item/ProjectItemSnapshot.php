<?php

declare(strict_types=1);

namespace App\Service\Project\Item;

use App\Entity\ProjectItem;

/**
 * The changeable fields of an item as plain values, for the `before` of a
 * log row. Times are ISO 8601 in UTC.
 */
final class ProjectItemSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function of(ProjectItem $item): array
    {
        return [
            'state' => $item->getState(),
            'parentId' => $item->getParentId(),
            'sort' => $item->getSort(),
            'waitingSince' => self::time($item->getWaitingSince()),
            'title' => $item->getTitle(),
            'detail' => $item->getDetail(),
            'startAt' => self::time($item->getStartAt()),
            'endAt' => self::time($item->getEndAt()),
            'allDay' => $item->isAllDay(),
            'rrule' => $item->getRrule(),
            'contact' => $item->getContact(),
            'amount' => $item->getAmount(),
            'estimated' => $item->isEstimated(),
        ];
    }

    private static function time(?\DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
