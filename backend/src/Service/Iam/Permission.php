<?php

declare(strict_types=1);

namespace App\Service\Iam;

/**
 * Four IAM permission levels. Higher implies lower: manage > edit > use > read.
 */
enum Permission: string
{
    case Read = 'read';
    case Use = 'use';
    case Edit = 'edit';
    case Manage = 'manage';

    public function implies(self $needed): bool
    {
        return $this->rank() >= $needed->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Read => 1,
            self::Use => 2,
            self::Edit => 3,
            self::Manage => 4,
        };
    }
}
