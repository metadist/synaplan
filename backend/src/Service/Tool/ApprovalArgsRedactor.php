<?php

declare(strict_types=1);

namespace App\Service\Tool;

/**
 * Drops secrets from stored approval arguments and previews (C6).
 */
final readonly class ApprovalArgsRedactor
{
    private const SENSITIVE_KEY = '/token|secret|password|authorization|credential/i';

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function redact(array $args): array
    {
        $out = [];
        foreach ($args as $key => $value) {
            $name = is_string($key) ? $key : (string) $key;
            if (1 === preg_match(self::SENSITIVE_KEY, $name)) {
                continue;
            }
            if (is_array($value)) {
                $out[$name] = $this->redact($value);
                continue;
            }
            if (is_string($value) && 1 === preg_match(self::SENSITIVE_KEY, $value)) {
                continue;
            }
            $out[$name] = $value;
        }

        return $out;
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
