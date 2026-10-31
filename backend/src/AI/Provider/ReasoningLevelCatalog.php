<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Catalog\ProviderCatalog;
use App\Model\ModelCatalog;

/**
 * Discrete reasoning levels a chat model exposes, cheapest first.
 *
 * A reasoning model with no selectable levels returns null so the chat keeps
 * the on/off Thinking toggle. The ladders — which families take which level
 * names — live on each provider's descriptor ({@see \App\AI\Catalog\ReasoningLadder});
 * this class applies them. It is static like its callers and reads the
 * shipped catalog.
 */
final class ReasoningLevelCatalog
{
    /** Every tier name any provider uses, cheapest first. */
    private const LADDER = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

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

        return ProviderCatalog::builtIn()->find(ModelCatalog::normalizeProvider($service))?->dialects->reasoning?->levelsFor($providerId);
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
     * When thinking is on and the caller did not send a level, an authored
     * catalog default (not the cheapest fallback) is used. Thinking off is
     * left alone so an older client can still disable reasoning.
     *
     * @param array<string, mixed> $options
     * @param list<string>         $features
     * @param array<string, mixed> $modelJson
     *
     * @return array<string, mixed>
     */
    public static function apply(array $options, string $service, string $providerId, array $features, array $modelJson = []): array
    {
        $levels = self::levels($service, $providerId, $features);
        if (null === $levels) {
            unset($options['reasoning_effort']);

            return $options;
        }

        $requested = $options['reasoning_effort'] ?? null;
        if (!is_string($requested) || '' === trim($requested)) {
            $default = self::authoredDefault($levels, $modelJson);
            if (null !== $default && self::reasoningIsOn($options['reasoning'] ?? false)) {
                $options['reasoning_effort'] = $default;
                $options['reasoning'] = !in_array($default, ['none', 'minimal'], true);
            } else {
                unset($options['reasoning_effort']);
            }

            return $options;
        }

        $clamped = self::clamp($levels, $requested);
        $options['reasoning_effort'] = $clamped;
        $options['reasoning'] = !in_array($clamped, ['none', 'minimal'], true);

        return $options;
    }

    /**
     * Catalog default only when the row actually names a level this model accepts.
     *
     * @param list<string>         $levels
     * @param array<string, mixed> $json
     */
    public static function authoredDefault(array $levels, array $json): ?string
    {
        $candidate = self::catalogDefault($json);
        if (null !== $candidate && in_array($candidate, $levels, true)) {
            return $candidate;
        }

        return null;
    }

    private static function reasoningIsOn(mixed $reasoning): bool
    {
        if (is_array($reasoning)) {
            return [] !== $reasoning;
        }

        return (bool) $reasoning;
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
}
