<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PlatformInstance;
use App\Service\OAuth\Server\AccessTokenValidator;
use App\Service\OAuth\Server\ClientAssertionContext;
use App\Service\OAuth\Server\ClientAssertionVerifier;
use App\Service\OAuth\Server\ClientRegistrationService;
use App\Service\OAuth\Server\CloudLinkService;
use App\Service\OAuth\Server\OAuthServerFactory;
use App\Service\OAuth\Server\Psr7;
use App\Service\OAuth\Server\RegistrationException;
use App\Service\OAuth\Server\RegistrationPolicy;
use App\Service\OAuth\Server\Repository\ScopeRepository;
use App\Service\PlatformLink\PlatformLinksConfig;
use Defuse\Crypto\Crypto;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Synaplan as OAuth2 authorization server for clouds (Nextcloud,
 * ownCloud): dynamic client registration (RFC 7591) and client
 * configuration (RFC 7592), the authorization endpoint with PKCE, the token
 * endpoint, revocation (RFC 7009) and the server metadata (RFC 8414).
 *
 * Clients authenticate with private_key_jwt only. RFC 7592 asks for a
 * registration access token on the configuration endpoint; Synaplan takes
 * the same client assertion there instead, so a cloud never holds a bearer
 * secret from Synaplan.
 */
final class OAuthServerController extends AbstractController
{
    public const AUTHORIZE_CACHE_PREFIX = 'oauth_authorize_';
    public const AUTHORIZE_TTL = 600;

    public function __construct(
        private readonly OAuthServerFactory $servers,
        private readonly ClientAssertionVerifier $assertions,
        private readonly ClientAssertionContext $assertionContext,
        private readonly ClientRegistrationService $registrations,
        private readonly AccessTokenValidator $accessTokens,
        private readonly CloudLinkService $links,
        private readonly PlatformLinksConfig $platformLinks,
        private readonly RegistrationPolicy $policy,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        #[Autowire('%env(APP_URL)%')]
        private readonly string $appUrl,
        #[Autowire('%env(FRONTEND_URL)%')]
        private readonly string $frontendUrl,
    ) {
    }

