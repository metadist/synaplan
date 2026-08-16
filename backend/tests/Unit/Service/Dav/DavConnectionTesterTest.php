<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Dav;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Dav\DavConnectionTester;
use App\Service\Dav\WebDavClient;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DavConnectionTesterTest extends TestCase
{
    private const BASE_URL = 'https://93.184.216.34/remote.php/dav/files/ada';

    public function testSupportsBothDavTypes(): void
    {
        $tester = $this->tester([]);

        self::assertTrue($tester->supports('webdav'));
        self::assertTrue($tester->supports('caldav'));
        self::assertFalse($tester->supports('m365'));
    }

    public function testAReachableCollectionMarksTheConnectionConnected(): void
    {
        $connection = $this->connection();
        $result = $this->tester([new MockResponse('<m/>', ['http_code' => 207])])->test($connection);

        self::assertTrue($result['success']);
        self::assertSame(Connection::STATUS_CONNECTED, $connection->getStatus());
        self::assertSame('ada@93.184.216.34', $result['account']);
    }

    public function testARevokedAppPasswordAsksForReauthentication(): void
    {
        $connection = $this->connection();
        $result = $this->tester([new MockResponse('', ['http_code' => 401])])->test($connection);

        self::assertFalse($result['success']);
        self::assertSame(Connection::STATUS_REAUTH_REQUIRED, $connection->getStatus());
        self::assertArrayHasKey('error', $result);
    }

    public function testAConnectionWithoutACredentialFailsWithAReadableReason(): void
    {
        $connection = new Connection(1, 'webdav', 'Broken row');
        $connection->setConfig(['base_url' => self::BASE_URL, 'username' => 'ada']);

        $result = $this->tester([])->test($connection);

        self::assertFalse($result['success']);
        self::assertSame(Connection::STATUS_ERROR, $connection->getStatus());
        self::assertStringContainsString('app password', (string) ($result['error'] ?? ''));
    }

    private function connection(): Connection
    {
        $connection = new Connection(1, 'webdav', 'My Nextcloud');
        $connection->setConfig(['base_url' => self::BASE_URL, 'username' => 'ada']);
        $connection->setCredentialId(42);

        return $connection;
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function tester(array $responses): DavConnectionTester
    {
        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->method('reveal')->willReturn('app-password');

        return new DavConnectionTester(
            new WebDavClient(new MockHttpClient($responses), new SsrfGuard()),
            $this->createStub(ConnectionRepository::class),
            $vault,
        );
    }
}
