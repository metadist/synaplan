<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\Model;

/**
 * The price of the run's model in what the person is billed, markup
 * included: micro-dollars per character sent, and for an answer of
 * {@see ANSWER_TOKENS}. Fixed when the run starts, so an estimate never
 * needs the catalog.
 */
final readonly class ChatRunRates
{
    /** The answer length a wrap-up is assumed to write. */
    public const ANSWER_TOKENS = 2_000;

    private const CHARS_PER_TOKEN = 4;
    private const MICRO = 1_000_000;

    public function __construct(
        public float $inMicroPerChar,
        public int $answerMicro,
    ) {
    }

    /** The rates of a model, or null when it has no price. */
    public static function of(?Model $model, float $markupPercent): ?self
    {
        if (null === $model || !$model->isPriceKnown() || ($model->getPriceIn() <= 0.0 && $model->getPriceOut() <= 0.0)) {
            return null;
        }
        $markup = 1 + max(0.0, $markupPercent) / 100;
        $in = self::perToken($model->getPriceIn(), $model->getInUnit()) * $markup * self::MICRO / self::CHARS_PER_TOKEN;
        $answer = self::perToken($model->getPriceOut(), $model->getOutUnit()) * $markup * self::MICRO * self::ANSWER_TOKENS;

        return new self(max(0.0, $in), (int) ceil(round(max(0.0, $answer), 3)));
    }

    /** One call that sends this many characters and writes a full answer. */
    public function call(int $chars): int
    {
        return (int) ceil(round(max(0, $chars) * $this->inMicroPerChar, 3)) + $this->answerMicro;
    }

    /** @return array{in: float, answer: int} */
    public function encode(): array
    {
        return ['in' => $this->inMicroPerChar, 'answer' => $this->answerMicro];
    }

    public static function decode(mixed $raw): ?self
    {
        if (!\is_array($raw) || !is_numeric($raw['in'] ?? null) || !\is_int($raw['answer'] ?? null)) {
            return null;
        }

        return new self(max(0.0, (float) $raw['in']), max(0, $raw['answer']));
    }

    private static function perToken(float $price, string $unit): float
    {
        return match ($unit) {
            'per1K' => $price / 1_000,
            'per1' => $price,
            default => $price / 1_000_000,
        };
    }
}
