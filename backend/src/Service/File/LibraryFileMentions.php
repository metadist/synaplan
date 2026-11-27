<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Repository\LibraryFileNameQuery;

/**
 * Searchable documents in the user's library that a message names.
 *
 * A file uploaded in another chat is not in this conversation, so the
 * planner never hears of it and plans no knowledge search. Matching the
 * message against the library's file names closes that gap without
 * listing the whole library in the prompt.
 */
final readonly class LibraryFileMentions
{
    private const MIN_NAME_LENGTH = 4;
    private const MIN_WORD_LENGTH = 3;
    private const MAX_MENTIONS = 5;

    public function __construct(private LibraryFileNameQuery $names)
    {
    }

    /**
     * @param array<int, true> $excludeIds files already in this conversation
     *
     * @return list<string> display names, newest first
     */
    public function mentioned(int $userId, string $text, array $excludeIds = []): array
    {
        $haystack = self::normalize($text);
        if ('' === $haystack) {
            return [];
        }

        $names = [];
        foreach ($this->names->searchableForUser($userId) as $row) {
            if (isset($excludeIds[$row['id']]) || !self::names($haystack, $row['name'])) {
                continue;
            }
            $names[] = $row['name'];
            if (count($names) >= self::MAX_MENTIONS) {
                break;
            }
        }

        return $names;
    }

    /**
     * @param array<int, true> $excludeIds files already in this conversation
     */
    public function renderPlannerBlock(int $userId, string $text, array $excludeIds = []): string
    {
        $names = $this->mentioned($userId, $text, $excludeIds);
        if ([] === $names) {
            return '';
        }

        $lines = '';
        foreach ($names as $name) {
            $lines .= '- "'.str_replace('"', "'", $name).'"'."\n";
        }

        return "\n\n## Documents in the user's library that this message names\n\n".$lines
            ."They are not attached to this conversation, so \$message.files does not hold them. To answer about them, plan a rag_query step: it searches the user's documents. Never plan file_analysis on \$message.files for them.\n";
    }

    private static function names(string $haystack, string $fileName): bool
    {
        $stem = self::normalize((string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $fileName));
        if (mb_strlen($stem) < self::MIN_NAME_LENGTH) {
            return false;
        }
        if (str_contains(' '.$haystack.' ', ' '.$stem.' ')) {
            return true;
        }

        $words = array_values(array_filter(
            explode(' ', $stem),
            static fn (string $word): bool => mb_strlen($word) >= self::MIN_WORD_LENGTH && !ctype_digit($word),
        ));
        if (count($words) < 2) {
            return false;
        }
        foreach ($words as $word) {
            if (!str_contains(' '.$haystack.' ', ' '.$word.' ')) {
                return false;
            }
        }

        return true;
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(\Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }
}
