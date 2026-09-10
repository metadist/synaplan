<?php

declare(strict_types=1);

namespace App\Service\Tool;

/**
 * Drops secrets from stored approval arguments and previews (C6).
 *
 * Keys that name a secret are dropped. Values are only masked when they LOOK
 * like a credential (an HTTP auth scheme or a long opaque token) — a plain
 * sentence that merely mentions "password" is legitimate user content.
 */
final readonly class ApprovalArgsRedactor
{
    public const MASK = '[redacted]';

    private const SENSITIVE_KEY = '/token|secret|password|passwd|authorization|credential|api[_-]?key/i';
    private const AUTH_SCHEME_VALUE = '/^\s*(bearer|basic|token|apikey)\s+\S+/i';
    private const OPAQUE_TOKEN_VALUE = '/^[A-Za-z0-9_\-.=\/+]{32,}$/';

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function redact(array $args): array
    {
        $out = [];
        foreach ($args as $key => $value) {
            $name = (string) $key;
            if (1 === preg_match(self::SENSITIVE_KEY, $name)) {
                continue;
            }
            if (is_array($value)) {
                $out[$name] = $this->redact($value);
                continue;
            }
            $out[$name] = is_string($value) && $this->looksLikeCredential($value) ? self::MASK : $value;
        }

        return $out;
    }

    private function looksLikeCredential(string $value): bool
    {
        return 1 === preg_match(self::AUTH_SCHEME_VALUE, $value)
            || 1 === preg_match(self::OPAQUE_TOKEN_VALUE, $value);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function preview(string $title, array $args): string
    {
        $parts = [];
        foreach ($this->redact($args) as $key => $value) {
            if (is_scalar($value) || null === $value) {
                $parts[] = $key.': '.(string) $value;
            }
        }
        $summary = implode(', ', array_slice($parts, 0, 6));
        if ('' === $summary) {
            return $title;
        }

        return $title.' — '.$summary;
    }
}
