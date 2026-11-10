<?php

declare(strict_types=1);

namespace App\Tests\Controller\OAuth;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Security\ApiKeyScope;
use App\Service\Infrastructure\RedisService;
use App\Service\OAuth\Server\AuthorizationRequestStore;
use App\Service\OAuth\Server\ClientAssertionVerifier;
use App\Service\OAuth\Server\RegistrationPolicy;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * A cloud as a test drives it against Synaplan's OAuth2 server: it has a
 * key pair, registers, pushes authorization requests and signs its calls.
 */
trait CloudClientTrait
{
    use AuthenticatedTestTrait;

    private const IAT = 'test-initial-access-token';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $privateKey;
    private string $host;

    private function bootCloud(): void
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
        $this->forgetFailedAuthentications();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $this->privateKey = (string) $pem;
        $this->host = 'cloud-'.bin2hex(random_bytes(4)).'.example.com';
    }

    private function forgetFailedAuthentications(): void
    {
        static::getContainer()->get('limiter.oauth_client_auth_failures')->create('127.0.0.1')->reset();
    }

    /**
     * Registers the cloud; with the initial access token it is active at once.
     *
     * @param array<string, mixed>|null $metadata
     *
     * @return array<string, mixed>
     */
    private function register(?string $bearer, ?array $metadata = null): array
    {
        $this->postRegistration($metadata ?? $this->metadata(), $bearer);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function postRegistration(array $metadata, ?string $bearer = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $bearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }
        $this->client->request('POST', '/oauth/register', server: $server, content: (string) json_encode($metadata));
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(): array
    {
        return [
            'software_id' => 'nextcloud',
            'client_uri' => 'https://'.$this->host,
            'redirect_uris' => [$this->redirectUri()],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_method' => 'private_key_jwt',
            'jwks' => ['keys' => [$this->rsaJwk($this->privateKey, 'cloud-key')]],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function rsaJwk(string $privateKey, string $kid): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($privateKey) ?: throw new \RuntimeException('key'));
        self::assertIsArray($details);

        return [
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ];
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param array<string, int> $times replaces iat, exp or adds nbf
     */
    private function assertion(string $clientId, string $endpoint, ?string $privateKey = null, string $algorithm = 'RS256', ?string $kid = 'cloud-key', array $times = []): string
    {
        return JWT::encode($times + [
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => $this->issuer().$endpoint,
            'jti' => bin2hex(random_bytes(12)),
            'iat' => time(),
            'exp' => time() + 60,
        ], $privateKey ?? $this->privateKey, $algorithm, $kid);
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function tokenRequest(string $assertion, array $params): array
    {
        return $this->form('/oauth/token', $params, $assertion);
    }

    /**
     * Pushes an authorization request as the client.
     *
     * @param array<string, string> $params replace or, with '', drop a parameter of a good request
     *
     * @return array<string, mixed>
     */
    private function push(string $clientId, string $verifier, string $externalId, array $params = []): array
    {
        $request = array_filter($params + [
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'state' => 'state-1',
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'external_id' => $externalId,
        ], static fn (string $value): bool => '' !== $value);

        return $this->form('/oauth/par', $request, $this->assertion($clientId, '/oauth/par'));
    }

    /**
     * A pushed request as the consent page gets it: the id the browser was
     * sent to the page with.
     */
    private function pushedRequest(string $clientId, string $verifier, string $externalId): string
    {
        $pushed = $this->push($clientId, $verifier, $externalId);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        self::assertSame(AuthorizationRequestStore::TTL, $pushed['expires_in']);
        self::assertStringStartsWith(AuthorizationRequestStore::REQUEST_URI_PREFIX, (string) $pushed['request_uri']);

        $this->client->request('GET', '/oauth/authorize', ['client_id' => $clientId, 'request_uri' => $pushed['request_uri']]);
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/connect/cloud?request=', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);

        return (string) $query['request'];
    }

    /**
     * The person allows the cloud; returns the authorization code.
     */
    private function consent(string $clientId, User $user, string $verifier, string $externalId): string
    {
        $request = $this->pushedRequest($clientId, $verifier, $externalId);

        $this->authenticateClient($this->client, $user);
        $this->client->request('GET', '/api/v1/oauth/consent/'.$request);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(
            ['client' => 'nextcloud', 'host' => $this->host, 'external_id' => $externalId, 'scopes' => ApiKeyScope::platformLinkScopes()],
            $this->json(),
        );

        $redirect = $this->decide($request, true);
        self::assertStringStartsWith($this->redirectUri(), $redirect);
        parse_str((string) parse_url($redirect, \PHP_URL_QUERY), $back);
        self::assertSame('state-1', $back['state'] ?? null);

        $this->client->request('POST', '/api/v1/oauth/consent/'.$request, server: ['CONTENT_TYPE' => 'application/json'], content: '{"approve":true}');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a consent request is used once');

        return (string) $back['code'];
    }

    private function decide(string $request, bool $approve): string
    {
        $this->client->request('POST', '/api/v1/oauth/consent/'.$request, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['approve' => $approve]));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return (string) $this->json()['redirect'];
    }

    /**
     * The tokens of a person who just allowed the cloud. The browser's
     * session is gone afterwards: what follows is the cloud's server.
     *
     * @return array<string, mixed>
     */
    private function grant(string $clientId, User $user, string $externalId): array
    {
        $verifier = bin2hex(random_bytes(32));
        $code = $this->consent($clientId, $user, $verifier, $externalId);
        $this->client->getCookieJar()->clear();
        $tokens = $this->tokenRequest($this->assertion($clientId, '/oauth/token'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $verifier,
        ]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $tokens;
    }

    private function lifecycle(string $clientId, string $externalId, string $event): void
    {
        $this->client->request('POST', '/oauth/register/'.$clientId.'/lifecycle', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->assertion($clientId, '/oauth/register/'.$clientId.'/lifecycle'),
        ], content: (string) json_encode(['external_id' => $externalId, 'event' => $event]));
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function form(string $path, array $params, string $assertion): array
    {
        $this->client->request('POST', $path, $params + [
            'client_assertion_type' => ClientAssertionVerifier::TYPE,
            'client_assertion' => $assertion,
        ]);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($body) ? $body : [];
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
