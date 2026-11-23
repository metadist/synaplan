<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Security;

use App\Service\Egress\EgressCall;
use App\Service\Security\BlockedAddressException;
use App\Service\Security\FixtureHostResolver;
use App\Service\Security\GuardedHttpFetch;
use App\Service\Security\SsrfGuard;
use App\Service\Security\TooManyRedirectsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GuardedHttpFetchTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $sent = [];

    /** @return list<array{0: string}> */
    public static function internalTargets(): array
    {
        return [
            ['http://127.0.0.1:6333/collections'],
            ['http://169.254.169.254/latest/meta-data/'],
            ['http://10.0.0.5/'],
            ['http://0x7f.0.0.1/'],
            ['http://localhost/'],
            ['//127.0.0.1/'],
            ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('internalTargets')]
    public function testARedirectToAnInternalAddressIsNeverRequested(string $location): void
    {
        $fetch = $this->fetch([
            'https://public.example.com/go' => self::redirect(302, $location),
        ]);

        try {
            $fetch->request('GET', 'https://public.example.com/go', [], 3);
            self::fail('expected the redirect to be refused');
        } catch (BlockedAddressException) {
        }

        self::assertSame(['https://public.example.com/go'], array_column($this->sent, 'url'));
    }

    public function testABlockedFirstAddressSendsNothing(): void
    {
        $fetch = $this->fetch([]);

        $this->expectException(BlockedAddressException::class);
        try {
            $fetch->request('GET', 'http://127.0.0.1/', [], 3);
        } finally {
            self::assertSame([], $this->sent);
        }
    }

    public function testFollowsPublicRedirectsAndReturnsTheLastResponse(): void
    {
        $fetch = $this->fetch([
            'https://short.example/a' => self::redirect(301, 'https://www.example.org/b'),
            'https://www.example.org/b' => self::redirect(302, '/c?x=1'),
            'https://www.example.org/c?x=1' => new MockResponse('done', ['http_code' => 200]),
        ]);

        $response = $fetch->request('GET', 'https://short.example/a', ['max_redirects' => 20], 3);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('done', $response->getContent());
        self::assertSame(
            ['https://short.example/a', 'https://www.example.org/b', 'https://www.example.org/c?x=1'],
            array_column($this->sent, 'url'),
        );
        foreach ($this->sent as $request) {
            self::assertSame(0, $request['options']['max_redirects'], 'the client itself follows nothing');
            self::assertTrue(EgressCall::isTyped($request['options']));
        }
    }

    public function testEveryHopIsPinnedToTheAddressTheGuardAccepted(): void
    {
        $fetch = $this->fetch([
            'https://short.example/a' => self::redirect(302, 'https://target.example/b'),
            'https://target.example/b' => new MockResponse('done', ['http_code' => 200]),
        ]);

        $fetch->request('GET', 'https://short.example/a', [], 3);

        self::assertSame(['short.example' => FixtureHostResolver::PUBLIC_ADDRESS], $this->sent[0]['options']['resolve']);
        self::assertSame(['target.example' => FixtureHostResolver::PUBLIC_ADDRESS], $this->sent[1]['options']['resolve']);
    }

    public function testStopsAfterTheRedirectLimit(): void
    {
        $fetch = $this->fetch([
            'https://loop.example.com/1' => self::redirect(302, '/2'),
            'https://loop.example.com/2' => self::redirect(302, '/3'),
            'https://loop.example.com/3' => self::redirect(302, '/4'),
        ]);

        try {
            $fetch->request('GET', 'https://loop.example.com/1', [], 2);
            self::fail('expected the redirect limit');
        } catch (TooManyRedirectsException) {
        }

        self::assertCount(3, $this->sent);
    }

    public function testCredentialsStayWithTheFirstHost(): void
    {
        $fetch = $this->fetch([
            'https://api.example.com/v1' => self::redirect(302, 'https://api.example.com/v2'),
            'https://api.example.com/v2' => self::redirect(302, 'https://elsewhere.example/collect'),
            'https://elsewhere.example/collect' => new MockResponse('{}', ['http_code' => 200]),
        ]);

        $fetch->request('GET', 'https://api.example.com/v1', [
            'headers' => ['Authorization' => 'Bearer person-token', 'Accept' => 'application/json'],
        ], 3);

        self::assertSame('Bearer person-token', $this->sent[1]['options']['headers']['Authorization']);
        self::assertSame(['Accept' => 'application/json'], $this->sent[2]['options']['headers']);
    }

    public function testARedirectAnswerWithoutLocationIsReturnedAsIs(): void
    {
        $fetch = $this->fetch([
            'https://www.example.org/cached' => new MockResponse('', ['http_code' => 304]),
        ]);

        self::assertSame(304, $fetch->request('GET', 'https://www.example.org/cached', [], 3)->getStatusCode());
    }

    public function testSeeOtherTurnsAPostIntoAGet(): void
    {
        $fetch = $this->fetch([
            'https://api.example.com/submit' => self::redirect(303, '/result'),
            'https://api.example.com/result' => new MockResponse('ok', ['http_code' => 200]),
        ]);

        $fetch->request('POST', 'https://api.example.com/submit', ['body' => 'a=1'], 3);

        self::assertSame(['POST', 'GET'], array_column($this->sent, 'method'));
        self::assertArrayNotHasKey('body', $this->sent[1]['options']);
    }

    private static function redirect(int $status, string $location): MockResponse
    {
        return new MockResponse('', ['http_code' => $status, 'response_headers' => ['location' => $location]]);
    }

    /**
     * @param array<string, MockResponse> $answers
     */
    private function fetch(array $answers): GuardedHttpFetch
    {
        $record = function (string $method, string $url, array $options): void {
            $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];
        };
        $client = new class($record, $answers) extends MockHttpClient {
            /**
             * @param \Closure(string, string, array<string, mixed>): void $record
             * @param array<string, MockResponse>                          $answers
             */
            public function __construct(private \Closure $record, private array $answers)
            {
                parent::__construct();
            }

            public function request(string $method, string $url, array $options = []): \Symfony\Contracts\HttpClient\ResponseInterface
            {
                ($this->record)($method, $url, $options);
                $this->setResponseFactory([$this->answers[$url] ?? new MockResponse('unexpected '.$url, ['http_code' => 500])]);

                return parent::request($method, $url, $options);
            }
        };

        return new GuardedHttpFetch($client, new SsrfGuard(new FixtureHostResolver()));
    }
}
