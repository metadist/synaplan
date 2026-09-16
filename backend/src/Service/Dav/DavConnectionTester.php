<?php

declare(strict_types=1);

namespace App\Service\Dav;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Connection\ConnectionTester;
use App\Service\Credential\CredentialVaultInterface;

/**
 * Tests WebDAV and CalDAV connections with the cheapest call that proves the
 * app password works: PROPFIND Depth 0 on the configured collection.
 */
final readonly class DavConnectionTester implements ConnectionTester
{
    public function __construct(
        private WebDavClient $webDav,
        private ConnectionRepository $connections,
        private CredentialVaultInterface $vault,
    ) {
    }

    public function supports(string $type): bool
    {
        return in_array($type, ['webdav', 'caldav'], true);
    }

    public function test(Connection $connection): array
    {
        $target = DavConnectionResolver::resolve($connection, $this->vault);
        if (null === $target) {
            $connection->markChecked(Connection::STATUS_ERROR);
            $this->connections->save($connection);

            return [
                'success' => false,
                'status' => Connection::STATUS_ERROR,
                'error' => 'This connection has no stored app password or address. Edit it and save the credentials again.',
            ];
        }

        try {
            $found = $this->webDav->exists($target, '');
        } catch (DavException $e) {
            $status = in_array($e->statusCode, [401, 403], true)
                ? Connection::STATUS_REAUTH_REQUIRED
                : Connection::STATUS_ERROR;
            $connection->markChecked($status);
            $this->connections->save($connection);

            return ['success' => false, 'status' => $status, 'error' => $e->getMessage()];
        }

        if (!$found) {
            $connection->markChecked(Connection::STATUS_ERROR);
            $this->connections->save($connection);

            return [
                'success' => false,
                'status' => Connection::STATUS_ERROR,
                'error' => 'The server answered, but the configured folder or calendar does not exist.',
            ];
        }

        $connection->markChecked(Connection::STATUS_CONNECTED);
        $this->connections->save($connection);

        return [
            'success' => true,
            'status' => Connection::STATUS_CONNECTED,
            'account' => $target->username.'@'.$target->host(),
        ];
    }
}
