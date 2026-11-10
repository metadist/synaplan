<?php

declare(strict_types=1);

namespace App\Tests\Controller\OAuth;

use App\Entity\ApiKey;
use App\Entity\OAuthToken;
use App\Entity\PlatformInstance;
use App\Repository\ExternalIdentityRepository;
use App\Repository\OAuthTokenRepository;
use App\Repository\PlatformInstanceRepository;
use App\Security\ApiKeyScope;
use App\Service\PlatformLink\PlatformInstanceService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * What Synaplan's OAuth2 server refuses: an authorization the cloud's server
 * did not start, consent by anything but a signed-in person, keys it would
 * not verify with, half-applied registrations, endless guessing, and tokens
 * that outlive the client.
 */
final class OAuthServerHardeningTest extends WebTestCase
{
    use CloudClientTrait;

    protected function setUp(): void
    {
        $this->bootCloud();
    }

    protected function tearDown(): void
    {
        $this->forgetFailedAuthentications();
        parent::tearDown();
    }

    public function testAnAuthorizationStartsOnlyWithARequestTheClientPushed(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $verifier = bin2hex(random_bytes(32));

        // What a browser could be sent with: the parameters themselves,
        // naming a cloud account of the sender's choice.
        $this->client->request('GET', '/oauth/authorize', [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'state' => 's',
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'external_id' => 'ada',
        ]);
        self::assertResponseRedirects();
        self::assertStringEndsWith('/connect/cloud', (string) $this->client->getResponse()->headers->get('Location'), 'no request to consent to');

        $this->client->request('POST', '/oauth/par', ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $this->redirectUri(), 'external_id' => 'ada']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'only the client pushes');
        self::assertSame('invalid_client', $this->json()['error']);

