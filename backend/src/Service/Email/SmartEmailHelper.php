<?php

declare(strict_types=1);

namespace App\Service\Email;

/**
 * Helper for parsing and validating "smart" email addresses.
 *
 * Synaplan uses a Gmail-based inbound email system where users can send emails to:
 * - smart@synaplan.net (general)
 * - smart+KEYWORD@synaplan.net (keyword triggers user-specific handling)
 *
 * Legacy domain synaplan.com is also accepted for backwards compatibility.
 *
 * @todo Make the accepted domain(s) configurable for self-hosted installations.
 *       See: https://github.com/metadist/synaplan/issues/177
 */
final class SmartEmailHelper
{
    /**
     * Accepted email domains for smart addresses.
     * First domain is the canonical one used when building addresses.
     */
    private const ACCEPTED_DOMAINS = ['synaplan.net', 'synaplan.com'];

    /**
     * The local part prefix for smart addresses.
     */
    private const LOCAL_PART = 'smart';

    /**
     * Check if email is a valid smart address (smart@domain or smart+keyword@domain).
     */
    public static function isValidSmartAddress(string $email): bool
    {
        $email = strtolower(trim($email));
        $domainsPattern = implode('|', array_map('preg_quote', self::ACCEPTED_DOMAINS));

        return 1 === preg_match('/^'.self::LOCAL_PART.'(\+[a-z0-9\-_]+)?@('.$domainsPattern.')$/i', $email);
    }

    /**
     * Extract keyword from smart+keyword@domain address.
     *
     * @return string|null The keyword, or null if no keyword or invalid address
     */
    public static function extractKeyword(string $email): ?string
    {
        $email = strtolower(trim($email));
        $domainsPattern = implode('|', array_map('preg_quote', self::ACCEPTED_DOMAINS));

        if (preg_match('/^'.self::LOCAL_PART.'\+([a-z0-9\-_]+)@('.$domainsPattern.')$/i', $email, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Build a smart email address, optionally with a keyword.
     */
    public static function buildAddress(?string $keyword = null): string
    {
        $domain = self::ACCEPTED_DOMAINS[0]; // Use canonical domain

        if ($keyword) {
            $keyword = preg_replace('/[^a-z0-9\-_]/', '', strtolower($keyword));

            return self::LOCAL_PART.'+'.$keyword.'@'.$domain;
        }

        return self::LOCAL_PART.'@'.$domain;
    }

    /**
     * Get the base smart email address (without keyword).
     */
    public static function getBaseAddress(): string
    {
        return self::buildAddress();
    }
}
