<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

final readonly class PlanPoint
{
    public const OPEN = 'open';
    public const ANSWERED = 'answered';
    public const SKIPPED = 'skipped';
    public const UNCONFIRMED = 'not_confirmed';

    /** @var list<string> */
    public const STATUSES = [self::OPEN, self::ANSWERED, self::SKIPPED, self::UNCONFIRMED];

    public function __construct(
        public string $id,
        public string $title,
        public string $status,
    ) {
    }

    /**
     * @return array{id: string, title: string, status: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
        ];
    }
}