        $this->push($clientId, $verifier, 'ada', ['code_challenge_method' => 'plain']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, 'PKCE with S256');
        $this->push($clientId, $verifier, '');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, 'the cloud account is named');
        $this->push($clientId, $verifier, 'ada', ['request_uri' => 'urn:ietf:params:oauth:request_uri:'.str_repeat('a', 48)]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->push($clientId, $verifier, 'ada', ['redirect_uri' => 'https://elsewhere.example/callback']);
        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertFalse($this->client->getResponse()->isRedirection(), 'the cloud\'s server is told, not redirected');
        $this->push($clientId, $verifier, 'ada', ['scope' => 'chat everything']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_scope', $this->json()['error']);
    }

    public function testAPushedRequestBelongsToItsClientAndToTheAccountItNamed(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $pushed = $this->push($clientId, bin2hex(random_bytes(32)), 'ada', ['scope' => 'chat memories']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->host = 'cloud-'.bin2hex(random_bytes(4)).'.example.com';
        $other = (string) $this->register(self::IAT)['client_id'];
        $this->client->request('GET', '/oauth/authorize', ['client_id' => $other, 'request_uri' => $pushed['request_uri']]);
        self::assertStringEndsWith('/connect/cloud', (string) $this->client->getResponse()->headers->get('Location'), 'another client cannot use it');

        // The browser cannot change what was pushed, whatever it appends.
        $this->client->request('GET', '/oauth/authorize', ['client_id' => $clientId, 'request_uri' => $pushed['request_uri'], 'external_id' => 'grace', 'scope' => 'files']);
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        $this->authenticateClient($this->client, $this->person());
        $this->client->request('GET', '/api/v1/oauth/consent/'.$query['request']);
        self::assertResponseIsSuccessful();
        self::assertSame('ada', $this->json()['external_id']);
        self::assertSame(['chat', 'memories'], $this->json()['scopes'], 'the page names what the cloud asked for');
    }

    public function testDenyingSendsThePersonBackWithoutALink(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $request = $this->pushedRequest($clientId, bin2hex(random_bytes(32)), 'ada');
        $this->authenticateClient($this->client, $this->person());

        $redirect = $this->decide($request, false);
        self::assertStringStartsWith($this->redirectUri(), $redirect);
        parse_str((string) parse_url($redirect, \PHP_URL_QUERY), $back);
        self::assertSame(['access_denied', 'state-1'], [$back['error'] ?? null, $back['state'] ?? null]);
        self::assertArrayNotHasKey('code', $back);
        self::assertNull(static::getContainer()->get(ExternalIdentityRepository::class)->findOneByTriple('nextcloud', $clientId, 'ada'));
    }

    public function testOnlyASignedInPersonConsents(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $request = $this->pushedRequest($clientId, bin2hex(random_bytes(32)), 'ada');

        $this->client->request('GET', '/api/v1/oauth/consent/'.$request);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // A key with full access acts for its owner everywhere else. Here it
        // would turn itself into a grant that outlives it.
        $plain = 'sk_'.bin2hex(random_bytes(20));
        $this->em->persist((new ApiKey())
            ->setOwner($this->person())
            ->setKey($plain)
            ->setStatus('active')
            ->setName('Full access')
            ->setScopes([ApiKeyScope::WILDCARD]));
        $this->em->flush();
        $key = ['HTTP_X_API_KEY' => $plain, 'CONTENT_TYPE' => 'application/json'];
        $this->client->request('GET', '/api/v1/oauth/consent/'.$request, server: $key);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request('POST', '/api/v1/oauth/consent/'.$request, server: $key, content: '{"approve":true}');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNull(static::getContainer()->get(ExternalIdentityRepository::class)->findOneByTriple('nextcloud', $clientId, 'ada'));
    }

    public function testACloudMaySignWithAP256KeyThatHasNoKid(): void
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $coordinate = static fn (string $raw): string => self::base64Url(str_pad($raw, 32, "\0", \STR_PAD_LEFT));

        $metadata = $this->metadata();
        $metadata['jwks'] = ['keys' => [['kty' => 'EC', 'crv' => 'P-256', 'x' => $coordinate($details['ec']['x']), 'y' => $coordinate($details['ec']['y']), 'ignored' => 'member']]];
        $registered = $this->register(self::IAT, $metadata);
        self::assertSame(['kty', 'use', 'alg', 'crv', 'x', 'y'], array_keys($registered['jwks']['keys'][0]), 'kept with its algorithm and nothing unknown');
        self::assertSame('ES256', $registered['jwks']['keys'][0]['alg']);
        $clientId = (string) $registered['client_id'];

        $path = '/oauth/register/'.$clientId;
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->assertion($clientId, $path, (string) $pem, 'ES256', null)]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->assertion($clientId, $path)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'another key, another algorithm');
    }

    public function testRegistrationRefusesKeysItWouldNotVerifyWith(): void
    {
        $weak = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($weak);
        openssl_pkey_export($weak, $weakPem);
        $good = $this->metadata()['jwks']['keys'][0];
        $refused = [
            'a 1024-bit RSA key' => [$this->rsaJwk((string) $weakPem, 'weak')],
            'an algorithm that is not the key\'s' => [['alg' => 'ES256'] + $good],
            'an encryption key' => [['use' => 'enc'] + $good],
            'a curve other than P-256' => [['kty' => 'EC', 'crv' => 'P-384', 'x' => 'AA', 'y' => 'AA']],
            'a symmetric key' => [['kty' => 'oct', 'k' => 'c2VjcmV0']],
            'two keys that cannot be told apart' => [$good, $good],
            'a key that is not a key' => [['kty' => 'RSA', 'n' => str_repeat('!', 400), 'e' => 'AQAB']],
            'no key' => [],
        ];
        foreach ($refused as $what => $keys) {
            $this->postRegistration(['jwks' => ['keys' => $keys]] + $this->metadata(), self::IAT);
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, $what);
            self::assertSame('invalid_client_metadata', $this->json()['error'], $what);
        }
    }

    public function testARefusedRegistrationOrUpdateChangesNothing(): void
    {
        $this->postRegistration(['delivery_url' => 'https://elsewhere.example/apps/synaplan'] + $this->metadata(), self::IAT);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $instances = static::getContainer()->get(PlatformInstanceRepository::class);
        self::assertNull($instances->findOneBy(['host' => $this->host]), 'no client is left behind');

        $clientId = (string) $this->register(self::IAT)['client_id'];
        $path = '/oauth/register/'.$clientId;
        $this->client->request('PUT', $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->assertion($clientId, $path),
            'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode([
            'redirect_uris' => ['https://'.$this->host.'/apps/synaplan/oauth/other'],
            'delivery_url' => 'https://elsewhere.example/apps/synaplan',
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->em->clear();
        self::assertSame([$this->redirectUri()], $instances->findByInstanceId($clientId)?->getRedirectUris());

        $this->postRegistration(['redirect_uris' => array_map(fn (int $i): string => $this->redirectUri().'/'.$i, range(1, 11))] + $this->metadata(), self::IAT);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_redirect_uri', $this->json()['error']);
    }

    public function testAnAddressThatKeepsFailingToAuthenticateHasToWait(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $guess = fn () => $this->client->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => 'x', 'client_id' => $clientId, 'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => 'a.b.c']);
        for ($i = 0; $i < 30; ++$i) {
            $guess();
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }
        $guess();
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame('60', $this->client->getResponse()->headers->get('Retry-After'));

        $this->form('/oauth/revoke', ['token' => 'x'], $this->assertion($clientId, '/oauth/revoke'));
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS, 'on every endpoint a client authenticates at');
        $this->client->request('GET', '/oauth/register/'.$clientId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->assertion($clientId, '/oauth/register/'.$clientId)]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $this->forgetFailedAuthentications();
        $this->client->request('GET', '/oauth/register/'.$clientId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->assertion($clientId, '/oauth/register/'.$clientId)]);
        self::assertResponseIsSuccessful();
    }

    public function testAnAdministratorRevokingTheCloudEndsEveryToken(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $admin = $this->person();
        $this->grant($clientId, $this->person(), 'ada');
        self::assertNotSame([], $this->liveTokens($clientId));

        static::getContainer()->get(PlatformInstanceService::class)->revoke($clientId, $admin, '127.0.0.1');

        $this->em->clear();
        self::assertSame([], $this->liveTokens($clientId));
        self::assertSame(PlatformInstance::STATUS_REVOKED, static::getContainer()->get(PlatformInstanceRepository::class)->findByInstanceId($clientId)?->getStatus());
    }

    /**
     * @return list<OAuthToken>
     */
    private function liveTokens(string $clientId): array
    {
        return array_values(array_filter(
            static::getContainer()->get(OAuthTokenRepository::class)->findBy(['clientId' => $clientId, 'revoked' => false]),
            static fn (OAuthToken $token): bool => OAuthToken::KIND_ASSERTION !== $token->getKind(),
        ));
    }
}
