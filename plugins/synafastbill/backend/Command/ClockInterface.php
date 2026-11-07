<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command;

/**
 * Time seam so overdue calculations and payment dates are deterministic in
 * tests (a fixed clock) while using the real date in production.
 */
interface ClockInterface
{
    public function today(): \DateTimeImmutable;
}
