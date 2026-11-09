<?php

declare(strict_types=1);

namespace App\Tests\Controller\OAuth;

use App\Entity\ExternalIdentity;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ExternalIdentityRepository;
use App\Service\Infrastructure\RedisService;
use App\Service\OAuth\Server\RegistrationPolicy;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A cloud from registration to revocation against Synaplan's OAuth2 server:
 * dynamic registration with its public key, a person's consent with PKCE,
 * tokens obtained with private_key_jwt, refresh rotation, client
 * configuration, and revocation ending the person's link.
 */
final class OAuthServerFlowTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const IAT = 'test-initial-access-token';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $privateKey;
    private string $host;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, PlatformLinksConfig::CONFIG_GROUP, PlatformLinksConfig::KEY_ENABLED, '1');
        $this->em->flush();
        static::getContainer()->set(RegistrationPolicy::class, new RegistrationPolicy('pending', self::IAT));
        static::getContainer()->get(RedisService::class)->delete('oauth:register:'.sha1('127.0.0.1'));
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $this->privateKey = (string) $pem;
        $this->host = 'cloud-'.bin2hex(random_bytes(4)).'.example.com';
    }

    public function testTheMetadataNamesEveryEndpointAndOnlyPrivateKeyJwt(): void
    {
        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame($this->issuer(), $body['issuer']);
        self::assertSame($this->issuer().'/oauth/token', $body['token_endpoint']);
        self::assertSame($this->issuer().'/oauth/register', $body['registration_endpoint']);
        self::assertSame(['private_key_jwt'], $body['token_endpoint_auth_methods_supported']);
        self::assertSame(['S256'], $body['code_challenge_methods_supported']);
    }

    public function testRegistrationWaitsForApprovalUnlessItPresentsTheInitialAccessToken(): void
    {
        $pending = $this->register(null);
        self::assertSame('pending', $pending['status']);
        self::assertArrayNotHasKey('client_secret', $pending);

        $active = $this->register(self::IAT);
        self::assertSame('active', $active['status']);
        self::assertSame('private_key_jwt', $active['token_endpoint_auth_method']);
    }

    public function testRegistrationRefusesSecretsAndPrivateKeyMaterial(): void
    {
        $this->client->request('POST', '/oauth/register', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(
            array_merge($this->metadata(), ['token_endpoint_auth_method' => 'client_secret_basic']),
        ));
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_client_metadata', $this->json()['error']);

        $metadata = $this->metadata();
        $metadata['jwks']['keys'][0]['d'] = 'secret';
        $this->client->request('POST', '/oauth/register', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($metadata));
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testAPersonConsentsAndTheCloudGetsRotatingTokensUntilItRevokes(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $user = $this->person();
        $verifier = bin2hex(random_bytes(32));

        $code = $this->consent($clientId, $user, $verifier, 'ada');
        $identity = static::getContainer()->get(ExternalIdentityRepository::class)->findOneByTriple('nextcloud', $clientId, 'ada');
        self::assertInstanceOf(ExternalIdentity::class, $identity);
        self::assertSame((int) $user->getId(), $identity->getUserId());

        $this->client->getCookieJar()->clear();
        $assertion = $this->assertion($clientId, '/oauth/token');
        $tokens = $this->tokenRequest($assertion, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $verifier,
        ]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent().' '.$this->client->getResponse()->getStatusCode());
        self::assertNotEmpty($tokens['access_token']);
        self::assertNotEmpty($tokens['refresh_token']);
        [$header, $payload] = array_map(
            static fn (string $part): array => (array) json_decode((string) base64_decode(strtr($part, '-_', '+/')), true),
            array_slice(explode('.', (string) $tokens['access_token']), 0, 2),
        );
        self::assertSame('at+jwt', $header['typ']);
        self::assertSame([$this->issuer(), $clientId, (string) $user->getId()], [$payload['iss'], $payload['client_id'], $payload['sub']]);

        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']];
        $this->client->request('GET', '/api/v1/files', server: $bearer);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/api/v1/apikeys', server: $bearer);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'outside the scopes the person allowed');
        self::assertSame('insufficient_scope', $this->json()['error']);

        $this->tokenRequest($assertion, ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'a client assertion is good for one request');

        $rotated = $this->tokenRequest($this->assertion($clientId, '/oauth/token'), ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
        self::assertResponseIsSuccessful();
        $this->tokenRequest($this->assertion($clientId, '/oauth/token'), ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, 'the old refresh token is spent');

        $this->client->request('POST', '/oauth/revoke', [
            'token' => $rotated['refresh_token'],
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $this->assertion($clientId, '/oauth/revoke'),
        ]);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        $ended = static::getContainer()->get(ExternalIdentityRepository::class)->findOneByTriple('nextcloud', $clientId, 'ada');
        self::assertSame(ExternalIdentity::STATUS_REVOKED, $ended?->getStatus());
        $this->tokenRequest($this->assertion($clientId, '/oauth/token'), ['grant_type' => 'refresh_token', 'refresh_token' => $rotated['refresh_token']]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->client->request('GET', '/api/v1/files', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$rotated['access_token']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'the access token ended with the grant');
    }

    public function testAuthorizeNeedsPkceAndTheCloudsAccountName(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $this->client->request('GET', '/oauth/authorize', ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $this->redirectUri(), 'state' => 's', 'external_id' => 'ada']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->client->request('GET', '/oauth/authorize', ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $this->redirectUri(), 'state' => 's', 'code_challenge' => 'x', 'code_challenge_method' => 'S256']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testThePendingClientGetsNoTokensButReadsAndUpdatesItsConfiguration(): void
    {
        $clientId = (string) $this->register(null)['client_id'];

        $this->tokenRequest($this->assertion($clientId, '/oauth/token'), ['grant_type' => 'refresh_token', 'refresh_token' => 'x']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->client->request('GET', '/oauth/register/'.$clientId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->assertion($clientId, '/oauth/register/'.$clientId)]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('pending', $this->json()['status']);

        $this->client->request('PUT', '/oauth/register/'.$clientId, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->assertion($clientId, '/oauth/register/'.$clientId),
            'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode(['app_capabilities' => ['deliveries'], 'software_version' => '2.0.0']));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(['deliveries'], $this->json()['app_capabilities']);

        $this->client->request('GET', '/oauth/register/'.$clientId, server: ['HTTP_AUTHORIZATION' => 'Bearer not-an-assertion']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function consent(string $clientId, User $user, string $verifier, string $externalId): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->client->request('GET', '/oauth/authorize', [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'state' => 'state-1',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'external_id' => $externalId,
        ]);
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/connect/cloud?request=', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        $request = (string) $query['request'];

        $this->authenticateClient($this->client, $user);
        $this->client->request('GET', '/api/v1/oauth/consent/'.$request);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(['nextcloud', $this->host, $externalId], [$this->json()['client'], $this->json()['host'], $this->json()['external_id']]);

        $this->client->request('POST', '/api/v1/oauth/consent/'.$request, server: ['CONTENT_TYPE' => 'application/json'], content: '{"approve":true}');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $redirect = (string) $this->json()['redirect'];
        self::assertStringStartsWith($this->redirectUri(), $redirect);
        parse_str((string) parse_url($redirect, \PHP_URL_QUERY), $back);
        self::assertSame('state-1', $back['state'] ?? null);

        $this->client->request('POST', '/api/v1/oauth/consent/'.$request, server: ['CONTENT_TYPE' => 'application/json'], content: '{"approve":true}');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a consent request is used once');

        return (string) $back['code'];
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function tokenRequest(string $assertion, array $params): array
    {
        $this->client->request('POST', '/oauth/token', $params + [
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
        ]);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($body) ? $body : [];
    }

    private function assertion(string $clientId, string $endpoint): string
    {
        return JWT::encode([
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => $this->issuer().$endpoint,
            'jti' => bin2hex(random_bytes(12)),
            'iat' => time(),
            'exp' => time() + 60,
        ], $this->privateKey, 'RS256', 'cloud-key');
    }

    /**
     * @return array<string, mixed>
     */
    private function register(?string $bearer): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $bearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }
        $this->client->request('POST', '/oauth/register', server: $server, content: (string) json_encode($this->metadata()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(): array
    {
        $details = openssl_pkey_get_details((openssl_pkey_get_private($this->privateKey)) ?: throw new \RuntimeException('key'));
        self::assertIsArray($details);
        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return [
            'software_id' => 'nextcloud',
            'client_uri' => 'https://'.$this->host,
            'redirect_uris' => [$this->redirectUri()],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_method' => 'private_key_jwt',
            'jwks' => ['keys' => [[
                'kty' => 'RSA',
                'kid' => 'cloud-key',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $b64($details['rsa']['n']),
                'e' => $b64($details['rsa']['e']),
            ]]],
        ];
    }

    private function issuer(): string
    {
        return rtrim((string) ($_SERVER['APP_URL'] ?? $_ENV['APP_URL'] ?? ''), '/');
    }

    private function redirectUri(): string
    {
        return 'https://'.$this->host.'/apps/synaplan/oauth/callback';
    }

    private function person(): User
    {
        $user = (new User())
            ->setMail('oauth-'.bin2hex(random_bytes(4)).'@synaplan.internal')
            ->setType('WEB')
            ->setProviderId('local')
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);

        return $body;
    }
}
