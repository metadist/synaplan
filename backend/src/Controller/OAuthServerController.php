<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Http\ClientAddress;
use App\Service\OAuth\Server\AuthorizationRequestStore;
use App\Service\OAuth\Server\ClientAssertionVerifier;
use App\Service\OAuth\Server\ClientAuthentication;
use App\Service\OAuth\Server\ClientAuthenticationException;
use App\Service\OAuth\Server\ClientRegistrationService;
use App\Service\OAuth\Server\GrantContext;
use App\Service\OAuth\Server\OAuthServerFactory;
use App\Service\OAuth\Server\Psr7;
use App\Service\OAuth\Server\PushedAuthorizationService;
use App\Service\OAuth\Server\Repository\ScopeRepository;
use App\Service\OAuth\Server\TokenRevocationService;
use App\Service\PlatformLink\PlatformLinksConfig;
use League\OAuth2\Server\Exception\OAuthServerException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Synaplan as OAuth2 authorization server for clouds (Nextcloud,
 * ownCloud): the server metadata (RFC 8414), pushed authorization requests
 * (RFC 9126), the authorization endpoint, the token endpoint and revocation
 * (RFC 7009). Clients register in {@see OAuthClientRegistrationController}.
 *
 * Clients authenticate with private_key_jwt only, so a cloud never holds a
 * secret from Synaplan. An authorization starts with a pushed request and
 * no other way: it names the cloud account being linked, and only the
 * cloud's server may say which one that is.
 */
#[OA\Tag(name: 'Platform Links')]
final class OAuthServerController extends AbstractController
{
    use OAuthEndpointResponses;

    private const ASSERTION_PROPERTIES = [
        'client_assertion_type' => ClientAssertionVerifier::TYPE,
        'client_assertion' => 'A JWT signed with a key from the client\'s jwks: iss and sub the client_id, aud this endpoint or the issuer, a fresh jti, exp at most five minutes ahead.',
    ];

    public function __construct(
        private readonly OAuthServerFactory $servers,
        private readonly ClientAuthentication $clients,
        private readonly PushedAuthorizationService $pushed,
        private readonly TokenRevocationService $revocation,
        private readonly ClientRegistrationService $registrations,
        private readonly PlatformLinksConfig $platformLinks,
        private readonly GrantContext $context,
        #[Autowire('%env(APP_URL)%')]
        private readonly string $appUrl,
        #[Autowire('%env(FRONTEND_URL)%')]
        private readonly string $frontendUrl,
    ) {
    }

