<?php

declare(strict_types=1);

namespace App\Service\SelfAware\Eval;

/**
 * String/topic assertions for one eval row. Used by the live command and unit tests.
 */
final class SelfAwareEvalAssertions
{
    /**
     * @param array<string, mixed> $expect
     */
    public static function topicMatches(string $actualTopic, array $expect): bool
    {
        $expected = $expect['topic'] ?? null;
        if (null === $expected) {
            return true;
        }
        if ('not_synaplan' === $expected) {
            return 'synaplan' !== $actualTopic;
        }

        return $actualTopic === $expected;
    }

    /**
     * @param array<string, mixed> $expect
     *
     * @return list<string> failure messages (empty = pass)
     */
    public static function answerFailures(string $answer, array $expect): array
    {
        $failures = [];
        $haystack = mb_strtolower($answer);

        foreach (self::stringList($expect['must_contain_any'] ?? null) as $needles) {
            $hit = false;
            foreach ($needles as $needle) {
                if (str_contains($haystack, mb_strtolower($needle))) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $failures[] = 'missing any of: '.implode('|', $needles);
            }
        }

        foreach (self::stringList($expect['must_mention_any'] ?? null) as $needles) {
            $hit = false;
            foreach ($needles as $needle) {
                if (str_contains($haystack, mb_strtolower($needle))) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $failures[] = 'did not mention any of: '.implode('|', $needles);
            }
        }

        foreach (self::flatList($expect['must_not_contain'] ?? null) as $forbidden) {
            if (str_contains($haystack, mb_strtolower($forbidden))) {
                $failures[] = 'contained forbidden "'.$forbidden.'"';
            }
        }

        return $failures;
    }

    /**
     * @return list<list<string>>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value) || [] === $value) {
            return [];
        }
        if (array_is_list($value) && is_string($value[0] ?? null)) {
            return [$value];
        }

        $groups = [];
        foreach ($value as $group) {
            if (is_array($group)) {
                $groups[] = array_values(array_filter($group, 'is_string'));
            } elseif (is_string($group)) {
                $groups[] = [$group];
            }
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    private static function flatList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
