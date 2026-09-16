<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\OAuth;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\OAuth\OAuthClient;
use App\Service\OAuth\OAuthConsentService;
use App\Service\OAuth\OAuthException;
use App\Service\OAuth\OAuthProviderConfig;
use App\Service\OAuth\OAuthProviderRegistry;
use App\Service\OAuth\OAuthProviderSource;
use App\Service\OAuth\OAuthTokenSet;
use App\Service\OAuth\OAuthTokenStore;
use App\Service\OAuthStateService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OAuthConsentServiceTest extends TestCase
{
    private ArrayAdapter $cache;
    private InMemoryCredentialVault $vault;
    private OAuthStateService $state;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->vault = new InMemoryCredentialVault();
        $this->state = new OAuthStateService(new NullLogger(), 'test-app-secret');
    }

    public function testConsentRoundTripStoresTokensOnANewConnection(): void
    {
        $service = $this->service([
            new MockResponse(json_encode([
                'access_token' => 'at',
                'refresh_token' => 'rt',
                'expires_in' => 3_600,
                'scope' => 'Mail.Read offline_access',
            ]), ['http_code' => 200]),
        ]);

        $state = $this->stateFromUrl($service->authorizationUrl(42, Connection::TYPE_M365));
        $connection = $service->completeConsent(Connection::TYPE_M365, 'the-code', $state);

        self::assertSame(42, $connection->getOwnerId());
        self::assertSame(Connection::TYPE_M365, $connection->getType());
        self::assertSame(Connection::STATUS_CONNECTED, $connection->getStatus());
        self::assertSame(['Mail.Read', 'offline_access'], $connection->getScopes());
        self::assertSame(Connection::TYPE_M365, ($connection->getConfig() ?? [])['provider'] ?? null);

        $stored = OAuthTokenSet::fromJson($this->vault->reveal((int) $connection->getCredentialId(), 42));
        self::assertSame('rt', $stored->refreshToken);
    }

    /**
     * Re-consenting must update the existing row; otherwise every reconnect
     * leaves another half-authorized connection behind in the user's list.
     */
    public function testSecondConsentUpdatesTheExistingConnection(): void
    {
        $existing = new Connection(42, Connection::TYPE_M365, 'Microsoft 365');
        $existing->setCredentialId($this->vault->store(42, Connection::TYPE_M365, (new OAuthTokenSet('old', 'old-rt', 1))->toJson()));

        $service = $this->service([
            new MockResponse(json_encode(['access_token' => 'at-2', 'refresh_token' => 'rt-2', 'expires_in' => 3_600]), ['http_code' => 200]),
        ], $existing);

        $state = $this->stateFromUrl($service->authorizationUrl(42, Connection::TYPE_M365));
        $connection = $service->completeConsent(Connection::TYPE_M365, 'code', $state);

        self::assertSame($existing, $connection);
        self::assertCount(1, $this->vault->stored, 'the existing credential is rotated, not duplicated');
        self::assertSame('rt-2', OAuthTokenSet::fromJson($this->vault->reveal((int) $connection->getCredentialId(), 42))->refreshToken);
    }

    public function testReplayedCallbackIsRejectedBecauseTheVerifierIsSingleUse(): void
    {
        $service = $this->service([
            new MockResponse(json_encode(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3_600]), ['http_code' => 200]),
            new MockResponse(json_encode(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3_600]), ['http_code' => 200]),
        ]);

        $state = $this->stateFromUrl($service->authorizationUrl(42, Connection::TYPE_M365));
        $service->completeConsent(Connection::TYPE_M365, 'code', $state);

        $this->expectException(OAuthException::class);
        $service->completeConsent(Connection::TYPE_M365, 'code', $state);
    }

    public function testTamperedStateIsRejected(): void
    {
        $service = $this->service([]);
        $state = $this->stateFromUrl($service->authorizationUrl(42, Connection::TYPE_M365));

        $this->expectException(OAuthException::class);
        $service->completeConsent(Connection::TYPE_M365, 'code', $state.'x');
    }

    /**
     * A state signed for a different provider must not unlock this one.
     */
    public function testStateFromAnotherProviderIsRejected(): void
    {
        $service = $this->service([]);
        $foreign = $this->state->generateState('dropbox', ['owner_id' => 42, 'pkce_nonce' => 'abc']);

        $this->expectException(OAuthException::class);
        $service->completeConsent(Connection::TYPE_M365, 'code', $foreign);
    }

    /**
     * The signed state is readable by anyone who sees the URL, so a forged one
     * without the server-side verifier must not be enough to complete consent.
     */
    public function testStateWithoutAStoredVerifierIsRejected(): void
    {
        $service = $this->service([]);
        $forged = $this->state->generateState(Connection::TYPE_M365, ['owner_id' => 42, 'pkce_nonce' => 'never-issued']);

        $this->expectException(OAuthException::class);
        $service->completeConsent(Connection::TYPE_M365, 'code', $forged);
    }

    private function stateFromUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);

        return $query['state'];
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function service(array $responses, ?Connection $existing = null): OAuthConsentService
    {
        $factory = function (string $method, string $url, array $options) use (&$responses): MockResponse {
            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 200]);
        };

        $connections = $this->createStub(ConnectionRepository::class);
        $connections->method('findOneByOwnerAndType')->willReturn($existing);

        return new OAuthConsentService(
            new OAuthClient(new MockHttpClient($factory), new NullLogger()),
            new OAuthProviderRegistry([$this->source()]),
            new OAuthTokenStore($this->vault, $connections),
            $this->state,
            $connections,
            $this->cache,
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
