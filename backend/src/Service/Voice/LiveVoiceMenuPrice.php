<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Entity\Model;
use App\Service\Usage\CostCalculationService;

/**
 * Typical voice cost per minute, so the model menu can rank live models
 * the same way the other menus rank theirs.
 *
 * Catalog prices mix dollars per minute and dollars per million tokens.
 * Comparing those raw numbers would call a cheap per-minute model expensive.
 * This uses the same per-second estimate the budget uses, without markup.
 */
final class LiveVoiceMenuPrice
{
    /** Worst-case speech rate. Keep in step with VoiceLiveUsageService. */
    private const AUDIO_TOKENS_PER_SECOND = 25;

    public static function perMinute(Model $model): float
    {
        $json = $model->getJson();
        $mode = $json['pricing_mode'] ?? 'per_token';
        $perSecond = match ($mode) {
            'per_second' => CostCalculationService::normaliseToPerUnit($model->getPriceIn(), $model->getInUnit()),
            'per_audio_token' => (
                ((float) ($json['audio_price_in_per_1M'] ?? 0) + (float) ($json['audio_price_out_per_1M'] ?? 0))
                / 1_000_000
            ) * self::AUDIO_TOKENS_PER_SECOND,
            default => 0.0,
        };

        return $perSecond * 60;
    }
}
