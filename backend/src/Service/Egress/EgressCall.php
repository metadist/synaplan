<?php

declare(strict_types=1);

namespace App\Service\Egress;

/**
 * Marks a request whose URL a person typed. The allowlist does not grow to
 * include it. `SsrfGuard` and the pinned connect decide, per request.
 */
final class EgressCall
{
    public const TYPED = 'egress_typed';

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function typed(array $options): array
    {
        $extra = \is_array($options['extra'] ?? null) ? $options['extra'] : [];
        $extra[self::TYPED] = true;
        $options['extra'] = $extra;

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function isTyped(array $options): bool
    {
        $extra = $options['extra'] ?? null;
        if (\is_array($extra) && true === ($extra[self::TYPED] ?? false)) {
            return true;
        }

        return isset($options['resolve']) && \is_array($options['resolve']) && [] !== $options['resolve'];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function strip(array $options): array
    {
        if (!\is_array($options['extra'] ?? null)) {
            return $options;
        }
        unset($options['extra'][self::TYPED]);
        if ([] === $options['extra']) {
            unset($options['extra']);
        }

        return $options;
    }
}
