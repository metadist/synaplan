<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Recovery;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Prompt\PromptCatalog;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\Exception\StreamCancelledException;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

/**
 * Two ways to widen a search that found nothing, each used at most once.
 *
 * The first asks the SORT model to correct a spelling or name a close
 * synonym. The second is deterministic: keep the strongest term, drop
 * filters the user did not ask for. A suggestion that repeats an attempt
 * is discarded, so the same arguments are never sent twice.
 */
final readonly class QueryRelaxer
{
    private const MAX_TOKENS = 200;

    /** @var list<string> */
    private const QUERY_KEYS = ['query', 'q', 'search', 'search_term', 'title', 'name', 'text', 'prompt'];

    /** @var list<string> */
    private const OPTIONAL_FILTERS = [
        'filter', 'filters', 'sort', 'order', 'direction', 'page_size', 'limit',
        'start_cursor', 'cursor', 'date_range', 'since', 'until', 'status',
        'assignee', 'label', 'tag', 'type',
    ];

    /** @var list<string> */
    private const STOPWORDS = [
        'a', 'an', 'the', 'of', 'in', 'on', 'for', 'to', 'and', 'or', 'my', 'our', 'me',
        'und', 'der', 'die', 'das', 'ein', 'eine', 'fuer', 'für', 'mit', 'von',
        'el', 'la', 'los', 'las', 'un', 'una', 'de', 'en', 'y',
        'le', 'les', 'des', 'et', 'pour',
        'bir', 've', 'icin', 'için', 'bu',
    ];

    public function __construct(
        private AiFacade $aiFacade,
        private PromptRepository $prompts,
        private ModelConfigService $models,
        private UserRepository $users,
        private RateLimitService $rateLimits,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $tried     signatures from {@see signature()}
     *
     * @return array<string, mixed>|null
     */
    public function suggest(string $userText, string $tool, array $arguments, array $tried, ?int $userId): ?array
    {
        $instructions = $this->instructions();
        $modelId = $this->models->getDefaultModel('SORT', $userId);
        if (null === $instructions || null === $modelId) {
            return null;
        }
        $provider = $this->models->getProviderForModel($modelId);
        $modelName = $this->models->getModelName($modelId);
        if (null === $provider || null === $modelName) {
            return null;
        }

        $payload = json_encode([
            'question' => mb_substr($userText, 0, 500),
            'tool' => $tool,
            'arguments' => $arguments,
            'tried' => $tried,
            'keys' => array_keys($arguments),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return null;
        }

        try {
            $response = $this->aiFacade->chat([
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => $payload],
            ], $userId, [
                'provider' => $provider,
                'model' => $modelName,
                'temperature' => 0,
                'max_tokens' => self::MAX_TOKENS,
                'disable_thinking' => true,
            ]);
            $this->record($userId, $modelId, $response);
        } catch (StreamCancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->info('QueryRelaxer: suggestion skipped', ['error' => $e->getMessage()]);

            return null;
        }

        return $this->accept($arguments, $this->decode((string) ($response['content'] ?? '')), $tried);
    }

    /**
     * Strongest term only, and drop filters the user did not name.
     *
     * @param array<string, mixed> $arguments
     * @param list<string>         $tried
     *
     * @return array<string, mixed>|null
     */
    public function broaden(array $arguments, string $userText, array $tried): ?array
    {
        $next = $arguments;
        $changed = false;
        foreach ($arguments as $key => $value) {
            if (in_array($key, self::QUERY_KEYS, true) && is_string($value)) {
                $term = $this->strongestTerm($value);
                if ('' !== $term && $term !== $value) {
                    $next[$key] = $term;
                    $changed = true;
                }
            }
            if (in_array($key, self::OPTIONAL_FILTERS, true) && !$this->userAskedFor($value, $userText)) {
                unset($next[$key]);
                $changed = true;
            }
        }
        if (!$changed || in_array(self::signature($next), $tried, true)) {
            return null;
        }

        return $next;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function signature(array $arguments): string
    {
        $encoded = json_encode(self::sortKeys($arguments), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @param array<string, mixed> $original
     * @param array<string, mixed> $decoded
     * @param list<string>         $tried
     *
     * @return array<string, mixed>|null
     */
    private function accept(array $original, array $decoded, array $tried): ?array
    {
        if (true === ($decoded['give_up'] ?? false)) {
            return null;
        }
        $next = $decoded['arguments'] ?? null;
        if (!is_array($next)) {
            return null;
        }

        $clean = [];
        foreach ($next as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (!array_key_exists($key, $original) && !in_array($key, self::QUERY_KEYS, true)) {
                continue;
            }
            $clean[$key] = $value;
        }
        if ([] === $clean || in_array(self::signature($clean), $tried, true)) {
            return null;
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        $raw = trim($raw);
        $raw = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw);
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function strongestTerm(string $query): string
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, \PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens)) {
            return trim($query);
        }

        $best = '';
        foreach ($tokens as $token) {
            if (mb_strlen($token) < 3) {
                continue;
            }
            if (in_array(mb_strtolower($token), self::STOPWORDS, true)) {
                continue;
            }
            if (mb_strlen($token) > mb_strlen($best)) {
                $best = $token;
            }
        }

        return '' !== $best ? $best : trim($query);
    }

    private function userAskedFor(mixed $value, string $userText): bool
    {
        $haystack = mb_strtolower($userText);
        foreach ($this->stringsOf($value) as $needle) {
            if (mb_strlen($needle) >= 3 && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function stringsOf(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return '' === $trimmed ? [] : [$trimmed];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            foreach ($this->stringsOf($item) as $needle) {
                $out[] = $needle;
            }
        }

        return $out;
    }

    private function instructions(): ?string
    {
        try {
            $row = $this->prompts->findByTopic('tools:query_relax', 0);
        } catch (\Throwable) {
            $row = null;
        }
        if (null !== $row && '' !== trim($row->getPrompt())) {
            return $row->getPrompt();
        }
        foreach (PromptCatalog::all() as $entry) {
            if ('tools:query_relax' === $entry['topic']) {
                return $entry['prompt'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function record(?int $userId, int $modelId, array $response): void
    {
        if (null === $userId || $userId <= 0) {
            return;
        }

        try {
            $user = $this->users->find($userId);
            if (!$user instanceof User) {
                return;
            }
            \App\Service\Usage\LegacyUsageWriter::record($this->rateLimits, $user, 'PLANNING', [
                'usage' => $response['usage'] ?? [],
                'model_id' => $modelId,
                'provider' => $response['provider'] ?? '',
                'model' => $response['model'] ?? '',
                'input_text' => '',
                'response_text' => is_string($response['content'] ?? null) ? $response['content'] : '',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('QueryRelaxer: failed to record usage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => is_array($item) ? self::sortKeys($item) : $item, $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }
}
