<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

final class ResearchIds
{
    public static function newId(): string
    {
        return 'rr_'.bin2hex(random_bytes(8));
    }

    public static function assert(string $id): void
    {
        if (1 !== preg_match('/^rr_[0-9a-f]{16}$/', $id)) {
            throw new \InvalidArgumentException('This research could not be found.');
        }
    }
}
