<?php

declare(strict_types=1);

namespace App\Service\Project\Item;

use App\Entity\ProjectItem;
use App\Service\Project\ProjectException;

/**
 * Reads the fields of a project item from a request body. Each kind takes
 * only its own fields; an unknown or foreign field is refused, so a typo
 * never passes silently. Times without a zone are read in the project's
 * zone and returned in UTC; a calendar day ("2026-11-30") is an all-day
 * time at midnight UTC.
 *
 * The result holds only the fields the body sent. A task's `dueAt` is
 * returned as `endAt`.
 */
final class ProjectItemInput
{
    public const TITLE_MAX = 200;
    public const DETAIL_MAX = 1000;
    public const CONTACT_MAX = 200;
    private const AMOUNT = '/^\d{1,10}(\.\d{1,2})?$/';
    private const DAY = '/^\d{4}-\d{2}-\d{2}$/';
    private const ZONED = '/(Z|[+-]\d{2}:?\d{2})$/i';
    private const MOMENT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?$/i';
    private const YEAR_MIN = 1970;
    private const YEAR_MAX = 2100;
    private const SORT_MAX = 100000;

    /** The fields each kind takes; `state` only on a change. */
    private const FIELDS = [
        ProjectItem::KIND_TASK => ['title', 'detail', 'state', 'parentId', 'sort', 'dueAt', 'allDay', 'contact', 'waiting'],
        ProjectItem::KIND_DATE => ['title', 'detail', 'state', 'sort', 'startAt', 'endAt', 'allDay', 'rrule', 'contact'],
        ProjectItem::KIND_DECISION => ['title', 'detail', 'state'],
        ProjectItem::KIND_COST => ['title', 'detail', 'state', 'parentId', 'amount', 'estimated'],
        ProjectItem::KIND_NOTE => ['title', 'detail', 'state'],
    ];

    /** @var array<string, string> */
    private const REQUIRED = [
        ProjectItem::KIND_DATE => 'startAt',
        ProjectItem::KIND_COST => 'amount',
    ];

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function read(array $body, string $kind, \DateTimeZone $zone, bool $create): array
    {
        $allowed = self::FIELDS[$kind] ?? null;
        if (null === $allowed) {
            throw ProjectException::invalid('kind must be one of: '.implode(', ', ProjectItem::KINDS));
        }
        if ($create) {
            $allowed = array_values(array_diff($allowed, ['state']));
            foreach (['title', self::REQUIRED[$kind] ?? 'title'] as $needed) {
                if (!\array_key_exists($needed, $body)) {
                    throw ProjectException::invalid($needed.' is required for a '.$kind);
                }
            }
        }
        $foreign = array_diff(array_keys($body), $allowed);
        if ([] !== $foreign) {
            throw ProjectException::invalid('a '.$kind.' does not take: '.implode(', ', $foreign));
        }

        $out = [];
        foreach ($body as $field => $value) {
            $out = [...$out, ...$this->field((string) $field, $value, $kind)];
        }

        return $this->times($body, $out, $zone);
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $field, mixed $value, string $kind): array
    {
        return match ($field) {
            'title' => ['title' => $this->title($value)],
            'detail' => ['detail' => $this->text($value, self::DETAIL_MAX, 'detail', true)],
            'contact' => ['contact' => $this->text($value, self::CONTACT_MAX, 'contact', false)],
            'state' => ['state' => $this->state($value, $kind)],
            'parentId' => ['parentId' => $this->uuidOrNull($value)],
            'sort' => ['sort' => $this->sort($value)],
            'allDay' => ['allDay' => $this->bool($value, 'allDay')],
            'waiting' => ['waiting' => $this->bool($value, 'waiting')],
            'estimated' => ['estimated' => $this->bool($value, 'estimated')],
            'rrule' => ['rrule' => null === $value ? null : RecurrenceRule::normalize($this->string($value, 'rrule'))],
            'amount' => ['amount' => $this->amount($value)],
            default => [],
        };
    }

