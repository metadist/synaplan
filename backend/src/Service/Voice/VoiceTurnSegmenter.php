<?php

declare(strict_types=1);

namespace App\Service\Voice;

/**
 * Groups transcript pieces into turns. Same speaker and a gap under 1.2s
 * stay one turn. A speaker change or a longer pause starts the next one.
 */
final class VoiceTurnSegmenter
{
    public const SILENCE_MS = 1200;

    /**
     * @param list<array{role?: mixed, text?: mixed, startMs?: mixed, endMs?: mixed}> $chunks
     *
     * @return list<array{role: string, text: string, startMs: int, endMs: int}>
     */
    public function segment(array $chunks): array
    {
        $turns = [];
        foreach ($chunks as $chunk) {
            $text = trim(is_string($chunk['text'] ?? null) ? $chunk['text'] : '');
            if ('' === $text) {
                continue;
            }
            $role = 'assistant' === ($chunk['role'] ?? null) ? 'assistant' : 'user';
            $startMs = is_numeric($chunk['startMs'] ?? null) ? (int) $chunk['startMs'] : 0;
            $endMs = is_numeric($chunk['endMs'] ?? null) ? (int) $chunk['endMs'] : $startMs;
            $lastIndex = array_key_last($turns);
            $last = null === $lastIndex ? null : $turns[$lastIndex];
            $gap = null === $last ? \PHP_INT_MAX : $startMs - $last['endMs'];
            if (null !== $last && $last['role'] === $role && $gap < self::SILENCE_MS) {
                $turns[$lastIndex] = [
                    'role' => $role,
                    'text' => $last['text'].' '.$text,
                    'startMs' => $last['startMs'],
                    'endMs' => max($last['endMs'], $endMs),
                ];
                continue;
            }
            $turns[] = [
                'role' => $role,
                'text' => $text,
                'startMs' => $startMs,
                'endMs' => $endMs,
            ];
        }

        return $turns;
    }

    /**
     * The text to keep when a stored turn and a new one are the same words.
     * A spoken question is stored once when it is handed to Synaplan and
     * again when the speaker's turn closes; the longer version wins.
     * Returns null when the two texts are different turns.
     */
    public function sameTurn(string $stored, string $next): ?string
    {
        $storedKey = $this->normalized($stored);
        $nextKey = $this->normalized($next);
        if ('' === $storedKey || '' === $nextKey) {
            return null;
        }
        if (str_starts_with($nextKey, $storedKey)) {
            return $next;
        }
        if (str_starts_with($storedKey, $nextKey)) {
            return $stored;
        }

        return null;
    }

    private function normalized(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
