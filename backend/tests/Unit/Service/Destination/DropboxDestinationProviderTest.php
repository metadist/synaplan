<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Destination;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Destination\DestinationFailureCode;
use App\Service\Destination\DropboxDestinationProvider;
use App\Service\Destination\ShareableFile;
use App\Service\Dropbox\DropboxClient;
use App\Service\OAuth\ConnectionAccessTokenProvider;
use App\Service\OAuth\OAuthReauthRequiredException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DropboxDestinationProviderTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'dbx_test_') ?: '';
        file_put_contents($this->tempFile, 'file-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->tempFile);
    }

    public function testUploadsIntoTheConfiguredFolderAndReportsTheStoredName(): void
    {
        $captured = [];
        $provider = $this->provider($this->connection(['folder' => 'Reports']), [
            new MockResponse(json_encode([
                'name' => 'plan (2).docx',
                'path_display' => '/Reports/plan (2).docx',
            ]), ['http_code' => 200]),
        ], $captured);

        $result = $provider->send($this->file('plan.docx'), ['connection_id' => 5]);

        self::assertTrue($result->ok);
        self::assertSame('Reports/plan (2).docx', $result->reference);
        self::assertSame('plan (2).docx', $result->context['newName']);

        $apiArg = json_decode($this->headerValue($captured[0]['options']['headers'], 'Dropbox-API-Arg'), true);
        self::assertSame('/Reports/plan.docx', $apiArg['path']);
    }

    public function testDefaultsToTheSynaplanFolderAndSanitizesTheName(): void
    {
        $captured = [];
        $provider = $this->provider($this->connection(), [
            new MockResponse(json_encode(['name' => 'a-b.txt', 'path_display' => '/Synaplan/a-b.txt']), ['http_code' => 200]),
        ], $captured);

        $result = $provider->send($this->file('a/b.txt'), ['connection_id' => 5]);

        self::assertTrue($result->ok);
        $apiArg = json_decode($this->headerValue($captured[0]['options']['headers'], 'Dropbox-API-Arg'), true);
        self::assertSame('/Synaplan/a-b.txt', $apiArg['path'], 'slashes in a display name must not create folders');
    }

    public function testRejectsAForeignOrWrongTypeConnection(): void
    {
        $webdav = new Connection(1, 'webdav', 'Nextcloud');
        $provider = $this->provider($webdav, []);

        $result = $provider->send($this->file('x.txt'), ['connection_id' => 5]);

        self::assertFalse($result->ok);
        self::assertSame(DestinationFailureCode::Unauthorized, $result->code);
    }

    public function testInsufficientSpaceMapsToQuotaExceeded(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Dropbox save failed',
            self::callback(static function (array $context): bool {
                return 'quota_exceeded' === ($context['code'] ?? null)
                    && 'a/b.txt' === ($context['file'] ?? null)
                    && 'Synaplan/a-b.txt' === ($context['remote'] ?? null)
                    && isset($context['error']);
            }),
        );

        $provider = $this->provider($this->connection(), [
            new MockResponse(json_encode(['error_summary' => 'path/insufficient_space/..']), ['http_code' => 409]),
        ], logger: $logger);

        $result = $provider->send($this->file('a/b.txt'), ['connection_id' => 5]);

        self::assertFalse($result->ok);
        self::assertSame(DestinationFailureCode::QuotaExceeded, $result->code);
    }

    public function testReauthRequiredMapsToUnauthorized(): void
    {
        $tokens = $this->createStub(ConnectionAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->willThrowException(new OAuthReauthRequiredException('gone'));

        $repo = $this->createMock(ConnectionRepository::class);
        $repo->method('findByIdAndOwner')->willReturn($this->connection());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Dropbox save failed: token exchange rejected',
            self::callback(static function (array $context): bool {
                return DestinationFailureCode::Unauthorized->value === ($context['code'] ?? null)
                    && 'x.txt' === ($context['file'] ?? null)
                    && 'Synaplan/x.txt' === ($context['remote'] ?? null)
                    && isset($context['error']);
            }),
        );

        $provider = new DropboxDestinationProvider(
            new DropboxClient(new MockHttpClient([]), $tokens, new NullLogger()),
            $repo,
            $logger,
        );

        $result = $provider->send($this->file('x.txt'), ['connection_id' => 5]);

        self::assertFalse($result->ok);
        self::assertSame(DestinationFailureCode::Unauthorized, $result->code);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connection(array $config = []): Connection
    {
        $connection = new Connection(1, Connection::TYPE_DROPBOX, 'user@example.com');
        $connection->setConfig($config + ['provider' => Connection::TYPE_DROPBOX]);

        return $connection;
    }

    private function file(string $name): ShareableFile
    {
        return new ShareableFile(
            fileId: 3,
            ownerId: 1,
            absolutePath: $this->tempFile,
            name: $name,
            sizeBytes: (int) filesize($this->tempFile),
        );
    }

    /**
     * @param list<string> $headers
     */
    private function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $line) {
            if (str_starts_with(strtolower($line), strtolower($name).':')) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        self::fail(sprintf('Header %s was not sent', $name));
    }

    /**
     * @param list<MockResponse>                                              $responses
     * @param list<array{method: string, url: string, options: array<mixed>}> $captured
     */
    private function provider(
        Connection $connection,
        array $responses,
        array &$captured = [],
        ?LoggerInterface $logger = null,
    ): DropboxDestinationProvider {
        $factory = function (string $method, string $url, array $options) use (&$captured, &$responses): MockResponse {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 200]);
        };

        $tokens = $this->createStub(ConnectionAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->willReturn('at-1');

        $repo = $this->createMock(ConnectionRepository::class);
        $repo->method('findByIdAndOwner')->willReturn($connection);

        return new DropboxDestinationProvider(
            new DropboxClient(new MockHttpClient($factory), $tokens, new NullLogger(), static function (int $seconds): void {}),
            $repo,
            $logger ?? new NullLogger(),
        );
    }
}
