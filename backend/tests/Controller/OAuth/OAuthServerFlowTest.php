<?php

declare(strict_types=1);

namespace App\Tests\Controller\OAuth;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\UserLifecycleService;
use App\Service\OAuth\Server\RegistrationPolicy;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A cloud from registration to revocation against Synaplan's OAuth2 server:
 * dynamic registration with its public key, a pushed authorization request,
 * a person's consent with PKCE, tokens obtained with private_key_jwt,
 * refresh rotation, client configuration, and revocation ending the
 * person's link.
 */
final class OAuthServerFlowTest extends WebTestCase
{
    use CloudClientTrait;

    protected function setUp(): void
    {
        $this->bootCloud();
    }

    public function testTheMetadataNamesEveryEndpointAndOnlyPrivateKeyJwt(): void
    {
        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame($this->issuer(), $body['issuer']);
        self::assertSame($this->issuer().'/oauth/token', $body['token_endpoint']);
        self::assertSame($this->issuer().'/oauth/register', $body['registration_endpoint']);
        self::assertSame($this->issuer().'/oauth/par', $body['pushed_authorization_request_endpoint']);
        self::assertTrue($body['require_pushed_authorization_requests']);
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

    public function testAnInitialAccessTokenApprovesOneClient(): void
    {
        self::assertSame('active', $this->register(self::IAT)['status']);

        $this->host = 'cloud-'.bin2hex(random_bytes(4)).'.example.com';
        $this->postRegistration($this->metadata(), self::IAT);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('invalid_token', $this->json()['error']);
        self::assertNull(
            static::getContainer()->get(PlatformInstanceRepository::class)->findOneBy(['host' => $this->host]),
            'a spent token does not leave a client behind',
        );
    }

    public function testRegistrationRefusesSecretsAndPrivateKeyMaterial(): void
    {
        $this->postRegistration(array_merge($this->metadata(), ['token_endpoint_auth_method' => 'client_secret_basic']));
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_client_metadata', $this->json()['error']);

        $metadata = $this->metadata();
        $metadata['jwks']['keys'][0]['d'] = 'secret';
        $this->postRegistration($metadata);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testAPersonConsentsAndTheCloudGetsRotatingTokensUntilItRevokes(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $user = $this->person();
        $verifier = bin2hex(random_bytes(32));

        $code = $this->consent($clientId, $user, $verifier, 'ada');
        self::assertNull(
            static::getContainer()->get(ExternalIdentityRepository::class)->findOneByTriple('nextcloud', $clientId, 'ada'),
            'allowing does not connect anything until the cloud redeems the code',
        );

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
        self::assertSame([$this->issuer(), $clientId, (string) $user->getId(), 'ada'], [$payload['iss'], $payload['client_id'], $payload['sub'], $payload['external_id']]);
        $identity = static::getContainer()->get(ExternalIdentityRepository::class)->findOneByTriple('nextcloud', $clientId, 'ada');
        self::assertInstanceOf(ExternalIdentity::class, $identity);
        self::assertSame((int) $user->getId(), $identity->getUserId());

        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']];
        $this->client->request('GET', '/api/v1/files', server: $bearer);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/api/v1/apikeys', server: $bearer);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'outside the scopes the person allowed');
        self::assertSame('insufficient_scope', $this->json()['error']);

        $this->tokenRequest($assertion, ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'a client assertion is good for one request');

        $this->client->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'no assertion, no client');
        self::assertSame('invalid_client', $this->json()['error']);
        $this->client->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId, 'client_secret' => 'guess']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'a secret is no way in');
        $this->reuseInitialAccessToken();
        $other = (string) $this->register(self::IAT)['client_id'];
        $this->tokenRequest($this->assertion($other, '/oauth/token'), ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
        self::assertFalse($this->client->getResponse()->isSuccessful(), 'another client cannot redeem this grant');

        $rotated = $this->tokenRequest($this->assertion($clientId, '/oauth/token'), ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
        self::assertResponseIsSuccessful();
        self::assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);

        $this->form('/oauth/revoke', ['token' => (string) $rotated['refresh_token']], $this->assertion($clientId, '/oauth/revoke'));
        self::assertResponseIsSuccessful();
        $this->tokenRequest($this->assertion($clientId, '/oauth/token'), ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, 'revoking the grant also ends the refresh token it replaced');
        $this->em->clear();
        $ended = static::getContainer()->get(ExternalIdentityRepository::class)->findOneByTriple('nextcloud', $clientId, 'ada');
        self::assertSame(ExternalIdentity::STATUS_REVOKED, $ended?->getStatus());
        $this->tokenRequest($this->assertion($clientId, '/oauth/token'), ['grant_type' => 'refresh_token', 'refresh_token' => $rotated['refresh_token']]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->client->request('GET', '/api/v1/files', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$rotated['access_token']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'the access token ended with the grant');
    }

    public function testTheCloudPausesAndResumesAPersonWithoutThemConnectingAgain(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $tokens = $this->grant($clientId, $this->person(), 'ada');
        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']];

        $this->lifecycle($clientId, 'ada', 'disabled');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/api/v1/files', server: $bearer);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'paused while the cloud disabled the person');

        $this->lifecycle($clientId, 'ada', 'enabled');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/api/v1/files', server: $bearer);
        self::assertResponseIsSuccessful('resumed with the same grant');

        $this->client->request('POST', '/oauth/register/'.$clientId.'/lifecycle', server: ['CONTENT_TYPE' => 'application/json'], content: '{"external_id":"ada","event":"disabled"}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'only the client itself reports');
    }

    public function testADeletedCloudAccountEndsTheGrantForGood(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $tokens = $this->grant($clientId, $this->person(), 'ada');

        $this->lifecycle($clientId, 'ada', 'deleted');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(ExternalIdentity::STATUS_REVOKED, $this->json()['status']);

        $this->client->request('GET', '/api/v1/files', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->tokenRequest($this->assertion($clientId, '/oauth/token'), ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, 'the refresh token went with the account');
    }

    public function testThePendingClientGetsNoTokensButReadsAndUpdatesItsConfiguration(): void
    {
        $clientId = (string) $this->register(null)['client_id'];

        $this->tokenRequest($this->assertion($clientId, '/oauth/token'), ['grant_type' => 'refresh_token', 'refresh_token' => 'x']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->push($clientId, bin2hex(random_bytes(32)), 'ada');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'nobody is asked to allow a client an administrator has not approved');

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

    public function testOneCloudAccountDoesNotUnlockAnother(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $user = $this->person();
        $ada = $this->grant($clientId, $user, 'ada');
        $grace = $this->grant($clientId, $user, 'grace');

        $this->lifecycle($clientId, 'ada', 'disabled');
        $this->client->request('GET', '/api/v1/files', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$ada['access_token']]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'the disabled account stays disabled');
        $this->client->request('GET', '/api/v1/files', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$grace['access_token']]);
        self::assertResponseIsSuccessful('the other account of the same person keeps working');

        $this->form('/oauth/revoke', ['token' => (string) $ada['refresh_token']], $this->assertion($clientId, '/oauth/revoke'));
        self::assertResponseIsSuccessful();
        $this->em->clear();
        $identities = static::getContainer()->get(ExternalIdentityRepository::class);
        self::assertSame(ExternalIdentity::STATUS_REVOKED, $identities->findOneByTriple('nextcloud', $clientId, 'ada')?->getStatus());
        self::assertSame(ExternalIdentity::STATUS_ACTIVE, $identities->findOneByTriple('nextcloud', $clientId, 'grace')?->getStatus());
        $this->tokenRequest($this->assertion($clientId, '/oauth/token'), ['grant_type' => 'refresh_token', 'refresh_token' => (string) $grace['refresh_token']]);
        self::assertResponseIsSuccessful('revoking one account does not spend the other');
    }

    public function testAnAccessTokenDoesNotWidenAnApiKey(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $tokens = $this->grant($clientId, $this->person(), 'mallory');
        $owner = $this->person();
        $plain = 'sk_'.bin2hex(random_bytes(20));
        $this->em->persist((new ApiKey())
            ->setOwner($owner)
            ->setKey($plain)
            ->setStatus('active')
            ->setName('Meeting notes')
            ->setScopes(['audio:transcribe']));
        $this->em->flush();

        $this->client->request('GET', '/api/v1/chats', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token'],
            'HTTP_X_API_KEY' => $plain,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request('GET', '/api/v1/chats?api_key='.$plain, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testConsentIgnoresABodyThatIsNotJson(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $request = $this->pushedRequest($clientId, bin2hex(random_bytes(32)), 'ada');
        $this->authenticateClient($this->client, $this->person());

        $this->client->request('POST', '/api/v1/oauth/consent/'.$request, server: ['CONTENT_TYPE' => 'text/plain'], content: '{"approve":true}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        $redirect = $this->decide($request, true);
        self::assertStringStartsWith($this->redirectUri(), $redirect);
    }

    public function testChangingARegistrationNeedsAnAssertionForThatEndpoint(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $this->client->request('GET', '/oauth/register/'.$clientId, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->assertion($clientId, '/oauth/token', times: ['aud' => $this->issuer()]),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'an assertion made for the token endpoint cannot read the registration');
    }

    public function testEndingThePersonsSessionsEndsTheCloudGrant(): void
    {
        $clientId = (string) $this->register(self::IAT)['client_id'];
        $user = $this->person();
        $tokens = $this->grant($clientId, $user, 'ada');

        static::getContainer()->get(UserLifecycleService::class)->endCloudGrants($user);
        $this->client->request('GET', '/api/v1/files', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAShortRegistrationTokenIsRefusedAtStartup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RegistrationPolicy('token', 'short-token');
    }
}
