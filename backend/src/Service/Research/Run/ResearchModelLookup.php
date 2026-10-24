<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

interface ResearchModelLookup
{
    public function slot(int $userId, string $slot): ?ResearchModelChoice;

    public function byKey(int $userId, string $catalogKey): ?ResearchModelChoice;

    public function byId(int $userId, int $id): ?ResearchModelChoice;

    /**
     * @return list<ResearchModelChoice>
     */
    public function verifyCandidates(int $userId): array;
}
