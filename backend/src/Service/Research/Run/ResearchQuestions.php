<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * The questions a run is waiting on, and which step continues once they are
 * all answered or skipped. A lead question also names the tool call the
 * answer belongs to.
 */
final class ResearchQuestions
{
    /**
     * @param list<ResearchQuestion> $items
     */
    public function __construct(
        public array $items,
        public string $resumeUnit,
        public string $resumeStatus,
        public ?string $toolCallId = null,
    ) {
    }

    public function anyOpen(): bool
    {
        foreach ($this->items as $item) {
            if ($item->open()) {
                return true;
            }
        }

        return false;
    }

    public function find(string $id): ?ResearchQuestion
    {
        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (ResearchQuestion $item): array => $item->toArray(), $this->items),
            'resume_unit' => $this->resumeUnit,
            'resume_status' => $this->resumeStatus,
            'tool_call_id' => $this->toolCallId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $rows = $data['items'] ?? null;
        if (!is_array($rows)) {
            return null;
        }
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = ResearchQuestion::fromArray($row);
            if (null !== $item) {
                $items[] = $item;
            }
        }
        if ([] === $items) {
            return null;
        }
        $unit = $data['resume_unit'] ?? ResearchUnitPlan::BRIEF_PLAN;
        $status = $data['resume_status'] ?? ResearchStatus::Planning->value;
        $call = $data['tool_call_id'] ?? null;

        return new self(
            $items,
            is_string($unit) && '' !== $unit ? $unit : ResearchUnitPlan::BRIEF_PLAN,
            is_string($status) && '' !== $status ? $status : ResearchStatus::Planning->value,
            is_string($call) && '' !== $call ? $call : null,
        );
    }
}
