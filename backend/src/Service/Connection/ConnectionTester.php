<?php

declare(strict_types=1);

namespace App\Service\Connection;

use App\Entity\Connection;

/**
 * Verifies one connection type against the real remote system.
 *
 * Without a tester, "test" can only confirm that a secret exists. WebDAV
 * (connector plan 07 C10) and Dropbox (C13) get their own implementations.
 */
interface ConnectionTester
{
    public function supports(string $type): bool;

    /**
     * Performs the cheapest call that proves the credential works, updates the
     * connection status, and reports the outcome.
     *
     * @return array{success: bool, status: string, account?: string, error?: string}
     */
    public function test(Connection $connection): array;
}
