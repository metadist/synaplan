<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

final class ResearchIds
{
    public const PATTERN = '/^rr_[0-9a-f]{16}$/D';

    public static function newId(): string
    {
        return 'rr_'.bin2hex(random_bytes(8));
    }

    public static function isValid(string $id): bool
    {
        return 1 === preg_match(self::PATTERN, $id);
    }

    public static function assert(string $id): void
    {
        if (!self::isValid($id)) {
            throw new \InvalidArgumentException('This research could not be found.');
        }
    }
}
