<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The text a provider was actually given for one request, with secrets removed
 * and a hard length cap. No I/O.
 */
final readonly class ProviderTranscript
{
    public const MAX_CHARS = 32000;

    /**
     * @param list<array{role: string, text: string}> $messages
     */
    private function __construct(
        private array $messages,
        private bool $truncated,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public static function fromMessages(array $messages): self
    {
        $mapped = [];
        foreach ($messages as $message) {
            $role = self::mapRole((string) ($message['role'] ?? ''));
            $text = self::textOf($message['content'] ?? $message['text'] ?? '');
            if ('' === $role || '' === $text) {
                continue;
            }
            $mapped[] = ['role' => $role, 'text' => $text];
        }

        return new self($mapped, false);
    }

    public function redact(): self
    {
        $redacted = [];
        foreach ($this->messages as $message) {
            $redacted[] = [
                'role' => $message['role'],
                'text' => self::redactText($message['text']),
            ];
        }

        return new self($redacted, $this->truncated);
    }

    public function cap(int $max = self::MAX_CHARS): self
    {
        $used = 0;
        $capped = [];
        $truncated = $this->truncated;
        foreach ($this->messages as $message) {
            $room = $max - $used;
            if ($room <= 0) {
                $truncated = true;
                break;
            }
            $text = $message['text'];
            $length = mb_strlen($text);
            if ($length > $room) {
                $text = mb_substr($text, 0, $room);
                $truncated = true;
                $length = $room;
            }
            $used += $length;
            $capped[] = ['role' => $message['role'], 'text' => $text];
        }

        return new self($capped, $truncated);
    }

    /**
     * @return list<array{role: string, text: string}>
     */
    public function toArray(): array
    {
        return $this->messages;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    private static function mapRole(string $role): string
    {
        return match (strtolower($role)) {
            'system', 'developer', 'instructions' => 'instructions',
            'user', 'you' => 'you',
            'assistant', 'tool' => 'assistant',
            default => '',
        };
    }

    private static function textOf(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (!is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
                continue;
            }
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
                continue;
            }
            if (is_array($part) && is_string($part['content'] ?? null)) {
                $parts[] = $part['content'];
            }
        }

        return trim(implode("\n", $parts));
    }

    private static function redactText(string $text): string
    {
        $withoutBearer = preg_replace('/Bearer\s+\S+/i', '…', $text) ?? $text;

        return preg_replace('/\bsk[-_][A-Za-z0-9_\-]+/', '…', $withoutBearer) ?? $withoutBearer;
    }
}
