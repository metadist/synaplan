<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PlatformInstance;
use App\Service\Http\ClientAddress;
use App\Service\OAuth\Server\ClientAuthentication;
use App\Service\OAuth\Server\ClientAuthenticationException;
use App\Service\OAuth\Server\ClientRegistrationService;
use App\Service\OAuth\Server\CloudLinkService;
use App\Service\OAuth\Server\RegistrationException;
use App\Service\PlatformLink\Exception\PlatformSsoException;
use App\Service\PlatformLink\PlatformLinksConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A cloud as OAuth2 client of Synaplan: dynamic client registration
 * (RFC 7591), its own configuration (RFC 7592), and what happens to the
 * accounts it linked.
 *
 * RFC 7592 asks for a registration access token on the configuration
 * endpoint; Synaplan takes the client assertion there instead (as bearer
 * token), so a cloud never holds a bearer secret from Synaplan.
 */
#[OA\Tag(name: 'Platform Links')]
final class OAuthClientRegistrationController extends AbstractController
{
    use OAuthEndpointResponses;

    public function __construct(
        private readonly ClientRegistrationService $registrations,
        private readonly ClientAuthentication $clients,
        private readonly CloudLinkService $links,
        private readonly PlatformLinksConfig $platformLinks,
    ) {
    }

