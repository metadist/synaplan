<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command;

final class SystemClock implements ClockInterface
{
    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }
}
