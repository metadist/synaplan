<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Dropbox;

use App\Entity\Connection;
use App\Service\Dropbox\DropboxClient;
use App\Service\Dropbox\DropboxException;
use App\Service\OAuth\ConnectionAccessTokenProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DropboxClientTest extends TestCase
{
    public function testCurrentAccountSendsTheBearerTokenAndMapsTheAccount(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse(json_encode([
                'account_id' => 'dbid:abc',
                'name' => ['display_name' => 'Ada Lovelace'],
                'email' => 'ada@example.com',
            ]), ['http_code' => 200]),
        ], $captured);

        $account = $client->currentAccount($this->connection());

        self::assertSame('ada@example.com', $account['email']);
        self::assertSame('Ada Lovelace', $account['name']);
        self::assertSame('dbid:abc', $account['accountId']);
        self::assertContains('Authorization: Bearer at-1', $captured[0]['options']['headers']);
        self::assertSame('https://api.dropboxapi.com/2/users/get_current_account', $captured[0]['url']);
        self::assertSame('POST', $captured[0]['method']);
    }

    public function testUploadSendsTheApiArgHeaderAndRawBody(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse(json_encode([
                'name' => 'plan (2).docx',
                'path_display' => '/Synaplan/plan (2).docx',
            ]), ['http_code' => 200]),
        ], $captured);

        $stored = $client->upload($this->connection(), 'docx-bytes', '/Synaplan/plan.docx');

        self::assertSame('plan (2).docx', $stored['name']);
        self::assertSame('/Synaplan/plan (2).docx', $stored['path']);
        self::assertSame('https://content.dropboxapi.com/2/files/upload', $captured[0]['url']);
        self::assertContains('Content-Type: application/octet-stream', $captured[0]['options']['headers']);

        $apiArg = $this->headerValue($captured[0]['options']['headers'], 'Dropbox-API-Arg');
        $decoded = json_decode($apiArg, true);
        self::assertSame('/Synaplan/plan.docx', $decoded['path']);
        self::assertSame('add', $decoded['mode']);
        self::assertTrue($decoded['autorename'], 'rename conflict policy maps onto native autorename');
        self::assertSame('docx-bytes', $captured[0]['options']['body']);
    }

    public function testUploadOverwriteModeDisablesAutorename(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse(json_encode(['name' => 'plan.docx', 'path_display' => '/Synaplan/plan.docx']), ['http_code' => 200]),
        ], $captured);

        $client->upload($this->connection(), 'bytes', '/Synaplan/plan.docx', true);

        $decoded = json_decode($this->headerValue($captured[0]['options']['headers'], 'Dropbox-API-Arg'), true);
        self::assertSame('overwrite', $decoded['mode']);
        self::assertFalse($decoded['autorename']);
    }

    public function testUploadEscapesNonAsciiPathForTheHeader(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse(json_encode(['name' => 'plän.docx', 'path_display' => '/Synaplan/plän.docx']), ['http_code' => 200]),
        ], $captured);

        $client->upload($this->connection(), 'bytes', '/Synaplan/plän.docx');

        $apiArg = $this->headerValue($captured[0]['options']['headers'], 'Dropbox-API-Arg');
        self::assertMatchesRegularExpression('/^[\x20-\x7e]+$/', $apiArg, 'HTTP header values must be ASCII');
        self::assertSame('/Synaplan/plän.docx', json_decode($apiArg, true)['path']);
    }

    public function testA401TriggersOneForcedRefreshThenRetries(): void
    {
        $tokens = $this->createStub(ConnectionAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->willReturn('at-stale');
        $tokens->method('refreshNow')->willReturn('at-fresh');

        $captured = [];
        $factory = function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = $options;

            return 1 === count($captured)
                ? new MockResponse('{}', ['http_code' => 401])
                : new MockResponse(json_encode(['account_id' => 'dbid:abc', 'email' => 'a@b.c']), ['http_code' => 200]);
        };

        $client = new DropboxClient(new MockHttpClient($factory), $tokens, new NullLogger(), static function (int $seconds): void {});
        $account = $client->currentAccount($this->connection());

        self::assertSame('a@b.c', $account['email']);
        self::assertContains('Authorization: Bearer at-fresh', $captured[1]['headers']);
    }

    public function testDropboxErrorSummaryIsSurfaced(): void
    {
        $client = $this->client([
            new MockResponse(json_encode(['error_summary' => 'path/insufficient_space/..', 'error' => []]), ['http_code' => 409]),
        ]);

        try {
            $client->upload($this->connection(), 'bytes', '/Synaplan/plan.docx');
            self::fail('Expected a DropboxException');
        } catch (DropboxException $e) {
            self::assertStringContainsString('insufficient_space', $e->getMessage());
            self::assertSame('path/insufficient_space', $e->errorSummary);
            self::assertSame(409, $e->getCode());
        }
    }

    public function testPlainTextRejectionReasonAndRequestIdSurvive(): void
    {
        $client = $this->client([
            new MockResponse(
                'Error in call to API function "files/upload": HTTP header "Dropbox-API-Arg": could not decode input as JSON',
                ['http_code' => 400, 'response_headers' => ['x-dropbox-request-id' => 'req-42']],
            ),
        ]);

        try {
            $client->upload($this->connection(), 'bytes', '/Synaplan/plan.docx');
            self::fail('Expected a DropboxException');
        } catch (DropboxException $e) {
            self::assertStringContainsString('could not decode input as JSON', $e->getMessage());
            self::assertStringContainsString('req-42', $e->getMessage());
            self::assertSame(400, $e->getCode());
        }
    }

    public function testRejectedBearerTokenIsNotEchoedIntoTheMessage(): void
    {
        $client = $this->client([
            new MockResponse(
                'Error in call to API function "files/upload": Invalid authorization value in HTTP header "Authorization": "Bearer sl.secret-token".',
                ['http_code' => 400],
            ),
        ]);

        try {
            $client->upload($this->connection(), 'bytes', '/Synaplan/plan.docx');
            self::fail('Expected a DropboxException');
        } catch (DropboxException $e) {
            self::assertStringNotContainsString('sl.secret-token', $e->getMessage());
            self::assertStringContainsString('Bearer [redacted]', $e->getMessage());
        }
    }

    private function connection(): Connection
    {
        $connection = new Connection(7, Connection::TYPE_DROPBOX, 'Dropbox');
        $connection->setConfig(['provider' => Connection::TYPE_DROPBOX]);

        return $connection;
    }

    /**
     * @param list<string> $headers header lines as MockHttpClient reports them ("Name: value")
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
    private function client(array $responses, array &$captured = []): DropboxClient
    {
        $factory = function (string $method, string $url, array $options) use (&$captured, &$responses): MockResponse {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 200]);
        };

        $tokens = $this->createStub(ConnectionAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->willReturn('at-1');

        return new DropboxClient(
            new MockHttpClient($factory),
            $tokens,
            new NullLogger(),
            static function (int $seconds): void {},
        );
    }
}
