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
     * True only when the caller marked the URL as one a person typed.
     * A `resolve` pin is an operator's own address override and is not a
     * typed URL: inferring that would let a DNS override skip the allowlist.
     *
     * @param array<string, mixed> $options
     */
    public static function isTyped(array $options): bool
    {
        $extra = $options['extra'] ?? null;

        return \is_array($extra) && true === ($extra[self::TYPED] ?? false);
    }

    /**
     * The address the caller already checked, when it pinned one.
     *
     * @param array<string, mixed> $options
     */
    public static function pinnedAddress(array $options, string $host): ?string
    {
        $resolve = $options['resolve'] ?? null;
        if (!\is_array($resolve)) {
            return null;
        }
        $address = $resolve[$host] ?? $resolve[strtolower($host)] ?? null;

        return \is_string($address) && '' !== $address ? $address : null;
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