    #[Route('/oauth/register', name: 'oauth_register', methods: ['POST'])]
    #[OA\Post(
        path: '/oauth/register',
        operationId: 'oauthRegisterClient',
        summary: 'Register a cloud as OAuth2 client (RFC 7591)',
        description: 'Open to anyone unless the installation asks for an initial access token (Authorization: Bearer). A token approves one client and is then spent. Without one the client waits for an administrator, unless registration is open.',
        tags: ['Platform Links'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/OAuthClientMetadata')),
        responses: [
            new OA\Response(response: 201, description: 'The client', content: new OA\JsonContent(ref: '#/components/schemas/OAuthClient')),
            new OA\Response(response: 400, description: 'The metadata was refused', content: new OA\JsonContent(ref: '#/components/schemas/OAuthError')),
            new OA\Response(response: 401, description: 'An initial access token is required', content: new OA\JsonContent(ref: '#/components/schemas/OAuthError')),
            new OA\Response(response: 404, description: 'Platform links are off'),
            new OA\Response(response: 429, description: 'Too many registrations from this address', content: new OA\JsonContent(ref: '#/components/schemas/OAuthError')),
        ],
    )]
    public function register(Request $request): JsonResponse
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->notFound();
        }
        $header = (string) $request->headers->get('Authorization', '');
        try {
            $client = $this->registrations->register(
                $this->metadata($request),
                str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : null,
                $this->ip($request),
            );
        } catch (RegistrationException $e) {
            return $this->refused($e);
        }

        return $this->noStore($this->json($client, Response::HTTP_CREATED));
    }

    #[Route('/oauth/register/{clientId}', name: 'oauth_client_read', methods: ['GET'])]
    #[OA\Get(
        path: '/oauth/register/{clientId}',
        operationId: 'oauthReadClient',
        summary: 'The client\'s own registration (RFC 7592)',
        security: [['Bearer' => []]],
        tags: ['Platform Links'],
        parameters: [new OA\Parameter(name: 'clientId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The client', content: new OA\JsonContent(ref: '#/components/schemas/OAuthClient')),
            new OA\Response(response: 401, description: 'A client assertion of this client is required', content: new OA\JsonContent(ref: '#/components/schemas/OAuthError')),
            new OA\Response(response: 404, description: 'Platform links are off'),
            new OA\Response(response: 429, description: 'Too many failed client authentications from this address'),
        ],
    )]
    public function read(Request $request, string $clientId): JsonResponse
    {
        $client = $this->client($request, $clientId);
        if (!$client instanceof PlatformInstance) {
            return $client;
        }

        return $this->noStore($this->json($this->registrations->read($client)));
    }

    #[Route('/oauth/register/{clientId}', name: 'oauth_client_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/oauth/register/{clientId}',
        operationId: 'oauthUpdateClient',
        summary: 'Change the client\'s registration (RFC 7592), for example to rotate its keys',
        security: [['Bearer' => []]],
        tags: ['Platform Links'],
        parameters: [new OA\Parameter(name: 'clientId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/OAuthClientMetadata')),
        responses: [
            new OA\Response(response: 200, description: 'The client', content: new OA\JsonContent(ref: '#/components/schemas/OAuthClient')),
            new OA\Response(response: 400, description: 'The metadata was refused; nothing changed', content: new OA\JsonContent(ref: '#/components/schemas/OAuthError')),
            new OA\Response(response: 401, description: 'A client assertion of this client is required', content: new OA\JsonContent(ref: '#/components/schemas/OAuthError')),
            new OA\Response(response: 404, description: 'Platform links are off'),
            new OA\Response(response: 429, description: 'Too many failed client authentications from this address'),
        ],
    )]
    public function update(Request $request, string $clientId): JsonResponse
    {
        $client = $this->client($request, $clientId);
        if (!$client instanceof PlatformInstance) {
            return $client;
        }
        try {
            return $this->noStore($this->json($this->registrations->update($client, $this->metadata($request), $this->ip($request))));
        } catch (RegistrationException $e) {
            return $this->refused($e);
        }
    }

    #[Route('/oauth/register/{clientId}', name: 'oauth_client_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/oauth/register/{clientId}',
        operationId: 'oauthDeleteClient',
        summary: 'The cloud leaves (RFC 7592): the client and every token it holds stop working',
        security: [['Bearer' => []]],
        tags: ['Platform Links'],
        parameters: [new OA\Parameter(name: 'clientId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 204, description: 'Gone'),
            new OA\Response(response: 401, description: 'A client assertion of this client is required', content: new OA\JsonContent(ref: '#/components/schemas/OAuthError')),
            new OA\Response(response: 404, description: 'Platform links are off'),
            new OA\Response(response: 429, description: 'Too many failed client authentications from this address'),
        ],
    )]
    public function delete(Request $request, string $clientId): Response
    {
        $client = $this->client($request, $clientId);
        if (!$client instanceof PlatformInstance) {
            return $client;
        }
        $this->registrations->delete($client, $this->ip($request));

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/oauth/register/{clientId}/lifecycle', name: 'oauth_client_lifecycle', methods: ['POST'])]
    #[OA\Post(
        path: '/oauth/register/{clientId}/lifecycle',
        operationId: 'oauthClientLifecycle',
        summary: 'The cloud reports that one of its accounts was deleted, disabled or enabled',
        description: 'Synaplan ends, pauses or resumes that person\'s link. The Synaplan account goes with a deleted cloud account only while that cloud alone manages it.',
        security: [['Bearer' => []]],
        tags: ['Platform Links'],
        parameters: [new OA\Parameter(name: 'clientId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['external_id', 'event'],
            properties: [
                new OA\Property(property: 'external_id', type: 'string', example: 'alice'),
                new OA\Property(property: 'event', type: 'string', enum: ['deleted', 'disabled', 'enabled']),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'What became of the link', content: new OA\JsonContent(
                required: ['success', 'account', 'status', 'message'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'account', type: 'string', enum: ['kept', 'deleted', 'absent', 'unchanged'], description: 'What happened to the Synaplan account.'),
                    new OA\Property(property: 'status', type: 'string', example: 'revoked', description: 'The link\'s status now.'),
                    new OA\Property(property: 'message', type: 'string', example: 'The account was removed.'),
                ],
            )),
            new OA\Response(response: 400, description: 'external_id or event is missing or unknown'),
            new OA\Response(response: 401, description: 'A client assertion of this client is required', content: new OA\JsonContent(ref: '#/components/schemas/OAuthError')),
            new OA\Response(response: 404, description: 'Platform links are off'),
            new OA\Response(response: 409, description: 'The account was removed before and cannot be enabled'),
            new OA\Response(response: 429, description: 'Too many failed client authentications from this address'),
        ],
    )]
    public function lifecycle(Request $request, string $clientId): JsonResponse
    {
        $client = $this->client($request, $clientId, ['/oauth/register/'.$clientId.'/lifecycle'], false);
        if (!$client instanceof PlatformInstance) {
            return $client;
        }
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        try {
            $result = $this->links->lifecycle(
                $client,
                is_string($body['external_id'] ?? null) ? $body['external_id'] : '',
                is_string($body['event'] ?? null) ? $body['event'] : '',
                $this->ip($request),
            );
        } catch (PlatformSsoException $e) {
            return $this->json(['success' => false, 'error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        }

        return $this->json($result);
    }

    /**
     * The client the caller proved to be, or the answer that refuses it. A
     * client still waiting for approval may manage its registration.
     *
     * @param list<string>|null $audiences endpoint paths the assertion may name; the configuration endpoint by default
     */
    private function client(Request $request, string $clientId, ?array $audiences = null, bool $pendingOk = true): PlatformInstance|JsonResponse
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->notFound();
        }
        try {
            return $this->clients->fromBearer(
                $request,
                $clientId,
                $audiences ?? ['/oauth/register/'.$clientId, '/oauth/register'],
                $pendingOk,
            );
        } catch (ClientAuthenticationException $e) {
            return $this->clientAuthenticationFailed($e, true);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RegistrationException
     */
    private function metadata(Request $request): array
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            throw new RegistrationException('invalid_client_metadata', 'The body must be a JSON object.');
        }

        return $body;
    }

    private function refused(RegistrationException $e): JsonResponse
    {
        return $this->noStore($this->json(['error' => $e->error, 'error_description' => $e->getMessage()], $e->status));
    }

    private function ip(Request $request): string
    {
        return ClientAddress::resolve($request) ?? '';
    }
}
