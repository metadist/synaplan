<?php

declare(strict_types=1);

namespace App\Service\Iam\Policy;

/**
 * Settings a group may set. Keys outside this list never consult BGROUPCONFIG.
 */
final class PolicyAllowList
{
    public const MERGE_FIRST = 'first';
    public const MERGE_UNION = 'union';
    public const MERGE_OR = 'or';
    public const MERGE_HIGHEST_TIER = 'highest';

    public const TIERS = ['NEW', 'PRO', 'TEAM', 'BUSINESS'];

    public const DEFAULT_MODEL_SETTINGS = [
        'CHAT',
        'VECTORIZE',
        'PIC2TEXT',
        'SOUND2TEXT',
        'MEM',
        'TOOLS',
    ];

    public const FEATURE_KEYS = [
        'SAVEDTASKS.ENABLED',
        'DESKTOP_AGENT.ENABLED',
        'DOCUMENT_TOOLS.ENABLED',
        'MULTITASK.ROUTING_ENABLED',
        'MULTITASK.PARALLEL_ENABLED',
        'MULTITASK.URL_FETCH_ENABLED',
        'MULTITASK.MCP_FETCH_ENABLED',
        'MULTITASK.MCP_ACTION_ENABLED',
        'MULTITASK.EMAIL_SEARCH_ENABLED',
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::DEFAULT_MODEL_SETTINGS as $setting) {
            $keys[] = 'DEFAULTMODEL.'.$setting;
        }
        $keys[] = 'MODELS.ALLOWED';
        foreach (self::FEATURE_KEYS as $key) {
            $keys[] = $key;
        }
        $keys[] = 'RATELIMITS.TIER';

        return $keys;
    }

    public static function contains(string $group, string $setting): bool
    {
        return in_array(self::key($group, $setting), self::keys(), true);
    }

    public static function isKnownKey(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * @return array{group: string, setting: string}|null
     */
    public static function split(string $key): ?array
    {
        if (!self::isKnownKey($key)) {
            return null;
        }
        $dot = strpos($key, '.');
        if (false === $dot) {
            return null;
        }

        return [
            'group' => substr($key, 0, $dot),
            'setting' => substr($key, $dot + 1),
        ];
    }

    public static function key(string $group, string $setting): string
    {
        return $group.'.'.$setting;
    }

    public static function mergeRule(string $group, string $setting): string
    {
        $key = self::key($group, $setting);
        if (str_starts_with($key, 'DEFAULTMODEL.')) {
            return self::MERGE_FIRST;
        }
        if ('MODELS.ALLOWED' === $key) {
            return self::MERGE_UNION;
        }
        if ('RATELIMITS.TIER' === $key) {
            return self::MERGE_HIGHEST_TIER;
        }

        return self::MERGE_OR;
    }

    public static function isBoolKey(string $group, string $setting): bool
    {
        return self::MERGE_OR === self::mergeRule($group, $setting);
    }

    /**
     * @param list<string> $values
     */
    public static function merge(string $group, string $setting, array $values): ?string
    {
        $values = array_values(array_filter($values, static fn (string $v): bool => '' !== $v));
        if ([] === $values) {
            return null;
        }

        return match (self::mergeRule($group, $setting)) {
            self::MERGE_FIRST => $values[0],
            self::MERGE_UNION => self::mergeUnion($values),
            self::MERGE_OR => self::mergeOr($values),
            self::MERGE_HIGHEST_TIER => self::mergeHighestTier($values),
            default => $values[0],
        };
    }

    /**
     * @param list<string> $values
     */
    private static function mergeUnion(array $values): string
    {
        $keys = [];
        foreach ($values as $raw) {
            foreach (self::decodeList($raw) as $item) {
                $keys[$item] = true;
            }
        }

        return json_encode(array_keys($keys), \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $values
     */
    private static function mergeOr(array $values): string
    {
        foreach ($values as $raw) {
            if (true === filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE)) {
                return '1';
            }
        }

        return '0';
    }

    /**
     * @param list<string> $values
     */
    private static function mergeHighestTier(array $values): string
    {
        $rank = array_flip(self::TIERS);
        $best = null;
        $bestRank = -1;
        foreach ($values as $raw) {
            $tier = strtoupper(trim($raw));
            if (!isset($rank[$tier])) {
                continue;
            }
            if ($rank[$tier] > $bestRank) {
                $bestRank = $rank[$tier];
                $best = $tier;
            }
        }

        return $best ?? $values[0];
    }

    /**
     * @return list<string>
     */
    public static function decodeList(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item) && '' !== trim($item)) {
                $out[] = trim($item);
            }
        }

        return $out;
    }
}