    #[Route('/.well-known/oauth-authorization-server', name: 'oauth_metadata', methods: ['GET'])]
    #[OA\Get(
        path: '/.well-known/oauth-authorization-server',
        operationId: 'oauthServerMetadata',
        summary: 'OAuth2 authorization server metadata (RFC 8414)',
        tags: ['Platform Links'],
        responses: [
            new OA\Response(response: 200, description: 'Metadata', content: new OA\JsonContent(
                required: ['issuer', 'authorization_endpoint', 'token_endpoint', 'pushed_authorization_request_endpoint', 'require_pushed_authorization_requests', 'registration_endpoint', 'revocation_endpoint', 'jwks_uri', 'scopes_supported', 'registration_policy'],
                properties: [
                    new OA\Property(property: 'issuer', type: 'string', example: 'https://synaplan.example'),
                    new OA\Property(property: 'authorization_endpoint', type: 'string', example: 'https://synaplan.example/oauth/authorize'),
                    new OA\Property(property: 'token_endpoint', type: 'string', example: 'https://synaplan.example/oauth/token'),
                    new OA\Property(property: 'pushed_authorization_request_endpoint', type: 'string', example: 'https://synaplan.example/oauth/par'),
                    new OA\Property(property: 'require_pushed_authorization_requests', type: 'boolean', example: true),
                    new OA\Property(property: 'registration_endpoint', type: 'string', example: 'https://synaplan.example/oauth/register'),
                    new OA\Property(property: 'revocation_endpoint', type: 'string', example: 'https://synaplan.example/oauth/revoke'),
                    new OA\Property(property: 'jwks_uri', type: 'string', example: 'https://synaplan.example/.well-known/jwks.json'),
                    new OA\Property(property: 'scopes_supported', type: 'array', items: new OA\Items(type: 'string'), example: ['chat', 'files']),
                    new OA\Property(property: 'response_types_supported', type: 'array', items: new OA\Items(type: 'string'), example: ['code']),
                    new OA\Property(property: 'grant_types_supported', type: 'array', items: new OA\Items(type: 'string'), example: ['authorization_code', 'refresh_token']),
                    new OA\Property(property: 'code_challenge_methods_supported', type: 'array', items: new OA\Items(type: 'string'), example: ['S256']),
                    new OA\Property(property: 'token_endpoint_auth_methods_supported', type: 'array', items: new OA\Items(type: 'string'), example: ['private_key_jwt']),
                    new OA\Property(property: 'token_endpoint_auth_signing_alg_values_supported', type: 'array', items: new OA\Items(type: 'string'), example: ['RS256', 'ES256']),
                    new OA\Property(property: 'revocation_endpoint_auth_methods_supported', type: 'array', items: new OA\Items(type: 'string'), example: ['private_key_jwt']),
                    new OA\Property(property: 'registration_policy', type: 'string', enum: ['pending', 'open', 'token'], description: 'Whether a new client waits for an admin, is active at once, or needs the initial access token.'),
                ],
            )),
            new OA\Response(response: 404, description: 'Platform links are off'),
        ],
    )]
    public function metadata(): JsonResponse
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->notFound();
        }
        $issuer = rtrim($this->appUrl, '/');
        $response = $this->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'pushed_authorization_request_endpoint' => $issuer.'/oauth/par',
            'require_pushed_authorization_requests' => true,
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
            'registration_policy' => $this->registrations->policyMode(),
        ]);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    #[Route('/oauth/par', name: 'oauth_par', methods: ['POST'])]
    #[OA\Post(
        path: '/oauth/par',
        operationId: 'oauthPushAuthorizationRequest',
        summary: 'Push an authorization request (RFC 9126); the only way to start one',
        tags: ['Platform Links'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'application/x-www-form-urlencoded', schema: new OA\Schema(
            required: ['client_assertion_type', 'client_assertion', 'response_type', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'external_id'],
            properties: [
                new OA\Property(property: 'client_assertion_type', type: 'string', example: self::ASSERTION_PROPERTIES['client_assertion_type']),
                new OA\Property(property: 'client_assertion', type: 'string', description: self::ASSERTION_PROPERTIES['client_assertion']),
                new OA\Property(property: 'response_type', type: 'string', example: 'code'),
                new OA\Property(property: 'redirect_uri', type: 'string', example: 'https://cloud.example.com/apps/synaplan/link/callback'),
                new OA\Property(property: 'state', type: 'string'),
                new OA\Property(property: 'scope', type: 'string', example: 'chat files', description: 'Space-separated; the default set when left out.'),
                new OA\Property(property: 'code_challenge', type: 'string'),
                new OA\Property(property: 'code_challenge_method', type: 'string', example: 'S256'),
                new OA\Property(property: 'external_id', type: 'string', example: 'alice', description: 'The cloud account being linked: the person signed in to the cloud right now.'),
            ],
        ))),
        responses: [
            new OA\Response(response: 201, description: 'The request is kept', content: new OA\JsonContent(
                required: ['request_uri', 'expires_in'],
                properties: [
                    new OA\Property(property: 'request_uri', type: 'string', example: 'urn:ietf:params:oauth:request_uri:6f1c…', description: 'Send the browser to the authorization endpoint with this and client_id.'),
                    new OA\Property(property: 'expires_in', type: 'integer', example: 600),
                ],
            )),
            new OA\Response(response: 400, description: 'The request is not one Synaplan would ask consent for', content: new OA\JsonContent(ref: '#/components/schemas/OAuthError')),
            new OA\Response(response: 401, description: 'Client authentication failed', content: new OA\JsonContent(ref: '#/components/schemas/OAuthError')),
            new OA\Response(response: 404, description: 'Platform links are off'),
            new OA\Response(response: 429, description: 'Too many failed client authentications from this address'),
        ],
    )]
    public function pushAuthorizationRequest(Request $request): JsonResponse
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->notFound();
        }
        try {
            $client = $this->clients->fromForm($request, '/oauth/par');
            $requestUri = $this->pushed->push($client, $request->request->all());
        } catch (ClientAuthenticationException $e) {
            return $this->clientAuthenticationFailed($e);
        } catch (OAuthServerException $e) {
            // Never a redirect: this is the cloud's server, not a browser.
            return $this->noStore($this->json($e->getPayload(), $e->getHttpStatusCode()));
        }

        return $this->noStore($this->json(
            ['request_uri' => $requestUri, 'expires_in' => AuthorizationRequestStore::TTL],
            Response::HTTP_CREATED,
        ));
    }

    #[Route('/oauth/authorize', name: 'oauth_authorize', methods: ['GET'])]
    #[OA\Get(
        path: '/oauth/authorize',
        operationId: 'oauthAuthorize',
        summary: 'Send the person to the consent page for a pushed authorization request',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'client_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'request_uri', in: 'query', required: true, description: 'From the pushed authorization request endpoint.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 302, description: 'To the consent page; it says so when the request is unknown or ran out'),
            new OA\Response(response: 404, description: 'Platform links are off'),
        ],
    )]
    public function authorize(Request $request): Response
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->notFound();
        }
        $id = $this->pushed->resolve(
            (string) $request->query->get('client_id', ''),
            (string) $request->query->get('request_uri', ''),
        );

        // An unknown request goes to the page as well: the person reads what
        // happened there, in their language, and not a JSON error.
        return new RedirectResponse(rtrim($this->frontendUrl, '/').'/connect/cloud'.(null !== $id ? '?request='.$id : ''));
    }

    #[Route('/oauth/token', name: 'oauth_token', methods: ['POST'])]
    #[OA\Post(
        path: '/oauth/token',
        operationId: 'oauthToken',
        summary: 'Exchange an authorization code or a refresh token for tokens',
        tags: ['Platform Links'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'application/x-www-form-urlencoded', schema: new OA\Schema(
            required: ['grant_type', 'client_assertion_type', 'client_assertion'],
            properties: [
                new OA\Property(property: 'grant_type', type: 'string', enum: ['authorization_code', 'refresh_token']),
                new OA\Property(property: 'client_assertion_type', type: 'string', example: self::ASSERTION_PROPERTIES['client_assertion_type']),
                new OA\Property(property: 'client_assertion', type: 'string', description: self::ASSERTION_PROPERTIES['client_assertion']),
                new OA\Property(property: 'code', type: 'string', description: 'With authorization_code.'),
                new OA\Property(property: 'redirect_uri', type: 'string', description: 'With authorization_code; the one the request named.'),
                new OA\Property(property: 'code_verifier', type: 'string', description: 'With authorization_code (PKCE).'),
                new OA\Property(property: 'refresh_token', type: 'string', description: 'With refresh_token. Each one works once; the answer carries the next.'),
                new OA\Property(property: 'scope', type: 'string', description: 'With refresh_token, to narrow the scopes.'),
            ],
        ))),
        responses: [
            new OA\Response(response: 200, description: 'Tokens', content: new OA\JsonContent(
                required: ['token_type', 'expires_in', 'access_token', 'refresh_token'],
                properties: [
                    new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                    new OA\Property(property: 'expires_in', type: 'integer', example: 900),
                    new OA\Property(property: 'access_token', type: 'string', description: 'A JWT (RFC 9068), signed with a key from jwks_uri.'),
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ],
            )),
            new OA\Response(response: 400, description: 'The grant was refused', content: new OA\JsonContent(ref: '#/components/schemas/OAuthError')),
            new OA\Response(response: 401, description: 'Client authentication failed', content: new OA\JsonContent(ref: '#/components/schemas/OAuthError')),
            new OA\Response(response: 404, description: 'Platform links are off'),
            new OA\Response(response: 429, description: 'Too many failed client authentications from this address'),
        ],
    )]
    public function token(Request $request): Response
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->notFound();
        }
        try {
            $this->context->setIp(ClientAddress::resolve($request) ?? '');
            $this->clients->assertNotThrottled($request);
            $response = $this->servers->create()->respondToAccessTokenRequest(Psr7::request($request, $request->request->all()), Psr7::response());
        } catch (ClientAuthenticationException $e) {
            return $this->clientAuthenticationFailed($e);
        } catch (OAuthServerException $e) {
            if ('invalid_client' === $e->getErrorType()) {
                $this->clients->failed($request);
            }
            $response = $e->generateHttpResponse(Psr7::response());
        }

        return Psr7::toSymfony($response);
    }

    #[Route('/oauth/revoke', name: 'oauth_revoke', methods: ['POST'])]
    #[OA\Post(
        path: '/oauth/revoke',
        operationId: 'oauthRevoke',
        summary: 'Revoke a token (RFC 7009); a refresh token ends the person\'s link with the cloud',
        tags: ['Platform Links'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'application/x-www-form-urlencoded', schema: new OA\Schema(
            required: ['token', 'client_assertion_type', 'client_assertion'],
            properties: [
                new OA\Property(property: 'token', type: 'string', description: 'An access or refresh token of this client.'),
                new OA\Property(property: 'client_assertion_type', type: 'string', example: self::ASSERTION_PROPERTIES['client_assertion_type']),
                new OA\Property(property: 'client_assertion', type: 'string', description: self::ASSERTION_PROPERTIES['client_assertion']),
            ],
        ))),
        responses: [
            new OA\Response(response: 200, description: 'Done; also for a token that is unknown or another client\'s'),
            new OA\Response(response: 401, description: 'Client authentication failed', content: new OA\JsonContent(ref: '#/components/schemas/OAuthError')),
            new OA\Response(response: 404, description: 'Platform links are off'),
            new OA\Response(response: 429, description: 'Too many failed client authentications from this address'),
        ],
    )]
    public function revoke(Request $request): Response
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->notFound();
        }
        try {
            $client = $this->clients->fromForm($request, '/oauth/revoke');
        } catch (ClientAuthenticationException $e) {
            return $this->clientAuthenticationFailed($e);
        }
        $this->revocation->revoke($client, (string) $request->request->get('token', ''), ClientAddress::resolve($request) ?? '');

        return new Response('', Response::HTTP_OK);
    }
}
