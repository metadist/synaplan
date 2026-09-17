<?php

declare(strict_types=1);

namespace App\Service\Microsoft;

/**
 * Maps Microsoft's OAuth error / error_description onto a stable reason the
 * Connections page can translate. The query string must never carry the raw
 * provider text (tenant names, emails).
 */
final readonly class MicrosoftConsentErrorMapper
{
    public function reason(string $error, string $description): string
    {
        $haystack = strtoupper($error.' '.$description);
        if (str_contains($haystack, 'AADSTS50020')) {
            return 'personal_account';
        }
        if (str_contains($haystack, 'AADSTS90094') || str_contains($haystack, 'AADSTS65001')) {
            return 'admin_consent';
        }
        if ('access_denied' === $error) {
            return 'access_denied';
        }

        return 'unknown';
    }
}
