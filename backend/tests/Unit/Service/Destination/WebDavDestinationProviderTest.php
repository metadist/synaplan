<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Destination;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Dav\WebDavClient;
use App\Service\Destination\DestinationFailureCode;
use App\Service\Destination\ShareableFile;
use App\Service\Destination\WebDavDestinationProvider;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebDavDestinationProviderTest extends TestCase
{
    private const BASE_URL = 'https://93.184.216.34/remote.php/dav/files/ada';

    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'dav').'.docx';
        file_put_contents($this->tempFile, 'report content');
    }

    protected function tearDown(): void
    {
        @unlink($this->tempFile);
    }

    public function testUploadsIntoTheConfiguredFolder(): void
    {
        $captured = [];
        $provider = $this->provider([
            new MockResponse('', ['http_code' => 404]), // folder PROPFIND
            new MockResponse('', ['http_code' => 201]), // MKCOL
            new MockResponse('', ['http_code' => 404]), // target-name PROPFIND
            new MockResponse('', ['http_code' => 201]), // PUT
        ], $captured);

        $result = $provider->send($this->file(), ['connection_id' => 7]);

        self::assertTrue($result->ok);
        self::assertSame('Synaplan/report.docx', $result->reference);
        self::assertSame('PUT', $captured[3]['method']);
        self::assertSame(self::BASE_URL.'/Synaplan/report.docx', $captured[3]['url']);
    }

    public function testRenameConflictPolicyPicksAFreeName(): void
    {
        $captured = [];
        $provider = $this->provider([
            new MockResponse('<m/>', ['http_code' => 207]), // folder exists
            new MockResponse('<m/>', ['http_code' => 207]), // report.docx taken
            new MockResponse('', ['http_code' => 404]),     // report (2).docx free
            new MockResponse('', ['http_code' => 201]),     // PUT
        ], $captured);

        $result = $provider->send($this->file(), ['connection_id' => 7]);

        self::assertTrue($result->ok);
        self::assertSame('report (2).docx', $result->context['newName']);
    }

    public function testAnotherUsersConnectionIsUnauthorized(): void
    {
        $connections = $this->createMock(ConnectionRepository::class);
        $connections->method('findByIdAndOwner')->willReturn(null);

        $provider = new WebDavDestinationProvider(
            new WebDavClient(new MockHttpClient([]), new SsrfGuard()),
            $connections,
            $this->createStub(CredentialVaultInterface::class),
        );

        $result = $provider->send($this->file(), ['connection_id' => 99]);

        self::assertFalse($result->ok);
        self::assertSame(DestinationFailureCode::Unauthorized, $result->code);
    }

    public function testFullServerMapsToQuotaExceeded(): void
    {
        $provider = $this->provider([
            new MockResponse('<m/>', ['http_code' => 207]),
            new MockResponse('', ['http_code' => 404]),
            new MockResponse('', ['http_code' => 507]),
        ]);

        $result = $provider->send($this->file(), ['connection_id' => 7]);

        self::assertFalse($result->ok);
        self::assertSame(DestinationFailureCode::QuotaExceeded, $result->code);
    }

    public function testOversizedFileFailsBeforeAnyRequest(): void
    {
        $provider = $this->provider([]);
        $file = new ShareableFile(1, 1, $this->tempFile, 'report.docx', 500 * 1024 * 1024);

        $result = $provider->send($file, ['connection_id' => 7]);

        self::assertFalse($result->ok);
        self::assertSame(DestinationFailureCode::TooLarge, $result->code);
    }

    private function file(): ShareableFile
    {
        return new ShareableFile(1, 1, $this->tempFile, 'report.docx', 14);
    }

    /**
     * @param list<MockResponse>                            $responses
     * @param list<array{method: string, url: string}>|null $captured
     */
    private function provider(array $responses, ?array &$captured = null): WebDavDestinationProvider
    {
        $connection = new Connection(1, 'webdav', 'My Nextcloud');
        $connection->setConfig(['base_url' => self::BASE_URL, 'username' => 'ada', 'folder' => 'Synaplan']);
        $connection->setCredentialId(42);

        $connections = $this->createMock(ConnectionRepository::class);
        $connections->expects(self::any())->method('findByIdAndOwner')->with(7, 1)->willReturn($connection);

        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->expects(self::any())->method('reveal')->with(42, 1)->willReturn('app-password');

        $http = new MockHttpClient(function (string $method, string $url) use (&$responses, &$captured) {
            if (null !== $captured) {
                $captured[] = ['method' => $method, 'url' => $url];
            }

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new WebDavDestinationProvider(
            new WebDavClient($http, new SsrfGuard()),
            $connections,
            $vault,
        );
    }
}