    /**
     * Reads the time fields and settles all-day: a calendar day makes an
     * all-day time, a time of day does not; mixing them is refused.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $out
     *
     * @return array<string, mixed>
     */
    private function times(array $body, array $out, \DateTimeZone $zone): array
    {
        $map = ['startAt' => 'startAt', 'endAt' => 'endAt', 'dueAt' => 'endAt'];
        $days = [];
        foreach ($map as $field => $target) {
            if (!\array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if (null === $value) {
                $out[$target] = null;
                continue;
            }
            $text = trim($this->string($value, $field));
            $days[$field] = 1 === preg_match(self::DAY, $text);
            $out[$target] = $this->time($text, $days[$field], $zone, $field);
        }
        if ([] === $days) {
            return $out;
        }
        $allDay = $out['allDay'] ?? \in_array(true, $days, true);
        foreach ($days as $field => $isDay) {
            if ($isDay !== $allDay) {
                throw ProjectException::invalid($allDay ? $field.' must be a day (YYYY-MM-DD) for an all-day item' : $field.' needs a time of day unless allDay is true');
            }
        }
        $out['allDay'] = $allDay;

        return $out;
    }

    private function time(string $text, bool $isDay, \DateTimeZone $zone, string $field): \DateTimeImmutable
    {
        if (!$isDay && 1 !== preg_match(self::MOMENT, $text)) {
            throw ProjectException::invalid($field.' is not an ISO 8601 time');
        }
        $utc = new \DateTimeZone('UTC');
        try {
            $at = $isDay
                ? new \DateTimeImmutable($text.' 00:00:00', $utc)
                : new \DateTimeImmutable($text, 1 === preg_match(self::ZONED, $text) ? null : $zone);
        } catch (\Exception) {
            throw ProjectException::invalid($field.' is not an ISO 8601 time');
        }
        // A day that does not exist (2026-02-31) rolls over in PHP; refuse it instead.
        $written = $isDay ? $at->format('Y-m-d') : $at->format('Y-m-d\TH:i');
        $year = (int) $at->format('Y');
        if ($written !== substr($text, 0, $isDay ? 10 : 16) || $year < self::YEAR_MIN || $year > self::YEAR_MAX) {
            throw ProjectException::invalid($field.' is out of range');
        }

        return $at->setTimezone($utc);
    }

    private function title(mixed $value): string
    {
        $title = $this->text($value, self::TITLE_MAX, 'title', false);
        if (null === $title) {
            throw ProjectException::invalid('title must not be empty');
        }

        return $title;
    }

    private function text(mixed $value, int $max, string $field, bool $multiline): ?string
    {
        if (null === $value) {
            return null;
        }
        $text = $this->string($value, $field);
        // Control characters never belong in a board line; a detail keeps its line breaks.
        $text = (string) preg_replace($multiline ? '/[^\P{Cc}\n]/u' : '/\p{Cc}/u', $multiline ? '' : ' ', $text);
        $text = trim($text);
        if (mb_strlen($text) > $max) {
            throw ProjectException::invalid($field.' is longer than '.$max.' characters');
        }

        return '' === $text ? null : $text;
    }

    private function state(mixed $value, string $kind): string
    {
        $states = ProjectItem::KIND_NOTE === $kind
            ? [ProjectItem::STATE_OPEN, ProjectItem::STATE_DROPPED]
            : [ProjectItem::STATE_OPEN, ProjectItem::STATE_DONE, ProjectItem::STATE_DROPPED];
        if (!\is_string($value) || !\in_array($value, $states, true)) {
            throw ProjectException::invalid('state of a '.$kind.' must be one of: '.implode(', ', $states));
        }

        return $value;
    }

    private function uuidOrNull(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            throw ProjectException::invalid('parentId must be an item id');
        }

        return strtolower($value);
    }

    private function sort(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }
        if (!\is_int($value) || $value < 0 || $value > self::SORT_MAX) {
            throw ProjectException::invalid('sort must be a whole number from 0 to '.self::SORT_MAX);
        }

        return $value;
    }

    private function amount(mixed $value): string
    {
        if (\is_int($value)) {
            $value = (string) $value;
        } elseif (\is_float($value)) {
            $value = round($value, 2) === $value ? number_format($value, 2, '.', '') : 'x';
        }
        $value = \is_string($value) ? trim($value) : '';
        if (1 !== preg_match(self::AMOUNT, $value)) {
            throw ProjectException::invalid('amount must be a number from 0 with at most two decimals');
        }
        [$whole, $cents] = array_pad(explode('.', $value, 2), 2, '');

        return ((string) (int) $whole).'.'.str_pad($cents, 2, '0');
    }

    private function bool(mixed $value, string $field): bool
    {
        if (!\is_bool($value)) {
            throw ProjectException::invalid($field.' must be true or false');
        }

        return $value;
    }

    private function string(mixed $value, string $field): string
    {
        if (!\is_string($value)) {
            throw ProjectException::invalid($field.' must be text');
        }

        return $value;
    }
}
