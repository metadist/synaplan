<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PlatformLink\Exception\PlatformInstanceNotActiveException;
use App\Service\PlatformLink\Exception\PlatformInstanceNotFoundException;
use App\Service\PlatformLink\Exception\PlatformInstanceSecretException;
use App\Service\PlatformLink\Exception\PlatformLinkCodeException;
use App\Service\PlatformLink\Exception\PlatformLinkLimitException;
use App\Service\PlatformLink\Exception\PlatformLinkValidationException;
use App\Service\PlatformLink\PlatformInstanceService;
use App\Service\PlatformLink\PlatformLinkExchangeService;
use App\Service\PlatformLink\PlatformLinkRateLimiter;
use App\Service\PlatformLink\PlatformLinksConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/platform-links', name: 'platform_links_')]
#[OA\Tag(name: 'Platform Links')]
final class PlatformLinkController extends AbstractController
{
    private const EXCHANGE_IP_LIMIT = 60;
    private const EXCHANGE_IP_WINDOW = 3600;

    public function __construct(
        private readonly PlatformLinksConfig $platformLinksConfig,
        private readonly PlatformInstanceService $instanceService,
        private readonly PlatformLinkExchangeService $exchangeService,
        private readonly PlatformLinkRateLimiter $rateLimiter,
    ) {
    }

    #[Route('/codes', name: 'codes', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/platform-links/codes',
        operationId: 'createPlatformLinkCode',
        summary: 'Issue a one-time link code and the server-built redirect',
        tags: ['Platform Links'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['instance_id', 'external_id', 'redirect_uri', 'state'],
                properties: [
                    new OA\Property(property: 'instance_id', type: 'string', example: 'pi_ab12cd34ef56'),
                    new OA\Property(property: 'external_id', type: 'string', example: 'jdoe'),
                    new OA\Property(property: 'redirect_uri', type: 'string', example: 'https://files.example.org/apps/synaplan_integration/link/callback'),
                    new OA\Property(property: 'state', type: 'string', example: 'nonce'),
                    new OA\Property(property: 'with_memories', type: 'boolean', example: false),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Redirect the browser to this URL only',
                content: new OA\JsonContent(
                    required: ['success', 'redirect'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'redirect', type: 'string', example: 'https://files.example.org/apps/synaplan_integration/link/callback?code=ab&state=nonce'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid redirect or client'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Instance pending'),
            new OA\Response(response: 404, description: 'Feature disabled'),
            new OA\Response(response: 429, description: 'Too many codes'),
        ]
    )]
    public function createCode(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user?->getId());
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = $this->jsonBody($request);

        try {
            $result = $this->instanceService->issueCode(
                $user,
                \is_string($data['instance_id'] ?? null) ? $data['instance_id'] : '',
                \is_string($data['external_id'] ?? null) ? $data['external_id'] : '',
                \is_string($data['redirect_uri'] ?? null) ? $data['redirect_uri'] : '',
                \is_string($data['state'] ?? null) ? $data['state'] : '',
                true === ($data['with_memories'] ?? false),
                $request->getClientIp() ?? '',
            );
        } catch (PlatformLinkValidationException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (PlatformInstanceNotActiveException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (PlatformInstanceNotFoundException) {
            return $this->json(['success' => false, 'error' => 'Instance not found.'], Response::HTTP_NOT_FOUND);
        } catch (PlatformLinkLimitException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $this->json(['success' => true, ...$result]);
    }

    #[Route('/exchange', name: 'exchange', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/platform-links/exchange',
        operationId: 'exchangePlatformLinkCode',
        summary: 'Exchange a link code for a scoped per-user API key',
        description: 'Server-to-server. The browser never sees the key. Replay, expiry and foreign-instance codes return the same 400.',
        tags: ['Platform Links'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['instance_id', 'instance_secret', 'code'],
                properties: [
                    new OA\Property(property: 'instance_id', type: 'string'),
                    new OA\Property(property: 'instance_secret', type: 'string'),
                    new OA\Property(property: 'code', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Key minted',
                content: new OA\JsonContent(
                    required: ['success', 'api_key', 'user', 'link_id'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'api_key',
                            type: 'object',
                            required: ['id', 'key', 'name', 'scopes'],
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'key', type: 'string'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string')),
                            ]
                        ),
                        new OA\Property(
                            property: 'user',
                            type: 'object',
                            required: ['id', 'email', 'display_name'],
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'email', type: 'string'),
                                new OA\Property(property: 'display_name', type: 'string'),
                            ]
                        ),
                        new OA\Property(property: 'link_id', type: 'integer'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid or expired code'),
            new OA\Response(response: 401, description: 'Wrong instance secret'),
            new OA\Response(response: 403, description: 'Instance pending'),
            new OA\Response(response: 404, description: 'Feature disabled'),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ]
    )]
    public function exchange(Request $request): JsonResponse
    {
        $this->guard(null);
        $ip = $request->getClientIp() ?? 'unknown';
        if (!$this->rateLimiter->allow('platform_link:exchange_attempt:'.sha1($ip), self::EXCHANGE_IP_LIMIT, self::EXCHANGE_IP_WINDOW)) {
            return $this->json(['success' => false, 'error' => 'Too many exchange attempts. Please wait and try again.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = $this->jsonBody($request);

        try {
            $result = $this->exchangeService->exchange(
                \is_string($data['instance_id'] ?? null) ? $data['instance_id'] : '',
                \is_string($data['instance_secret'] ?? null) ? $data['instance_secret'] : '',
                \is_string($data['code'] ?? null) ? $data['code'] : '',
                $ip,
            );
        } catch (PlatformInstanceSecretException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        } catch (PlatformInstanceNotActiveException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (PlatformLinkCodeException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (PlatformInstanceNotFoundException) {
            return $this->json(['success' => false, 'error' => 'Invalid or expired link code.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($result);
    }

    private function guard(?int $userId): void
    {
        if (!$this->platformLinksConfig->isEnabled($userId)) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return \is_array($data) ? $data : [];
    }
}
