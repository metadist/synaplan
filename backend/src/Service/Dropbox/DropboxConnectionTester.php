<?php

declare(strict_types=1);

namespace App\Service\Dropbox;

use App\Entity\Connection;
use App\Service\Connection\ConnectionTester;

final readonly class DropboxConnectionTester implements ConnectionTester
{
    public function __construct(
        private DropboxConnectionService $dropbox,
    ) {
    }

    public function supports(string $type): bool
    {
        return Connection::TYPE_DROPBOX === $type;
    }

    public function test(Connection $connection): array
    {
        return $this->dropbox->test($connection);
    }
}
