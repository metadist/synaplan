<?php

declare(strict_types=1);

namespace App\Service\Microsoft;

use App\Entity\Connection;
use App\Service\Connection\ConnectionTester;

final readonly class MicrosoftConnectionTester implements ConnectionTester
{
    public function __construct(
        private MicrosoftConnectionService $microsoft,
    ) {
    }

    public function supports(string $type): bool
    {
        return Connection::TYPE_M365 === $type;
    }

    public function test(Connection $connection): array
    {
        return $this->microsoft->test($connection);
    }
}
