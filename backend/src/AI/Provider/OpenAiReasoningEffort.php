<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * Accepted `reasoning.effort` tiers per OpenAI model family, cheapest first.
 *
 * OpenAI renames and extends this vocabulary with almost every family and
 * answers an unknown tier with HTTP 400 ("Unsupported value: 'X' is not
 * supported with the 'Y' model"), so it has to be looked up per model
 * rather than guessed. Verified against the model reference on 2026-09-04
 * (https://developers.openai.com/api/docs/models).
 *
 * The first match wins, so more specific prefixes must come first —
 * `gpt-5.5-pro` accepts a narrower set than `gpt-5.5`, and every `gpt-5.x`
 * entry must precede the bare `gpt-5` fallback.
 *
 * Callers pass an already-lowercased bare model id (no `provider:` prefix).
 */
final class OpenAiReasoningEffort
{
    /**
     * @var array<string, list<string>>
     */
    private const TIERS = [
        // Sol / Luna publish a `none` skip tier; Astra does not. Longer
        // prefixes must precede the bare `gpt-6` fallback.
        'gpt-6-sol' => ['none', 'low', 'medium', 'high', 'xhigh', 'max'],
        'gpt-6-luna' => ['none', 'low', 'medium', 'high', 'xhigh', 'max'],
        'gpt-6' => ['low', 'medium', 'high', 'xhigh', 'max'],
        'gpt-5.6' => ['none', 'low', 'medium', 'high', 'xhigh', 'max'],
        // Pro reasons hard by design: no skip tier, default 'high'.
        'gpt-5.5-pro' => ['medium', 'high', 'xhigh'],
        'gpt-5.5' => ['none', 'low', 'medium', 'high', 'xhigh'],
        'gpt-5.4' => ['none', 'low', 'medium', 'high', 'xhigh'],
        // gpt-5 … gpt-5.3 still use the original 'minimal' skip tier.
        'gpt-5' => ['minimal', 'low', 'medium', 'high'],
    ];

    /** o-series (o1/o3/o4) and anything unknown: no skip tier, no xhigh. */
    private const TIERS_FALLBACK = ['low', 'medium', 'high'];

    /** Every tier name OpenAI uses, cheapest first. */
    private const LADDER = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * Tiers the given model accepts, cheapest first.
     *
     * @return list<string>
     */
    public static function tiers(string $model): array
    {
        foreach (self::TIERS as $prefix => $tiers) {
            if (str_starts_with($model, $prefix)) {
                return $tiers;
            }
        }

        return self::TIERS_FALLBACK;
    }

    /**
     * Cheapest `reasoning.effort` tier the given model accepts.
     */
    public static function lowest(string $model): string
    {
        return self::tiers($model)[0];
    }

    /**
     * Highest tier the model accepts that does not exceed $requested, so a
     * cross-provider `max` lands on the family's cap instead of HTTP 400.
     * Falls back to the cheapest tier when the request is below the family's
     * floor or is not a tier name at all.
     */
    public static function clamp(string $model, string $requested): string
    {
        $tiers = self::tiers($model);
        $ceiling = array_search(strtolower($requested), self::LADDER, true);
        if (false === $ceiling) {
            return $tiers[0];
        }

        $best = $tiers[0];
        foreach ($tiers as $tier) {
            $rank = array_search($tier, self::LADDER, true);
            if (false !== $rank && $rank <= $ceiling) {
                $best = $tier;
            }
        }

        return $best;
    }
}
