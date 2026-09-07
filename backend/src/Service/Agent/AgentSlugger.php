<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * Builds a URL-safe slug from an assistant name.
 *
 * Length is capped at {@see self::MAX_LENGTH} so `agent:{slug}` fits in
 * BPROMPTS.BTOPIC (VARCHAR(64); the `agent:` prefix is 6 characters).
 */
final class AgentSlugger
{
    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 58;

    public static function from(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if (strlen($slug) < self::MIN_LENGTH) {
            $slug = '' === $slug ? 'assistant' : $slug.'-ai';
        }

        if (strlen($slug) > self::MAX_LENGTH) {
            $slug = rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
        }

        if (strlen($slug) < self::MIN_LENGTH) {
            return 'assistant';
        }

        return $slug;
    }
}
