<?php

declare(strict_types=1);

namespace App\Service\SmartSearch;

use App\Entity\User;
use App\Service\SmartSearch\Index\FulltextQuery;

final readonly class SearchRequest
{
    public FulltextQuery $fulltext;

    /**
     * @param list<string> $kinds the kinds the caller asked for (already narrowed to known kinds)
     */
    public function __construct(
        public User $user,
        public string $query,
        public array $kinds,
        public int $limit,
        public QueryVectors $vectors,
    ) {
        $this->fulltext = new FulltextQuery($query);
    }

    public function userId(): int
    {
        return (int) $this->user->getId();
    }

    public function wants(string $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }
}
