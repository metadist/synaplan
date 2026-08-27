<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * Best-effort redaction of free-text fields before they enter the event ring.
 *
 * The event ring is built from an allow-list of structured fields, so the only
 * places user data can leak are the two free-text fields an exception carries:
 * the log message template and the exception message (either can contain
 * interpolated values such as "User foo@bar.com not found"). This scrubber
 * masks the common shapes of PII and secrets and hard-caps the length.
 *
 * This is a risk-reducer, not a guarantee — an exotic format can still slip
 * through. It exists precisely so the AI-facing feed never carries obvious
 * emails, bearer tokens, or provider keys.
 */
final class EventScrubber
{
    private const MAX_LENGTH = 2000;
    private const REDACTED = '[redacted]';

    /**
     * Ordered list of pattern => replacement. Applied in sequence.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        // Email addresses.
        '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[email]',
        // Authorization / Bearer headers.
        '/\bBearer\s+[A-Za-z0-9._\-]+/i' => 'Bearer [redacted]',
        // Provider API keys (OpenAI, Anthropic, Stripe, Groq, xAI, webhook secrets, …).
        '/\b(sk|pk|rk|gsk|xai|whsec)[-_][A-Za-z0-9]{6,}/i' => '[key]',
        // key=value / key: value pairs for obviously sensitive keys.
        '/\b(password|passwd|secret|token|api[_\-]?key|authorization|auth)\b\s*[:=]\s*\S+/i' => '$1='.self::REDACTED,
    ];

    public function scrub(?string $text): ?string
    {
        if (null === $text) {
            return null;
        }

        foreach (self::PATTERNS as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $text);
            if (\is_string($result)) {
                $text = $result;
            }
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_LENGTH).'…';
        }

        return $text;
    }
}
