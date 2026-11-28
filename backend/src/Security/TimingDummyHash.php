<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Password hashes used only so a missing account costs the same verify as a
 * real one. They are not credentials. Both values are listed in
 * .github/dummy-secrets.allow, which is what the scanners trust.
 */
final class TimingDummyHash
{
    public const LOGIN = '$2y$13$A5.u1234567890123456789012345678901234567890123456789';

    public const WORDPRESS = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    private function __construct()
    {
    }
}
