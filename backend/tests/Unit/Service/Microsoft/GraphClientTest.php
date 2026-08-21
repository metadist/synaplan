<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Microsoft;

use App\Entity\Connection;
use App\Service\Microsoft\GraphClient;
use App\Service\Microsoft\GraphException;
use App\Service\OAuth\ConnectionAccessTokenProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GraphClientTest extends TestCase
{
    public function testMeSendsTheBearerTokenAndMapsTheAccount(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse(json_encode([
                'id' => 'abc',
                'displayName' => 'Ada Lovelace',
                'userPrincipalName' => 'ada@contoso.onmicrosoft.com',
                'mail' => 'ada@contoso.com',
            ]), ['http_code' => 200]),
        ], $captured);

        $account = $client->me($this->connection());

        self::assertSame('ada@contoso.com', $account['mail']);
        self::assertSame('Ada Lovelace', $account['displayName']);
        self::assertContains('Authorization: Bearer at-1', $captured[0]['options']['headers']);
        self::assertStringStartsWith('https://graph.microsoft.com/v1.0/me', $captured[0]['url']);
    }

    public function testListMessagesRequestsNewestFirstWithoutBodies(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse(json_encode(['value' => [
                [
                    'id' => 'm1',
                    'subject' => 'Invoice',
                    'from' => ['emailAddress' => ['address' => 'billing@acme.test']],
                    'receivedDateTime' => '2026-08-15T09:00:00Z',
                    'bodyPreview' => 'Please find…',
                    'hasAttachments' => true,
                    'isRead' => false,
                ],
            ]]), ['http_code' => 200]),
        ], $captured);

        $messages = $client->listMessages($this->connection(), 5);

        self::assertCount(1, $messages);
        self::assertSame('billing@acme.test', $messages[0]['from']);
        self::assertTrue($messages[0]['hasAttachments']);

        $query = $captured[0]['options']['query'];
        self::assertSame('5', $query['$top']);
        self::assertSame('receivedDateTime desc', $query['$orderby']);
        self::assertStringNotContainsString('body,', $query['$select'], 'bodies are not pulled during a poll');
    }

    public function testSearchMessagesBuildsKqlAndSortsNewestFirst(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse(json_encode(['value' => [
                [
                    'id' => 'old',
                    'subject' => 'FPSenergy draft',
                    'from' => ['emailAddress' => ['address' => 'oliver@fps.test']],
                    'receivedDateTime' => '2026-08-01T09:00:00Z',
                    'bodyPreview' => 'first draft',
                ],
                [
                    'id' => 'new',
                    'subject' => 'FPSenergy final',
                    'from' => ['emailAddress' => ['address' => 'oliver@fps.test']],
                    'receivedDateTime' => '2026-08-15T09:00:00Z',
                    'bodyPreview' => 'final version',
                ],
            ]]), ['http_code' => 200]),
        ], $captured);

        $messages = $client->searchMessages($this->connection(), 'FPSenergy', 'Oliver Braun', '2026-01-01', 5);

        // $search is relevance-ranked — the client must re-sort by date.
        self::assertSame(['new', 'old'], array_column($messages, 'id'));

        $query = $captured[0]['options']['query'];
        // One quoted KQL string: terms + escaped multi-word from: + since.
        self::assertSame('"FPSenergy from:\"Oliver Braun\" received>=2026-01-01"', $query['$search']);
        self::assertArrayNotHasKey('$orderby', $query, '$orderby cannot be combined with $search');
        self::assertStringNotContainsString('body,', $query['$select'], 'search never pulls bodies');
    }

    public function testSearchMessagesSingleWordFromIsNotQuoted(): void
    {
        $captured = [];
        $client = $this->client([new MockResponse(json_encode(['value' => []]), ['http_code' => 200])], $captured);

        $client->searchMessages($this->connection(), 'report', 'oliver@fps.test');

        self::assertSame('"report from:oliver@fps.test"', $captured[0]['options']['query']['$search']);
    }

    public function testMessageBodyConvertsHtmlToReadableText(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse(json_encode([
                'subject' => 'FPSenergy final',
                'from' => ['emailAddress' => ['address' => 'oliver@fps.test']],
                'receivedDateTime' => '2026-08-15T09:00:00Z',
                'body' => [
                    'contentType' => 'html',
                    'content' => '<html><style>p{color:red}</style><body><p>Hello&nbsp;team,</p><p>the numbers are <b>up</b>.</p></body></html>',
                ],
            ]), ['http_code' => 200]),
        ], $captured);

        $message = $client->messageBody($this->connection(), 'msg-1');

        self::assertSame('oliver@fps.test', $message['from']);
        self::assertStringContainsString('Hello team,', $message['body']);
        self::assertStringContainsString('the numbers are up.', $message['body']);
        self::assertStringNotContainsString('<p>', $message['body']);
        self::assertStringNotContainsString('color:red', $message['body'], 'style blocks must not leak into the text');
        self::assertStringContainsString('/me/messages/msg-1', $captured[0]['url']);
    }

    public function testMessageBodyKeepsPlainTextVerbatim(): void
    {
        $client = $this->client([
            new MockResponse(json_encode([
                'subject' => 's',
                'receivedDateTime' => '2026-08-15T09:00:00Z',
                'body' => ['contentType' => 'text', 'content' => "Line one\nLine two"],
            ]), ['http_code' => 200]),
        ]);

        self::assertSame("Line one\nLine two", $client->messageBody($this->connection(), 'msg-2')['body']);
    }

    public function testMessageLimitIsClamped(): void
    {
        $captured = [];
        $client = $this->client([new MockResponse(json_encode(['value' => []]), ['http_code' => 200])], $captured);

        $client->listMessages($this->connection(), 5_000);

        self::assertSame('50', $captured[0]['options']['query']['$top']);
    }

    public function testThrottlingIsRetriedAfterTheRequestedDelay(): void
    {
        $slept = [];
        $captured = [];
        $client = $this->client([
            new MockResponse('{"error":{"code":"activityLimitReached"}}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '3']]),
            new MockResponse(json_encode(['id' => 'abc', 'displayName' => '', 'userPrincipalName' => '', 'mail' => 'ada@contoso.com']), ['http_code' => 200]),
        ], $captured, sleeper: static function (int $seconds) use (&$slept): void { $slept[] = $seconds; });

        $account = $client->me($this->connection());

        self::assertSame('ada@contoso.com', $account['mail']);
        self::assertSame([3], $slept, 'Retry-After must be honoured verbatim');
    }

    public function testRetriesAreBoundedAndThenFailHonestly(): void
    {
        $slept = [];
        $captured = [];
        $client = $this->client([
            new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '1']]),
            new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '1']]),
            new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '1']]),
        ], $captured, sleeper: static function (int $seconds) use (&$slept): void { $slept[] = $seconds; });

        $this->expectException(GraphException::class);

        try {
            $client->me($this->connection());
        } finally {
            self::assertCount(2, $slept, 'a scheduled run must not retry forever');
        }
    }

    public function testExpiredTokenIsRefreshedOnceOnA401(): void
    {
        $tokens = $this->createMock(ConnectionAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->willReturn('at-stale');
        $tokens->expects(self::once())->method('refreshNow')->willReturn('at-fresh');

        $captured = [];
        $factory = function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = $options;

            return 1 === count($captured)
                ? new MockResponse('{}', ['http_code' => 401])
                : new MockResponse(json_encode(['id' => 'abc', 'displayName' => '', 'userPrincipalName' => '', 'mail' => 'a@b.c']), ['http_code' => 200]);
        };

        $client = new GraphClient(new MockHttpClient($factory), $tokens, new NullLogger());
        $client->me($this->connection());

        self::assertContains('Authorization: Bearer at-fresh', $captured[1]['headers']);
    }

    public function testRepeated401IsReportedRatherThanLooping(): void
    {
        $tokens = $this->createStub(ConnectionAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->willReturn('at');
        $tokens->method('refreshNow')->willReturn('at-fresh');

        $client = new GraphClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 401])),
            $tokens,
            new NullLogger(),
        );

        $this->expectException(GraphException::class);
        $client->me($this->connection());
    }

    public function testGraphErrorCodeIsSurfaced(): void
    {
        $client = $this->client([
            new MockResponse(json_encode(['error' => [
                'code' => 'ErrorAccessDenied',
                'message' => 'Access is denied. Check credentials and try again.',
            ]]), ['http_code' => 403]),
        ]);

        $this->expectException(GraphException::class);
        $this->expectExceptionMessageMatches('/ErrorAccessDenied/');

        $client->me($this->connection());
    }

    public function testCreateEventPostsTheTransactionIdAndMapsTheWebLink(): void
    {
        $captured = [];
        $client = $this->client([
            new MockResponse(json_encode([
                'id' => 'evt-1',
                'webLink' => 'https://outlook.office.com/calendar/item/evt-1',
            ]), ['http_code' => 201]),
        ], $captured);

        $start = new \DateTimeImmutable('2026-08-22T10:00:00', new \DateTimeZone('UTC'));
        $result = $client->createEvent(
            $this->connection(),
            transactionId: 'synaplan-f0-m42-e0',
            subject: 'Marketing Strategy',
            start: $start,
            end: $start->add(new \DateInterval('PT1H')),
            timezone: 'UTC',
            body: 'Prep notes',
            location: 'Room 5',
            attendees: ['sanam@example.com'],
        );

        self::assertTrue($result['created']);
        self::assertSame('evt-1', $result['id']);
        self::assertStringContainsString('outlook.office.com', $result['webLink']);

        self::assertSame('POST', $captured[0]['method']);
        self::assertStringEndsWith('/me/events', $captured[0]['url']);
        $payload = json_decode((string) $captured[0]['options']['body'], true);
        self::assertSame('synaplan-f0-m42-e0', $payload['transactionId']);
        self::assertSame('Marketing Strategy', $payload['subject']);
        self::assertSame('2026-08-22T10:00:00', $payload['start']['dateTime']);
        self::assertSame('UTC', $payload['start']['timeZone']);
        self::assertSame('sanam@example.com', $payload['attendees'][0]['emailAddress']['address']);
    }

    public function testCreateEventTreatsA409AsAlreadyDeliveredNotAnError(): void
    {
        $client = $this->client([
            new MockResponse('{"error":{"code":"ErrorPropertyValidationFailure"}}', ['http_code' => 409]),
        ]);

        $start = new \DateTimeImmutable('2026-08-22T10:00:00', new \DateTimeZone('UTC'));
        $result = $client->createEvent(
            $this->connection(),
            'synaplan-f0-m42-e0',
            'Meeting',
            $start,
            $start->add(new \DateInterval('PT1H')),
            'UTC',
        );

        self::assertFalse($result['created'], 'a repeated transactionId means the event already exists');
        self::assertSame('', $result['id']);
    }

    public function testSendMailPostsTheMessageWithBase64Attachments(): void
    {
        $captured = [];
        $client = $this->client([new MockResponse('', ['http_code' => 202])], $captured);

        $client->sendMail(
            $this->connection(),
            ['owner@example.com'],
            'Your Synaplan results',
            'Here you go.',
            [['name' => 'meeting.ics', 'contentBytes' => base64_encode('BEGIN:VCALENDAR'), 'contentType' => 'text/calendar']],
        );

        self::assertSame('POST', $captured[0]['method']);
        self::assertStringEndsWith('/me/sendMail', $captured[0]['url']);
        $payload = json_decode((string) $captured[0]['options']['body'], true);
        self::assertTrue($payload['saveToSentItems']);
        self::assertSame('owner@example.com', $payload['message']['toRecipients'][0]['emailAddress']['address']);
        self::assertSame('meeting.ics', $payload['message']['attachments'][0]['name']);
        self::assertSame('#microsoft.graph.fileAttachment', $payload['message']['attachments'][0]['@odata.type']);
    }

    public function testSendMailFailureIsSurfacedWithTheGraphError(): void
    {
        $client = $this->client([
            new MockResponse('{"error":{"code":"ErrorSendAsDenied","message":"not allowed"}}', ['http_code' => 403]),
        ]);

        $this->expectException(GraphException::class);
        $this->expectExceptionMessageMatches('/ErrorSendAsDenied/');

        $client->sendMail($this->connection(), ['owner@example.com'], 's', 'b');
    }

    private function connection(): Connection
    {
        $connection = new Connection(7, Connection::TYPE_M365, 'Microsoft 365');
        $connection->setConfig(['provider' => Connection::TYPE_M365]);

        return $connection;
    }

    /**
     * @param list<MockResponse>                                              $responses
     * @param list<array{method: string, url: string, options: array<mixed>}> $captured
     * @param (\Closure(int): void)|null                                      $sleeper
     */
    private function client(array $responses, array &$captured = [], ?\Closure $sleeper = null): GraphClient
    {
        $factory = function (string $method, string $url, array $options) use (&$captured, &$responses): MockResponse {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 200]);
        };

        $tokens = $this->createStub(ConnectionAccessTokenProvider::class);
        $tokens->method('accessTokenFor')->willReturn('at-1');

        return new GraphClient(
            new MockHttpClient($factory),
            $tokens,
            new NullLogger(),
            $sleeper ?? static function (int $seconds): void {},
        );
    }
}