    #[Route('/.well-known/oauth-authorization-server', name: 'oauth_metadata', methods: ['GET'])]
    public function metadata(): JsonResponse
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $issuer = $this->issuer();
        $response = $this->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'registration_endpoint' => $issuer.'/oauth/register',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'jwks_uri' => $issuer.'/.well-known/jwks.json',
            'scopes_supported' => ScopeRepository::SUPPORTED,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['private_key_jwt'],
            'token_endpoint_auth_signing_alg_values_supported' => ['RS256', 'ES256'],
            'revocation_endpoint_auth_methods_supported' => ['private_key_jwt'],
            'registration_policy' => $this->policy->mode,
        ]);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    #[Route('/oauth/register', name: 'oauth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        try {
            $body = $this->jsonBody($request);
            $result = $this->registrations->register($body, $this->bearer($request), $request->getClientIp() ?? '');
        } catch (RegistrationException $e) {
            return $this->registrationError($e);
        }

        return $this->noStore($this->json($result, Response::HTTP_CREATED));
    }

    #[Route('/oauth/register/{clientId}', name: 'oauth_client_configuration', methods: ['GET', 'PUT', 'DELETE'])]
    public function configuration(Request $request, string $clientId): Response
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $instance = $this->assertions->verify(
            (string) $this->bearer($request),
            [$this->issuer().'/oauth/register/'.$clientId, $this->issuer().'/oauth/register'],
            true,
        );
        if (!$instance instanceof PlatformInstance || $instance->getInstanceId() !== $clientId) {
            return $this->json(['error' => 'invalid_token', 'error_description' => 'A client assertion of this client is required.'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
        try {
            return match ($request->getMethod()) {
                'PUT' => $this->noStore($this->json($this->registrations->update($instance, $this->jsonBody($request), $request->getClientIp() ?? ''))),
                'DELETE' => $this->deleted($instance, $request),
                default => $this->noStore($this->json($this->registrations->read($instance))),
            };
        } catch (RegistrationException $e) {
            return $this->registrationError($e);
        }
    }

    #[Route('/oauth/authorize', name: 'oauth_authorize', methods: ['GET'])]
    public function authorize(Request $request): Response
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $externalId = trim((string) $request->query->get('external_id', ''));
        if ('S256' !== $request->query->get('code_challenge_method') || '' === (string) $request->query->get('code_challenge', '')) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required.'], Response::HTTP_BAD_REQUEST);
        }
        if ('' === $externalId || mb_strlen($externalId) > 191) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'external_id names the person in the cloud and is required.'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->servers->create()->validateAuthorizationRequest(Psr7::request($request));
        } catch (OAuthServerException $e) {
            return Psr7::toSymfony($e->generateHttpResponse(Psr7::response()));
        }
        $nonce = bin2hex(random_bytes(24));
        $item = $this->cache->getItem(self::AUTHORIZE_CACHE_PREFIX.$nonce);
        $item->set($request->query->all());
        $item->expiresAfter(self::AUTHORIZE_TTL);
        $this->cache->save($item);

        return new RedirectResponse(rtrim($this->frontendUrl, '/').'/connect/cloud?request='.$nonce);
    }

    #[Route('/oauth/token', name: 'oauth_token', methods: ['POST'])]
    public function token(Request $request): Response
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $instance = $this->authenticateClient($request, '/oauth/token');
        if (!$instance instanceof PlatformInstance) {
            return $this->invalidClient();
        }
        $body = $request->request->all();
        $body['client_id'] = $instance->getInstanceId();
        try {
            $response = $this->servers->create()->respondToAccessTokenRequest(Psr7::request($request, $body), Psr7::response());
        } catch (OAuthServerException $e) {
            $response = $e->generateHttpResponse(Psr7::response());
        } finally {
            $this->assertionContext->reset();
        }

        return Psr7::toSymfony($response);
    }

    /**
     * RFC 7009. A refresh token ends the person's grant and their link with
     * the cloud: a cloud revokes it when the person disconnects there or the
     * cloud account is deleted or disabled. Unknown tokens answer 200 too.
     */
    #[Route('/oauth/revoke', name: 'oauth_revoke', methods: ['POST'])]
    public function revoke(Request $request): Response
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $instance = $this->authenticateClient($request, '/oauth/revoke');
        $this->assertionContext->reset();
        if (!$instance instanceof PlatformInstance) {
            return $this->invalidClient();
        }
        $token = (string) $request->request->get('token', '');
        $access = $this->accessTokens->validate($token, false);
        if (null !== $access) {
            if ($access['client_id'] === $instance->getInstanceId()) {
                $this->revokeAccess($access['jti']);
            }

            return new Response('', Response::HTTP_OK);
        }
        $refresh = $this->refreshTokenData($token);
        if (null !== $refresh && $refresh['client_id'] === $instance->getInstanceId() && null !== $refresh['user_id']) {
            $this->links->endByCloud($instance, $refresh['user_id'], $request->getClientIp() ?? '');
        }

        return new Response('', Response::HTTP_OK);
    }

    private function revokeAccess(string $jti): void
    {
        $this->servers->accessTokens()->revokeAccessToken($jti);
    }

    /**
     * @return array{client_id: string, user_id: int|null}|null
     */
    private function refreshTokenData(string $token): ?array
    {
        if ('' === $token) {
            return null;
        }
        try {
            $data = json_decode(Crypto::decryptWithPassword($token, $this->servers->encryptionKey()), true);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data) || !is_string($data['client_id'] ?? null)) {
            return null;
        }
        $user = $data['user_id'] ?? null;

        return [
            'client_id' => $data['client_id'],
            'user_id' => is_string($user) && ctype_digit($user) ? (int) $user : (is_int($user) ? $user : null),
        ];
    }

    private function authenticateClient(Request $request, string $endpoint): ?PlatformInstance
    {
        if (ClientAssertionVerifier::TYPE !== $request->request->get('client_assertion_type')) {
            return null;
        }
        $instance = $this->assertions->verify((string) $request->request->get('client_assertion', ''), [$this->issuer().$endpoint]);
        if (!$instance instanceof PlatformInstance) {
            return null;
        }
        $claimed = (string) $request->request->get('client_id', '');
        if ('' !== $claimed && $claimed !== $instance->getInstanceId()) {
            return null;
        }
        $this->assertionContext->verified($instance->getInstanceId());

        return $instance;
    }

    private function deleted(PlatformInstance $instance, Request $request): Response
    {
        $this->registrations->delete($instance, $request->getClientIp() ?? '');

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function invalidClient(): JsonResponse
    {
        return $this->noStore($this->json(['error' => 'invalid_client', 'error_description' => 'Client authentication failed (private_key_jwt).'], Response::HTTP_UNAUTHORIZED));
    }

    private function registrationError(RegistrationException $e): JsonResponse
    {
        return $this->noStore($this->json(['error' => $e->error, 'error_description' => $e->getMessage()], $e->status));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            throw new RegistrationException('invalid_client_metadata', 'The body must be a JSON object.');
        }

        return $body;
    }

    private function bearer(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : null;
    }

    private function issuer(): string
    {
        return rtrim($this->appUrl, '/');
    }
}
