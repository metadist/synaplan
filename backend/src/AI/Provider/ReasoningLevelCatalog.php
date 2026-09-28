<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * Discrete reasoning levels a chat model exposes, cheapest first.
 *
 * A reasoning model with no selectable levels returns null so the chat keeps
 * the on/off Thinking toggle. Families that reject an unknown name (HTTP 400)
 * are listed explicitly; everything else stays on the toggle.
 *
 * The first matching prefix wins, so longer prefixes come first.
 */
final class ReasoningLevelCatalog
{
    /** Every tier name any provider uses, cheapest first. */
    private const LADDER = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * Adaptive Claude models accept output_config.effort low|medium|high.
     * Must stay aligned with AnthropicProvider::ADAPTIVE_THINKING_MODELS.
     * Budget-token models are omitted on purpose: they keep the toggle.
     *
     * @var list<string>
     */
    private const ANTHROPIC_EFFORT_MODELS = [
        'claude-opus-4-6',
        'claude-opus-4-7',
        'claude-opus-4-8',
        'claude-sonnet-4-6',
        'claude-opus-5-5',
        'claude-opus-5',
        'claude-sonnet-5',
        'claude-fable-5-1',
        'claude-fable-5',
    ];

    /**
     * @param list<string> $features
     *
     * @return list<string>|null cheapest first, or null when the model has no level knob
     */
    public static function levels(string $service, string $providerId, array $features): ?array
    {
        if (!in_array('reasoning', $features, true)) {
            return null;
        }

        $providerId = strtolower($providerId);

        return match (self::normalizeService($service)) {
            'openai' => self::openAiLevels($providerId),
            'xai' => self::xaiLevels($providerId),
            'google', 'gemini' => ['low', 'medium', 'high'],
            'anthropic' => self::anthropicLevels($providerId),
            'meta' => ['minimal', 'low', 'medium', 'high', 'xhigh', 'max'],
            'huggingface', 'hugging face' => self::huggingFaceLevels($providerId),
            default => null,
        };
    }

    /**
     * Highest listed level that does not exceed $requested.
     * An unknown name falls back to the cheapest level.
     *
     * @param list<string> $levels
     */
    public static function clamp(array $levels, string $requested): string
    {
        if ([] === $levels) {
            return '';
        }

        $ceiling = array_search(strtolower($requested), self::LADDER, true);
        if (false === $ceiling) {
            return $levels[0];
        }

        $best = $levels[0];
        foreach ($levels as $level) {
            $rank = array_search($level, self::LADDER, true);
            if (false !== $rank && $rank <= $ceiling) {
                $best = $level;
            }
        }

        return $best;
    }

    /**
     * Catalog default when it is one of the model's levels, otherwise the cheapest.
     *
     * @param list<string>         $levels
     * @param array<string, mixed> $json
     */
    public static function defaultLevel(array $levels, array $json): string
    {
        $candidate = self::catalogDefault($json);
        if (null !== $candidate && in_array($candidate, $levels, true)) {
            return $candidate;
        }

        return $levels[0] ?? '';
    }

    /**
     * Clamp a requested level onto the model's list and align the boolean
     * Thinking flag with it. `none` and `minimal` mean reasoning off; `low`
     * is a real level. No request leaves the boolean untouched and drops a
     * stale effort so providers keep today's default.
     *
     * @param array<string, mixed> $options
     * @param list<string>         $features
     *
     * @return array<string, mixed>
     */
    public static function apply(array $options, string $service, string $providerId, array $features): array
    {
        $levels = self::levels($service, $providerId, $features);
        if (null === $levels) {
            unset($options['reasoning_effort']);

            return $options;
        }

        $requested = $options['reasoning_effort'] ?? null;
        if (!is_string($requested) || '' === trim($requested)) {
            unset($options['reasoning_effort']);

            return $options;
        }

        $clamped = self::clamp($levels, $requested);
        $options['reasoning_effort'] = $clamped;
        $options['reasoning'] = !in_array($clamped, ['none', 'minimal'], true);

        return $options;
    }

    /**
     * @param array<string, mixed> $json
     */
    private static function catalogDefault(array $json): ?string
    {
        $direct = $json['reasoning_effort_default'] ?? null;
        if (is_string($direct) && '' !== $direct) {
            return strtolower($direct);
        }

        $meta = $json['meta'] ?? null;
        if (is_array($meta)) {
            $nested = $meta['reasoning_effort_default'] ?? null;
            if (is_string($nested) && '' !== $nested) {
                return strtolower($nested);
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function openAiLevels(string $providerId): ?array
    {
        foreach (['gpt-5', 'gpt-6', 'o1', 'o3', 'o4'] as $prefix) {
            if (str_starts_with($providerId, $prefix)) {
                return OpenAiReasoningEffort::tiers($providerId);
            }
        }

        return null;
    }

    /**
     * grok-4.5 and grok-4.6 reason at a fixed depth and reject the parameter.
     *
     * @return list<string>|null
     */
    private static function xaiLevels(string $providerId): ?array
    {
        if (str_starts_with($providerId, 'grok-4.7')) {
            return ['low', 'medium', 'high', 'xhigh'];
        }

        if (str_starts_with($providerId, 'grok-4.3')) {
            return ['none', 'low', 'medium', 'high'];
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function anthropicLevels(string $providerId): ?array
    {
        foreach (self::ANTHROPIC_EFFORT_MODELS as $prefix) {
            if (str_starts_with($providerId, $prefix)) {
                return ['low', 'medium', 'high'];
            }
        }

        return null;
    }

    /**
     * Kimi K3 accepts low|high|max. K2 rows that advertise reasoning share it.
     *
     * @return list<string>|null
     */
    private static function huggingFaceLevels(string $providerId): ?array
    {
        if (str_contains($providerId, 'kimi-k3') || str_contains($providerId, 'kimi-k2')) {
            return ['low', 'high', 'max'];
        }

        return null;
    }

    private static function normalizeService(string $service): string
    {
        return strtolower(trim($service));
    }
}
