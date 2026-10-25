<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\Service\Research\Run\ResearchBudget;

/** JSON a tool returns to the lead. A refusal names the reason and what is left. */
final class LeadToolReply
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function ok(array $payload, ResearchBudget $budget): string
    {
        $payload['left'] = self::left($budget);

        return self::json($payload);
    }

    public static function error(string $code, ResearchBudget $budget): string
    {
        return self::json([
            'error' => $code,
            'left' => self::left($budget),
        ]);
    }

    /**
     * @return array{searches: int, sources: int, rounds: int}
     */
    private static function left(ResearchBudget $budget): array
    {
        $rounds = $budget->toArray()['rounds'];

        return [
            'searches' => $budget->searchesLeft(),
            'sources' => $budget->sourcesLeft(),
            'rounds' => max(0, $rounds[1] - $rounds[0]),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
