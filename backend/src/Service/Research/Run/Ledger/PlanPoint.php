<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

final readonly class PlanPoint
{
    public const OPEN = 'open';
    public const THIN = 'thin';
    public const CONFLICTING = 'conflicting';
    public const ANSWERED = 'answered';
    public const SKIPPED = 'skipped';
    public const UNCONFIRMED = 'not_confirmed';

    /** @var list<string> */
    public const STATUSES = [self::OPEN, self::THIN, self::CONFLICTING, self::ANSWERED, self::SKIPPED, self::UNCONFIRMED];

    /**
     * @param list<array{q: string, language: string, scope: string}> $queries
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $status,
        public string $why = '',
        public array $queries = [],
    ) {
    }

    /**
     * @return array{id: string, title: string, status: string, why: string, queries: list<array{q: string, language: string, scope: string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'why' => $this->why,
            'queries' => $this->queries,
        ];
    }
}
