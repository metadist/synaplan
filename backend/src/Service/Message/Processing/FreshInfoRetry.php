<?php

declare(strict_types=1);

namespace App\Service\Message\Processing;

use App\Entity\Message;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Message\Chat\FreshInfoRequest;
use App\Service\Usage\RecordedUsage;
use Psr\Log\LoggerInterface;

/**
 * The second half of a {@see FreshInfoRequest}: the model asked for the web
 * instead of answering, so the turn searches with the model's phrase and
 * dispatches once more with the results. One request per turn — the retry
 * runs without the option, so the model answers from what it has, found
 * or not. The probing call's tokens stay on the turn's usage.
 */
final readonly class FreshInfoRetry
{
    /** Handler metadata: the probing call's usage, shaped like a usage-extra entry. */
    public const USAGE_KEY = 'web_search_request_usage';

    /** The usage-extra kind of the probing call. */
    private const USAGE_KIND = 'LLM';

    public function __construct(
        private TurnWebSearch $webSearch,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed>                              $classification
     * @param array<string, mixed>                              $options
     * @param array<string, mixed>                              $response       the handler result of the first dispatch
     * @param callable(array<string, mixed>): array<string, mixed> $dispatch       runs the handler again with the given options
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null} the final handler result and the search results it was given
     */
    public function apply(Message $message, array $classification, array $options, array $response, TurnStatus $status, callable $dispatch, ?PerfTimer $perfTimer = null): array
    {
        $query = FreshInfoRequest::fromHandlerResult($response);
        if (null === $query) {
            return [$response, null];
        }
        unset($options[FreshInfoRequest::OPTION]);
        $probe = self::probeUsage(is_array($response['metadata'] ?? null) ? $response['metadata'] : []);

        $results = null;
        try {
            $perfTimer?->start('search_requested');
            $results = $this->webSearch->searchRequested($message, $classification, $options, $status, $query, $perfTimer);
        } catch (\Throwable $e) {
            $this->logger->warning('MessageProcessor: the search the model asked for failed; answering without it', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);
        } finally {
            $perfTimer?->stop('search_requested');
        }
        if (null !== $results) {
            $options['search_results'] = $results;
        }

        $final = $dispatch($options);
        if (null !== $probe && is_array($final['metadata'] ?? null)) {
            $final['metadata'][self::USAGE_KEY] = $probe;
        }

        return [$final, $results];
    }

    /**
     * The probing call as a usage-extra entry, or null when the provider
     * reported no tokens.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>|null
     */
    private static function probeUsage(array $metadata): ?array
    {
        $usage = is_array($metadata['usage'] ?? null) ? $metadata['usage'] : [];
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($prompt + $completion));
        if ($total <= 0) {
            return null;
        }
        $cost = $metadata['facade_charged_cost'] ?? null;

        return [
            'promptTokens' => $prompt,
            'completionTokens' => $completion,
            'totalTokens' => $total,
            'cost' => is_string($cost) && '' !== $cost ? $cost : '0',
            'modelKey' => RecordedUsage::modelKey(
                is_string($metadata['provider'] ?? null) ? $metadata['provider'] : null,
                is_string($metadata['model'] ?? null) ? $metadata['model'] : null,
            ),
            'kind' => self::USAGE_KIND,
            'priceKnown' => false !== ($metadata['facade_price_known'] ?? true),
        ];
    }
}
