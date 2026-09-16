<?php

declare(strict_types=1);

namespace App\Service\Tool\Policy;

enum PolicyOutcome: string
{
    case Auto = 'auto';
    case Approve = 'approve';
    case Block = 'block';

    public function rank(): int
    {
        return match ($this) {
            self::Auto => 0,
            self::Approve => 1,
            self::Block => 2,
        };
    }

    public function isMoreRestrictiveThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }
}
