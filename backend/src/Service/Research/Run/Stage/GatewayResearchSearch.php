<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Plug\WebSearch\WebSearchGateway;

/** Synaplan's own web search. A provider error is an empty list, not a failed run. */
final readonly class GatewayResearchSearch implements ResearchSearch
{
    public function __construct(private WebSearchGateway $gateway)
    {
    }

    public function search(string $query, string $language, int $limit, int $userId): array
    {
        try {
            $payload = $this->gateway->search($query, [
                'count' => $limit,
                'language' => $language,
            ], $userId);
        } catch (\Throwable) {
            return [];
        }

        $rows = $payload['results'] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        $hits = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = is_string($row['url'] ?? null) ? $row['url'] : '';
            $title = is_scalar($row['title'] ?? null) ? (string) $row['title'] : '';
            $snippet = is_scalar($row['description'] ?? null) ? (string) $row['description'] : '';
            $date = is_scalar($row['age'] ?? null) ? (string) $row['age'] : null;
            $hit = ResearchHit::from($url, $title, $snippet, $date);
            if ($hit instanceof ResearchHit) {
                $hits[] = $hit;
            }
            if (count($hits) >= $limit) {
                break;
            }
        }

        return $hits;
    }
}
