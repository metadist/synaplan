<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * Splits a provider dump that older media failures appended to the
 * user-visible text. New failures store that dump in message meta and
 * only an admin viewer receives it, inside the expandable error notice.
 */
final class StoredAdminDiagnostics
{
    private const MARKERS = [
        '**Admin-Diagnose (nur für dich sichtbar)**',
        '**Admin diagnostics (visible to you only)**',
    ];

    /**
     * @return array{text: string, debug: ?string}
     */
    public static function split(string $text): array
    {
        foreach (self::MARKERS as $marker) {
            $pos = strpos($text, $marker);
            if (false === $pos) {
                continue;
            }

            $user = rtrim(substr($text, 0, $pos));
            $user = preg_replace("/\n+---\s*$/", '', $user) ?? $user;
            $debug = trim(substr($text, $pos + strlen($marker)));
            $debug = trim($debug, "`\n\r ");

            return [
                'text' => trim($user),
                'debug' => '' !== $debug ? $debug : null,
            ];
        }

        return ['text' => $text, 'debug' => null];
    }

    public static function userText(string $text): string
    {
        return self::split($text)['text'];
    }
}
