<?php

declare(strict_types=1);

namespace App\AI\Exception;

use OpenAI\Exceptions\ErrorException;

/**
 * Recognises a gateway that refused a request on purpose — a quota, an
 * expired trial, a pause — while it is perfectly healthy. Such a refusal is
 * final for the turn: it must not be retried, must not open the provider's
 * circuit (which would fail every other endpoint of the same provider) and
 * must not count against the model's health.
 *
 * Today only the Synaplan Demo gateway uses these codes (`freeapi_*`).
 */
final class UpstreamRefusal
{
    /** @var list<string> */
    public const CODE_PREFIXES = ['freeapi_'];

    public static function codeOf(\Throwable $error): ?string
    {
        for ($current = $error; null !== $current; $current = $current->getPrevious()) {
            $code = match (true) {
                $current instanceof ProviderException => $current->getContext()['error_code'] ?? null,
                $current instanceof ErrorException => $current->getErrorCode(),
                default => null,
            };
            if (\is_string($code) && self::isRefusalCode($code)) {
                return $code;
            }
        }

        return null;
    }

    public static function isRefusal(\Throwable $error): bool
    {
        return null !== self::codeOf($error);
    }

    private static function isRefusalCode(string $code): bool
    {
        foreach (self::CODE_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
