<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * Redis key names for one research run. The shared client adds its own prefix.
 */
final class ResearchKey
{
    public const CLAIMS = 'research:claims';
    public const ACTIVE = 'research:active';

    public static function run(string $id): string
    {
        return 'research:run:'.$id;
    }

    public static function events(string $id): string
    {
        return 'research:events:'.$id;
    }

    public static function eventSeq(string $id): string
    {
        return 'research:events:'.$id.':seq';
    }

    public static function text(string $id, string $sourceId): string
    {
        return 'research:text:'.$id.':'.$sourceId;
    }

    public static function textIndex(string $id): string
    {
        return 'research:text-index:'.$id;
    }

    public static function activeUser(int $userId): string
    {
        return 'research:active:'.$userId;
    }

    public static function owner(int $userId): string
    {
        return 'research:owner:'.$userId;
    }

    public static function claim(string $id, int $seq): string
    {
        return 'research:claim:'.$id.':'.$seq;
    }

    public static function claimMember(string $id, int $seq, string $token): string
    {
        return $id.'|'.$seq.'|'.$token;
    }

    public static function reap(string $id, int $seq): string
    {
        return 'research:reap:'.$id.':'.$seq;
    }

    public static function stop(string $id): string
    {
        return 'research:stop:'.$id;
    }

    public static function writeNow(string $id): string
    {
        return 'research:write:'.$id;
    }

    public static function idempotency(int $userId, string $key): string
    {
        return 'research:idem:'.$userId.':'.hash('sha256', $key);
    }

    public static function delivered(string $id): string
    {
        return 'research:delivered:'.$id;
    }
}
