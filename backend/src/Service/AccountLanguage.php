<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The five languages the app, emails, and channel messages share.
 */
final class AccountLanguage
{
    public const SUPPORTED = ['de', 'en', 'es', 'fr', 'tr'];

    public static function normalize(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $code = strtolower(str_replace('_', '-', trim($value)));
        if ('' === $code) {
            return null;
        }

        $base = explode('-', $code, 2)[0];

        return \in_array($base, self::SUPPORTED, true) ? $base : null;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function withSignupLanguage(array $extra, mixed $language): array
    {
        $normalized = self::normalize($language);
        if (null !== $normalized) {
            $extra['language'] = $normalized;
        }

        return $extra;
    }
}
