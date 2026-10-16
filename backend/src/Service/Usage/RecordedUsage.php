<?php

declare(strict_types=1);

namespace App\Service\Usage;

use App\Entity\Message;

/**
 * Immutable result of {@see \App\Service\RateLimitService::recordUsage()}.
 *
 * Carries the token counts and both cost figures for the row that was just
 * written to BUSELOG, so the caller (e.g. StreamController) can surface the
 * charged per-message cost live in the SSE `complete` event without a second
 * DB round-trip. Costs are decimal strings (6 dp) for lossless transport.
 *
 * - rawCost:     the provider cost as stored in BUSELOG.BCOST.
 * - chargedCost: rawCost + operator markup (what the user is billed), i.e.
 *                consistent with the /statistics cost budget.
 */
final readonly class RecordedUsage
{
    public function __construct(
        public string $chargedCost,
        public string $rawCost,
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public bool $priceKnown = true,
    ) {
    }

    /**
     * Build the canonical message-usage shape shared by live SSE events and
     * persisted message metadata.
     *
     * @return array{promptTokens: int, completionTokens: int, totalTokens: int, cost: string, modelKey: string, kind: string, priceKnown: bool}
     */
    public function toMessageUsage(?string $provider, ?string $model, string $kind): array
    {
        return [
            'promptTokens' => $this->promptTokens,
            'completionTokens' => $this->completionTokens,
            'totalTokens' => $this->totalTokens,
            'cost' => $this->chargedCost,
            'modelKey' => self::modelKey($provider, $model),
            'kind' => $kind,
            'priceKnown' => $this->priceKnown,
        ];
    }

    /**
     * Persist the charged cost and, when the model published no price, a flag
     * the history API reads back. Absent flag means the price was known.
     */
    public function attachChatCost(Message $message): void
    {
        $message->setMeta('ai_chat_cost', $this->chargedCost);
        if (!$this->priceKnown) {
            $message->setMeta('ai_chat_price_known', '0');
        }
    }

    /**
     * Turn a sorter payload into the usage-extra row every channel stores.
     *
     * @param array<string, mixed> $sortingUsage
     *
     * @return array{promptTokens: int, completionTokens: int, totalTokens: int, cost: string, modelKey: string, kind: string, priceKnown: bool}
     */
    public static function fromSortingUsage(array $sortingUsage, ?string $provider, ?string $model): array
    {
        $known = $sortingUsage['price_known'] ?? true;

        return [
            'promptTokens' => (int) ($sortingUsage['prompt_tokens'] ?? 0),
            'completionTokens' => (int) ($sortingUsage['completion_tokens'] ?? 0),
            'totalTokens' => (int) ($sortingUsage['tokens'] ?? 0),
            'cost' => (string) ($sortingUsage['cost'] ?? '0'),
            'modelKey' => self::modelKey($provider, $model),
            'kind' => 'SORT',
            'priceKnown' => false !== $known,
        ];
    }

    /**
     * Compose a stable provider/model key for session grouping.
     */
    public static function modelKey(?string $provider, ?string $model): string
    {
        $normalizedProvider = strtolower(trim((string) $provider));
        $normalizedModel = trim((string) $model);
        if ('' !== $normalizedProvider && '' !== $normalizedModel) {
            return $normalizedProvider.':'.$normalizedModel;
        }

        return '' !== $normalizedModel
            ? $normalizedModel
            : ('' !== $normalizedProvider ? $normalizedProvider : 'unknown');
    }
}
