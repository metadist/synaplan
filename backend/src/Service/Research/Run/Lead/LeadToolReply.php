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
        $reply = [
            'error' => $code,
            'left' => self::left($budget),
        ];
        $next = 'budget_used' === $code ? self::next($budget) : null;
        if (null !== $next) {
            $reply['next'] = $next;
        }

        return self::json($reply);
    }

    /**
     * @return array{searches: int, sources: int, turns: int}
     */
    private static function left(ResearchBudget $budget): array
    {
        $rounds = $budget->toArray()['rounds'];

        return [
            'searches' => $budget->searchesLeft(),
            'sources' => $budget->sourcesLeft(),
            'turns' => max(0, $rounds[1] - $rounds[0]),
        ];
    }

    private static function next(ResearchBudget $budget): ?string
    {
        if ($budget->searchesLeft() > 0) {
            return null;
        }

        return $budget->sourcesLeft() > 0
            ? 'No search is left. Read sources you already found, or call finish.'
            : 'Nothing is left to search or read. Call finish now.';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
