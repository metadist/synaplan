<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Microsoft;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Microsoft\GraphClient;
use App\Service\Microsoft\M365MailSender;
use App\Service\OAuth\ConnectionAccessTokenProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * "Email write with Microsoft Office": result mails go out from the user's
 * own M365 mailbox when (and only when) a connected account carries the
 * `Mail.Send` grant — pre-expansion consents and dead connections make the
 * sender unavailable so callers fall back to system SMTP.
 */
final class M365MailSenderTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'att').'.ics';
        file_put_contents($this->tempFile, 'BEGIN:VCALENDAR');
    }

    protected function tearDown(): void
    {
        @unlink($this->tempFile);
    }

    public function testAvailableOnlyWithASendCapableConnection(): void
    {
        self::assertTrue($this->sender([$this->connection(scopes: ['Mail.Read', 'Mail.Send'])], [])->isAvailableFor(1));
        self::assertFalse($this->sender([$this->connection(scopes: ['Mail.Read'])], [])->isAvailableFor(1), 'a pre-expansion consent has no Mail.Send');
        self::assertFalse(
            $this->sender([$this->connection(scopes: ['Mail.Send'], status: Connection::STATUS_REAUTH_REQUIRED)], [])->isAvailableFor(1),
            'a dead connection is not a transport',
        );
        self::assertFalse($this->sender([], [])->isAvailableFor(1));
    }

    public function testSendsTheMailWithAttachmentsFromTheConnectedMailbox(): void
    {
        $captured = [];
        $sender = $this->sender(
            [$this->connection(scopes: ['Mail.Read', 'Mail.Send'])],
            [new MockResponse('', ['http_code' => 202])],
            $captured,
        );

        $sender->sendTaskResultEmail(1, 'owner@example.com', 'Your results', 'Here you go.', [
            ['path' => $this->tempFile, 'type' => 'text/calendar'],
        ]);

        self::assertCount(1, $captured);
        self::assertStringEndsWith('/me/sendMail', $captured[0]['url']);
        $payload = json_decode($captured[0]['body'], true);
        self::assertSame('owner@example.com', $payload['message']['toRecipients'][0]['emailAddress']['address']);
        self::assertSame(base64_encode('BEGIN:VCALENDAR'), $payload['message']['attachments'][0]['contentBytes']);
        self::assertSame('text/calendar', $payload['message']['attachments'][0]['contentType']);
    }

    public function testOversizedAttachmentIsRefusedSoCallersFallBackToSmtp(): void
    {
        $sender = $this->sender([$this->connection(scopes: ['Mail.Send'])], []);

        $bigFile = tempnam(sys_get_temp_dir(), 'big');
        try {
            $handle = fopen($bigFile, 'w');
            self::assertNotFalse($handle);
            ftruncate($handle, M365MailSender::MAX_ATTACHMENT_BYTES + 1);
            fclose($handle);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/exceeds the Microsoft Graph/');
            $sender->sendTaskResultEmail(1, 'owner@example.com', 's', 'b', [['path' => $bigFile, 'type' => null]]);
        } finally {
            @unlink($bigFile);
        }
    }

    public function testWithoutASendCapableConnectionTheSendRefusesLoudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No send-capable/');

        $this->sender([], [])->sendTaskResultEmail(1, 'owner@example.com', 's', 'b');
    }

    /**
     * @param list<string> $scopes
     */
    private function connection(array $scopes, string $status = Connection::STATUS_CONNECTED): Connection
    {
        $connection = new Connection(1, Connection::TYPE_M365, 'ada@contoso.com');
        $connection->setScopes($scopes);
        $connection->setStatus($status);
        (new \ReflectionProperty(Connection::class, 'id'))->setValue($connection, 3);

        return $connection;
    }

    /**
     * @param list<Connection>                                            $connections
     * @param list<MockResponse>                                          $responses
     * @param list<array{method: string, url: string, body: string}>|null $captured
     */
    private function sender(array $connections, array $responses, ?array &$captured = null): M365MailSender
    {
        $repo = $this->createMock(ConnectionRepository::class);
        $repo->method('findByOwner')->willReturn($connections);

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses, &$captured) {
            if (null !== $captured) {
                $captured[] = [
                    'method' => $method,
                    'url' => $url,
                    'body' => is_string($options['body'] ?? null) ? $options['body'] : '',
                ];
            }

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        $tokens = $this->createStub(ConnectionAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->willReturn('at-1');

        return new M365MailSender(
            new GraphClient($http, $tokens, new NullLogger(), static function (int $seconds): void {}),
            $repo,
            new NullLogger(),
        );
    }
}
