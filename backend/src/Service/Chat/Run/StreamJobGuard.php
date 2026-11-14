<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

/**
 * A stream job may carry the prompt. It may not carry a provider key.
 *
 * Message text is not scanned, so a sentence that says "authorization" is
 * still a job. A credential field, a header name, or a header line is not.
 */
final class StreamJobGuard
{
    private const CREDENTIAL_KEYS = [
        'api_key',
        'apikey',
        'api-key',
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'x-goog-api-key',
    ];

    /**
     * @param array<string, mixed> $payload
     */
    public static function reject(array $payload): void
    {
        self::rejectCredentialKeys($payload);
    }

    private static function rejectCredentialKeys(mixed $value, bool $headerList = false): void
    {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::CREDENTIAL_KEYS, true)) {
                throw new \InvalidArgumentException('A stream job must not carry a provider key');
            }
            if ($headerList && is_string($item) && self::isCredentialHeaderLine($item)) {
                throw new \InvalidArgumentException('A stream job must not carry a provider key');
            }
            self::rejectCredentialKeys($item, is_string($key) && 'headers' === strtolower($key));
        }
    }

    private static function isCredentialHeaderLine(string $line): bool
    {
        $colon = strpos($line, ':');
        if (false === $colon) {
            return false;
        }
        $name = strtolower(trim(substr($line, 0, $colon)));

        return in_array($name, self::CREDENTIAL_KEYS, true);
    }
}
