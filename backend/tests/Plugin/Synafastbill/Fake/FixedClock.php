<?php

declare(strict_types=1);

namespace App\Tests\Plugin\Synafastbill\Fake;

use Plugin\Synafastbill\Command\ClockInterface;

final class FixedClock implements ClockInterface
{
    public function __construct(
        private readonly string $date = '2026-08-01',
    ) {
    }

    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->date);
    }
}
