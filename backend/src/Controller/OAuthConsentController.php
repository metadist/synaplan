<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\PlatformInstanceRepository;
use App\Service\OAuth\Server\CloudLinkService;
use App\Service\OAuth\Server\Entity\User as OAuthUser;
use App\Service\OAuth\Server\OAuthServerFactory;
use App\Service\OAuth\Server\Psr7;
use League\OAuth2\Server\Exception\OAuthServerException;
use OpenApi\Attributes as OA;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The consent page's backend. /oauth/authorize parks a checked request and
 * sends the browser to the page; the signed-in person sees which cloud asks
 * for which account and scopes, and allows or denies. Each request is used
 * once.
 */
#[Route('/api/v1/oauth/consent', name: 'oauth_consent_')]
#[OA\Tag(name: 'Platform Links')]
final class OAuthConsentController extends AbstractController
{
    public function __construct(
        private readonly OAuthServerFactory $servers,
        private readonly PlatformInstanceRepository $instances,
        private readonly CloudLinkService $links,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[Route('/{request}', name: 'show', methods: ['GET'], requirements: ['request' => '[a-f0-9]{48}'])]
    #[OA\Get(
        path: '/api/v1/oauth/consent/{request}',
        operationId: 'oauthConsentShow',
        summary: 'What a cloud asks the signed-in person to allow',
        tags: ['Platform Links'],
        parameters: [new OA\Parameter(name: 'request', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The request', content: new OA\JsonContent(
                required: ['client', 'host', 'external_id', 'scopes'],
                properties: [
                    new OA\Property(property: 'client', type: 'string', example: 'nextcloud'),
                    new OA\Property(property: 'host', type: 'string', example: 'cloud.example.com'),
                    new OA\Property(property: 'external_id', type: 'string'),
                    new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string')),
                ],
            )),
            new OA\Response(response: 404, description: 'Unknown or expired request'),
        ],
    )]
    public function show(string $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        $params = $this->parked($request);
        $instance = null === $params ? null : $this->instances->findByInstanceId((string) ($params['client_id'] ?? ''));
        if (null === $params || !$instance instanceof PlatformInstance) {
            return $this->json(['error' => 'unknown_request'], Response::HTTP_NOT_FOUND);
        }
        $scope = trim((string) ($params['scope'] ?? ''));

        return $this->json([
            'client' => $instance->getClient(),
            'host' => $instance->getHost(),
            'external_id' => (string) ($params['external_id'] ?? ''),
            'scopes' => '' === $scope ? [] : array_values(array_filter(explode(' ', $scope))),
        ]);
    }

    #[Route('/{request}', name: 'decide', methods: ['POST'], requirements: ['request' => '[a-f0-9]{48}'])]
    #[OA\Post(
        path: '/api/v1/oauth/consent/{request}',
        operationId: 'oauthConsentDecide',
        summary: 'Allow or deny a cloud; answers where the browser goes next',
        tags: ['Platform Links'],
        parameters: [new OA\Parameter(name: 'request', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['approve'],
            properties: [new OA\Property(property: 'approve', type: 'boolean')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Redirect target', content: new OA\JsonContent(
                required: ['redirect'],
                properties: [new OA\Property(property: 'redirect', type: 'string')],
            )),
            new OA\Response(response: 404, description: 'Unknown or expired request'),
        ],
    )]
    public function decide(string $request, Request $http, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        $params = $this->parked($request);
        if (null === $params) {
            return $this->json(['error' => 'unknown_request'], Response::HTTP_NOT_FOUND);
        }
        $this->cache->deleteItem(OAuthServerController::AUTHORIZE_CACHE_PREFIX.$request);
        $body = json_decode($http->getContent(), true);
        $approve = is_array($body) && true === ($body['approve'] ?? null);

        $server = $this->servers->create();
        $psr = Psr7::request(Request::create('/oauth/authorize', 'GET', $params));
        try {
            $authorization = $server->validateAuthorizationRequest($psr);
            $authorization->setUser(new OAuthUser((int) $user->getId()));
            $authorization->setAuthorizationApproved($approve);
            $response = $server->completeAuthorizationRequest($authorization, Psr7::response());
        } catch (OAuthServerException $e) {
            $response = $e->generateHttpResponse(Psr7::response());
        }
        $location = $response->getHeaderLine('Location');
        if ('' === $location) {
            return $this->json(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        }
        if ($approve) {
            $instance = $this->instances->findByInstanceId((string) $params['client_id']);
            if ($instance instanceof PlatformInstance) {
                $this->links->link((int) $user->getId(), $instance, (string) $params['external_id'], $http->getClientIp() ?? '');
            }
        }

        return $this->json(['redirect' => $location]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parked(string $request): ?array
    {
        $item = $this->cache->getItem(OAuthServerController::AUTHORIZE_CACHE_PREFIX.$request);
        $value = $item->isHit() ? $item->get() : null;

        return is_array($value) ? $value : null;
    }
}
