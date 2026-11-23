<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email;

use App\Entity\User;
use App\Service\Email\InboundEmailRelayAuth;
use App\Service\Email\InboundEmailService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class InboundEmailRelayAuthTest extends TestCase
{
    private const BODY = '{"from":"ada@example.com","to":"smart@synaplan.net","body":"Hi"}';

    public function testARequestWithoutACredentialIsNotTrusted(): void
    {
        self::assertFalse($this->auth()->trusts($this->request(self::BODY), null));
    }

    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function ages(): iterable
    {
        yield 'just signed' => [0, true];
        yield 'within the minute' => [-55, true];
        yield 'older than a minute' => [-120, false];
        yield 'from the future' => [120, false];
    }

    #[DataProvider('ages')]
    public function testASignatureIsTrustedForAMinute(int $offset, bool $trusted): void
    {
        $headers = $this->auth()->headers(self::BODY, time() + $offset);

        self::assertSame($trusted, $this->auth()->trusts($this->request(self::BODY, $headers), null));
    }

    public function testASignatureCoversTheBodyAndTheInstanceSecret(): void
    {
        $headers = $this->auth()->headers(self::BODY);

        self::assertFalse($this->auth()->trusts($this->request(str_replace('ada@', 'eve@', self::BODY), $headers), null));
        self::assertFalse((new InboundEmailRelayAuth('another-instance'))->trusts($this->request(self::BODY, $headers), null));
        self::assertFalse((new InboundEmailRelayAuth(''))->trusts($this->request(self::BODY, (new InboundEmailRelayAuth(''))->headers(self::BODY)), null));
        self::assertFalse($this->auth()->trusts($this->request(self::BODY, [InboundEmailRelayAuth::SIGNATURE_HEADER => 'x', InboundEmailRelayAuth::TIMESTAMP_HEADER => 'now']), null));
    }

    public function testAnAuthenticatedCallerIsTrustedOnlyAsAnAdministrator(): void
    {
        $signed = $this->request(self::BODY, $this->auth()->headers(self::BODY));

        self::assertTrue($this->auth()->trusts($this->request(self::BODY), $this->caller(['ROLE_USER', 'ROLE_ADMIN'])));
        self::assertFalse($this->auth()->trusts($this->request(self::BODY), $this->caller(['ROLE_USER', 'ROLE_PRO'])));
        self::assertFalse($this->auth()->trusts($signed, $this->caller(['ROLE_USER'])));
    }

    public function testThePollerSignsExactlyWhatItForwards(): void
    {
        $sent = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = $options;

            return new MockResponse('{"success":true}', ['response_headers' => ['content-type' => 'application/json']]);
        });
        $poller = new InboundEmailService($http, new NullLogger(), $this->auth());

        $result = $poller->forwardToWebhook(
            ['from' => 'ada@example.com', 'to' => 'smart@synaplan.net', 'subject' => 'Hi', 'body' => 'Grüße', 'message_id' => '<m1@example.com>', 'in_reply_to' => null],
            'https://example.test/api/v1/webhooks/email',
        );

        self::assertTrue($result['success']);
        self::assertIsArray($sent);
        $headers = [];
        foreach ($sent['headers'] as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $headers[$name] = $value;
        }
        self::assertTrue($this->auth()->trusts($this->request((string) $sent['body'], $headers), null));
        self::assertSame('ada@example.com', json_decode((string) $sent['body'], true)['from']);
    }

    private function auth(): InboundEmailRelayAuth
    {
        return new InboundEmailRelayAuth('app-secret-of-the-test-instance');
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $body, array $headers = []): Request
    {
        $request = Request::create('/api/v1/webhooks/email', 'POST', content: $body);
        $request->headers->add($headers);

        return $request;
    }

    /**
     * @param list<string> $roles
     */
    private function caller(array $roles): User
    {
        $caller = $this->createStub(User::class);
        $caller->method('getRoles')->willReturn($roles);

        return $caller;
    }
}
