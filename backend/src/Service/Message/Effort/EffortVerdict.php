<?php

declare(strict_types=1);

namespace App\Service\Message\Effort;

use App\AI\StructuredOutput\JsonResponseDecoder;

/**
 * The sorter's effort rating, read from the same `tools:sort` answer that
 * carries the topic and the web-search vote. No second model call.
 *
 * A missing, unknown or unparsable rating is `answer` with `voted = false`,
 * so snapshots and the router eval can tell "no vote" from "voted answer";
 * an install whose sorting model cannot carry the fields simply never
 * researches on its own.
 */
final readonly class EffortVerdict
{
    public const KEY_EFFORT = 'BEFFORT';
    public const KEY_REASON = 'BEFFORT_WHY';

    public function __construct(
        public Effort $effort,
        public EffortReason $reason,
        public bool $voted,
    ) {
    }

    public static function none(): self
    {
        return new self(Effort::Answer, EffortReason::Simple, false);
    }

    /**
     * @param array<string, mixed> $data the decoded sorter JSON
     */
    public static function fromSorterJson(array $data): self
    {
        $effort = Effort::tryFrom(self::lower($data[self::KEY_EFFORT] ?? null));
        if (null === $effort) {
            return self::none();
        }

        return new self($effort, EffortReason::tryFrom(self::lower($data[self::KEY_REASON] ?? null)) ?? EffortReason::defaultFor($effort), true);
    }

    /**
     * Reads the rating from the sorter's raw reply text. The sorter result
     * carries the reply verbatim (`raw_response`) and its own parser does not
     * pass the decoded object through, so the two fields are decoded here
     * with the same tolerant decoder.
     */
    public static function fromRawResponse(?string $raw, ?JsonResponseDecoder $decoder = null): self
    {
        if (null === $raw || '' === trim($raw)) {
            return self::none();
        }
        $decoded = ($decoder ?? new JsonResponseDecoder())->decode($raw);
        if (!$decoded->success || null === $decoded->data) {
            return self::none();
        }

        return self::fromSorterJson($decoded->data);
    }

    /**
     * @param array<string, mixed> $classification
     */
    public static function fromClassification(array $classification): self
    {
        $effort = Effort::tryFrom(self::lower($classification['effort'] ?? null)) ?? Effort::Answer;
        $reason = EffortReason::tryFrom(self::lower($classification['effort_why'] ?? null)) ?? EffortReason::defaultFor($effort);

        return new self($effort, $reason, (bool) ($classification['effort_voted'] ?? false));
    }

    /**
     * The fields as they travel in the classification array beside
     * `web_search` and `read_pages`.
     *
     * @return array{effort: string, effort_why: string, effort_voted: bool}
     */
    public function toClassificationFields(): array
    {
        return [
            'effort' => $this->effort->value,
            'effort_why' => $this->reason->value,
            'effort_voted' => $this->voted,
        ];
    }

    private static function lower(mixed $value): string
    {
        return is_string($value) ? strtolower(trim($value)) : '';
    }
}
