<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\OAuth;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\OAuth\ConnectionAccessTokenProvider;
use App\Service\OAuth\OAuthClient;
use App\Service\OAuth\OAuthProviderConfig;
use App\Service\OAuth\OAuthProviderRegistry;
use App\Service\OAuth\OAuthProviderSource;
use App\Service\OAuth\OAuthReauthRequiredException;
use App\Service\OAuth\OAuthTokenSet;
use App\Service\OAuth\OAuthTokenStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ConnectionAccessTokenProviderTest extends TestCase
{
    private InMemoryCredentialVault $vault;

    protected function setUp(): void
    {
        $this->vault = new InMemoryCredentialVault();
    }

    /**
     * The whole point of F3: a scheduled run at 07:00 refreshes a token with
     * nobody signed in. A Security dependency would make that impossible.
     */
    public function testConstructorDoesNotDependOnSecurity(): void
    {
        $params = (new \ReflectionClass(ConnectionAccessTokenProvider::class))->getConstructor()?->getParameters() ?? [];

        foreach ($params as $param) {
            $type = $param->getType();
            self::assertFalse(
                $type instanceof \ReflectionNamedType && Security::class === $type->getName(),
                'ConnectionAccessTokenProvider must not take Security'
            );
        }
    }

    public function testValidTokenIsReturnedWithoutContactingTheProvider(): void
    {
        $connection = $this->connection(new OAuthTokenSet('at-valid', 'rt', time() + 3_600));
        $calls = [];

        $token = $this->provider([], $calls)->accessTokenFor($connection);

        self::assertSame('at-valid', $token);
        self::assertSame([], $calls, 'a token that is still valid must not trigger a refresh');
    }

    public function testExpiringTokenIsRefreshedAndPersisted(): void
    {
        // Inside the 5-minute skew: still "valid" by the clock, but a queued
        // run could reach Graph after it expires.
        $connection = $this->connection(new OAuthTokenSet('at-old', 'rt-1', time() + 60));

        $token = $this->provider([
            new MockResponse(json_encode(['access_token' => 'at-new', 'expires_in' => 3_600]), ['http_code' => 200]),
        ])->accessTokenFor($connection);

        self::assertSame('at-new', $token);

        $stored = OAuthTokenSet::fromJson($this->vault->reveal((int) $connection->getCredentialId(), 7));
        self::assertSame('at-new', $stored->accessToken);
        self::assertSame('rt-1', $stored->refreshToken, 'the refresh token must survive a refresh that omits it');
        self::assertSame(Connection::STATUS_CONNECTED, $connection->getStatus());
    }

    public function testExpiredGrantMarksTheConnectionForReauth(): void
    {
        $connection = $this->connection(new OAuthTokenSet('at', 'rt-dead', time() - 10));

        $provider = $this->provider([
            new MockResponse(json_encode(['error' => 'invalid_grant']), ['http_code' => 400]),
        ]);

        try {
            $provider->accessTokenFor($connection);
            self::fail('expected the grant to be reported as dead');
        } catch (OAuthReauthRequiredException) {
            self::assertSame(Connection::STATUS_REAUTH_REQUIRED, $connection->getStatus());
            self::assertNotNull($connection->getLastChecked());
        }
    }

    public function testMissingCredentialIsReportedAsReauthRatherThanACrash(): void
    {
        $connection = new Connection(7, Connection::TYPE_M365, 'Microsoft 365');
        $connection->setConfig(['provider' => Connection::TYPE_M365]);

        $this->expectException(OAuthReauthRequiredException::class);

        $this->provider([])->accessTokenFor($connection);
        self::assertSame(Connection::STATUS_REAUTH_REQUIRED, $connection->getStatus());
    }

    public function testRefreshNowIgnoresTheStoredExpiry(): void
    {
        $connection = $this->connection(new OAuthTokenSet('at-valid', 'rt', time() + 3_600));

        $token = $this->provider([
            new MockResponse(json_encode(['access_token' => 'at-forced', 'expires_in' => 3_600]), ['http_code' => 200]),
        ])->refreshNow($connection);

        self::assertSame('at-forced', $token);
    }

    private function connection(OAuthTokenSet $tokens): Connection
    {
        $connection = new Connection(7, Connection::TYPE_M365, 'Microsoft 365');
        $connection->setConfig(['provider' => Connection::TYPE_M365]);
        $connection->setCredentialId($this->vault->store(7, Connection::TYPE_M365, $tokens->toJson()));

        return $connection;
    }

    /**
     * @param list<MockResponse>                                              $responses
     * @param list<array{method: string, url: string, options: array<mixed>}> $captured
     */
    private function provider(array $responses, array &$captured = []): ConnectionAccessTokenProvider
    {
        $factory = function (string $method, string $url, array $options) use (&$captured, &$responses): MockResponse {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 200]);
        };

        $connections = $this->createStub(ConnectionRepository::class);

        return new ConnectionAccessTokenProvider(
            new OAuthClient(new MockHttpClient($factory), new NullLogger()),
            new OAuthTokenStore($this->vault, $connections),
            new OAuthProviderRegistry([$this->source()]),
            $connections,
            new NullLogger(),
        );
    }

    private function source(): OAuthProviderSource
    {
        return new class implements OAuthProviderSource {
            public function provider(): string
            {
                return Connection::TYPE_M365;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function toProviderConfig(): OAuthProviderConfig
            {
                return new OAuthProviderConfig(
                    provider: Connection::TYPE_M365,
                    authorizeUrl: 'https://login.example/authorize',
                    tokenUrl: 'https://login.example/token',
                    clientId: 'cid',
                    clientSecret: 'secret',
                    redirectUri: 'https://app.example/callback',
                    scopes: ['offline_access', 'Mail.Read'],
                );
            }
        };
    }
}
