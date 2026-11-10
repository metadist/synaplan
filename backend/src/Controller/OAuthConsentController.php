<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Http\ClientAddress;
use App\Service\OAuth\Server\AuthorizationRequestStore;
use App\Service\OAuth\Server\ConsentService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The consent page's backend. A cloud pushed an authorization request and
 * sent the browser to the page; the signed-in person sees which cloud asks
 * for which of its accounts and what it may do, and allows or denies. Each
 * request is decided once.
 *
 * Signed-in people only: no API key reaches these routes
 * ({@see \App\Security\ApiKeyScope::isSessionOnly()}).
 */
#[Route('/api/v1/oauth/consent', name: 'oauth_consent_')]
#[OA\Tag(name: 'Platform Links')]
final class OAuthConsentController extends AbstractController
{
    public function __construct(
        private readonly ConsentService $consent,
    ) {
    }

    #[Route('/{request}', name: 'show', methods: ['GET'], requirements: ['request' => AuthorizationRequestStore::ID_PATTERN])]
    #[OA\Get(
        path: '/api/v1/oauth/consent/{request}',
        operationId: 'oauthConsentShow',
        summary: 'What a cloud asks the signed-in person to allow',
        security: [['CookieAuth' => []]],
        tags: ['Platform Links'],
        parameters: [new OA\Parameter(name: 'request', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The request', content: new OA\JsonContent(
                required: ['client', 'host', 'external_id', 'scopes'],
                properties: [
                    new OA\Property(property: 'client', type: 'string', example: 'nextcloud'),
                    new OA\Property(property: 'host', type: 'string', example: 'cloud.example.com'),
                    new OA\Property(property: 'external_id', type: 'string', example: 'alice', description: 'The cloud account that gets linked.'),
                    new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string'), example: ['chat', 'files'], description: 'What the cloud may do for the person once allowed.'),
                ],
            )),
            new OA\Response(response: 401, description: 'Not signed in'),
            new OA\Response(response: 404, description: 'Unknown or expired request'),
        ],
    )]
    public function show(string $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        $described = $this->consent->describe($request);

        return null === $described
            ? $this->json(['error' => 'unknown_request'], Response::HTTP_NOT_FOUND)
            : $this->json($described);
    }

    #[Route('/{request}', name: 'decide', methods: ['POST'], requirements: ['request' => AuthorizationRequestStore::ID_PATTERN])]
    #[OA\Post(
        path: '/api/v1/oauth/consent/{request}',
        operationId: 'oauthConsentDecide',
        summary: 'Allow or deny a cloud; answers where the browser goes next',
        security: [['CookieAuth' => []]],
        tags: ['Platform Links'],
        parameters: [new OA\Parameter(name: 'request', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['approve'],
            properties: [new OA\Property(property: 'approve', type: 'boolean', example: true)],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Redirect target', content: new OA\JsonContent(
                required: ['redirect'],
                properties: [new OA\Property(property: 'redirect', type: 'string', example: 'https://cloud.example.com/apps/synaplan/link/callback?code=…&state=…')],
            )),
            new OA\Response(response: 401, description: 'Not signed in'),
            new OA\Response(response: 404, description: 'Unknown or expired request, or one that was decided before'),
        ],
    )]
    public function decide(string $request, Request $http, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        $body = json_decode($http->getContent(), true);
        $redirect = $this->consent->decide(
            $request,
            (int) $user->getId(),
            is_array($body) && true === ($body['approve'] ?? null),
            ClientAddress::resolve($http) ?? '',
        );

        return null === $redirect
            ? $this->json(['error' => 'unknown_request'], Response::HTTP_NOT_FOUND)
            : $this->json(['redirect' => $redirect]);
    }
}
