<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Recovery;

use App\Service\Multitask\Execution\NodeResult;

/**
 * A read that succeeded and found nothing. That is not a failure: the call
 * worked, and a second identical call would not. Real failures (auth, a tool
 * that does not exist, a server error) stay failures and are never retried.
 */
final class EmptyResultDetector
{
    /** @var list<string> */
    private const LIST_KEYS = ['results', 'items', 'data', 'hits', 'pages', 'issues', 'values'];

    /** @var list<string> */
    private const COUNT_KEYS = ['total', 'count', 'total_count', 'results_count'];

    private const PHRASE_MAX_CHARS = 240;

    public function isEmpty(NodeResult $result): bool
    {
        if ($result->isSuccessful()) {
            return $this->successfulIsEmpty($result);
        }

        // A blank tool body is reported as a failure ("returned no content")
        // even though the call itself worked. Treat that one sentence as empty.
        return $result->isReportableFailure()
            && str_contains(strtolower((string) $result->error), 'returned no content');
    }

    private function successfulIsEmpty(NodeResult $result): bool
    {
        if (0 === ($result->metadata['results_count'] ?? null)) {
            return true;
        }

        $search = $result->metadata['search_results'] ?? null;
        if (is_array($search) && is_array($search['results'] ?? null) && [] === $search['results']) {
            return true;
        }

        $text = trim((string) $result->text);
        if ('' === $text) {
            return true;
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded) && $this->jsonIsEmpty($decoded)) {
            return true;
        }

        return $this->phraseIsEmpty($text);
    }

    /**
     * @param array<mixed> $decoded
     */
    private function jsonIsEmpty(array $decoded): bool
    {
        if (array_is_list($decoded)) {
            return [] === $decoded;
        }

        $sawList = false;
        foreach (self::LIST_KEYS as $key) {
            if (!array_key_exists($key, $decoded) || !is_array($decoded[$key])) {
                continue;
            }
            $sawList = true;
            if ([] !== $decoded[$key]) {
                return false;
            }
        }
        if ($sawList) {
            return true;
        }

        foreach (self::COUNT_KEYS as $key) {
            $count = $decoded[$key] ?? null;
            if (0 === $count || 0.0 === $count) {
                return true;
            }
        }

        return false;
    }

    private function phraseIsEmpty(string $text): bool
    {
        if (mb_strlen($text) > self::PHRASE_MAX_CHARS) {
            return false;
        }

        return 1 === preg_match(
            '/\b(no results|0 results|nothing found|no matches|keine ergebnisse|nichts gefunden|returned no content|no emails matching)\b/iu',
            $text,
        );
    }
}
