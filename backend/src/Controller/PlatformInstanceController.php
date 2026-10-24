<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PlatformLink\DeliveryAddressService;
use App\Service\PlatformLink\Exception\PlatformInstanceNotFoundException;
use App\Service\PlatformLink\Exception\PlatformInstanceSecretException;
use App\Service\PlatformLink\Exception\PlatformLinkLimitException;
use App\Service\PlatformLink\Exception\PlatformLinkValidationException;
use App\Service\PlatformLink\PlatformInstanceService;
use App\Service\PlatformLink\PlatformLinksConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/platform-links/instances', name: 'platform_link_instances_')]
#[OA\Tag(name: 'Platform Links')]
final class PlatformInstanceController extends AbstractController
{
    public function __construct(
        private readonly PlatformLinksConfig $platformLinksConfig,
        private readonly PlatformInstanceService $instanceService,
        private readonly DeliveryAddressService $deliveryAddress,
    ) {
    }

    #[Route('', name: 'register', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/platform-links/instances',
        operationId: 'registerPlatformInstance',
        summary: 'Register a partner instance',
        description: 'Admin session or admin API key creates an active instance. Anonymous callers create a pending instance (rate-limited). The instance_secret is shown once.',
        tags: ['Platform Links'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['client', 'host', 'redirect_uris'],
                properties: [
                    new OA\Property(property: 'client', type: 'string', enum: ['nextcloud', 'owncloud', 'opencloud'], example: 'nextcloud'),
                    new OA\Property(property: 'host', type: 'string', example: 'https://files.example.org'),
                    new OA\Property(property: 'redirect_uris', type: 'array', items: new OA\Items(type: 'string'), example: ['https://files.example.org/apps/synaplan_integration/link/callback']),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Instance registered',
                content: new OA\JsonContent(
                    required: ['success', 'instance_id', 'instance_secret', 'status'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'instance_id', type: 'string', example: 'pi_ab12cd34ef56'),
                        new OA\Property(property: 'instance_secret', type: 'string', description: 'Shown once.'),
                        new OA\Property(property: 'status', type: 'string', enum: ['active', 'pending'], example: 'pending'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid host or redirect URI'),
            new OA\Response(response: 404, description: 'Feature disabled'),
            new OA\Response(response: 429, description: 'Too many registrations'),
        ]
    )]
    public function register(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user?->getId());
        $data = $this->jsonBody($request);

        try {
            $result = $this->instanceService->register(
                \is_string($data['client'] ?? null) ? $data['client'] : '',
                \is_string($data['host'] ?? null) ? $data['host'] : '',
                \is_array($data['redirect_uris'] ?? null) ? $data['redirect_uris'] : [],
                $user,
                $request->getClientIp() ?? '',
            );
        } catch (PlatformLinkValidationException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (PlatformLinkLimitException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $this->json(['success' => true, ...$result], Response::HTTP_CREATED);
    }

    #[Route('/self', name: 'self', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/platform-links/instances/self',
        operationId: 'getPlatformInstanceSelf',
        summary: 'Describe this instance using its credentials',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'X-Instance-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Instance-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Instance status',
                content: new OA\JsonContent(
                    required: ['success', 'status', 'host', 'client', 'delivery_url', 'capabilities', 'app_capabilities', 'notify_keys'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'status', type: 'string', example: 'pending'),
                        new OA\Property(property: 'host', type: 'string', example: 'files.example.org'),
                        new OA\Property(property: 'client', type: 'string', example: 'nextcloud'),
                        new OA\Property(property: 'delivery_url', type: 'string', example: 'https://files.example.org/index.php/apps/synaplan_integration/api/v1/inbound'),
                        new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string'), example: ['deliveries']),
                        new OA\Property(property: 'app_capabilities', type: 'array', items: new OA\Items(type: 'string'), example: ['deliveries'], description: 'Features the app itself reported.'),
                        new OA\Property(property: 'reachable', type: 'boolean', nullable: true, description: 'Whether the last delivery probe got an answer; null when never probed.'),
                        new OA\Property(
                            property: 'notify_keys',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                required: ['publicKey', 'kid'],
                                properties: [
                                    new OA\Property(property: 'publicKey', type: 'string'),
                                    new OA\Property(property: 'kid', type: 'string'),
                                ],
                            ),
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Invalid instance credentials'),
            new OA\Response(response: 404, description: 'Feature disabled or unknown instance'),
        ]
    )]
    public function self(Request $request): JsonResponse
    {
        $this->guard(null);
        $instanceId = (string) $request->headers->get('X-Instance-Id', '');
        $secret = (string) $request->headers->get('X-Instance-Secret', '');

        try {
            $result = $this->instanceService->describeSelf($instanceId, $secret);
        } catch (PlatformInstanceNotFoundException) {
            return $this->json(['success' => false, 'error' => 'Instance not found.'], Response::HTTP_NOT_FOUND);
        } catch (PlatformInstanceSecretException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['success' => true, ...$result]);
    }

    #[Route('/self', name: 'delivery_url', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/platform-links/instances/self',
        operationId: 'setPlatformInstanceDeliveryUrl',
        summary: 'Set the address Synaplan calls for this cloud',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'X-Instance-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Instance-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'delivery_url', type: 'string', description: 'Address Synaplan calls. Saving it probes the address; the answer carries reachable.'),
                    new OA\Property(
                        property: 'app_capabilities',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                        example: ['deliveries'],
                        description: 'Features this app supports. Names the core does not know are ignored.',
                    ),
                    new OA\Property(property: 'oidc_client_id', type: 'string', description: 'The OIDC client this cloud\'s tokens name as azp.'),
                    new OA\Property(property: 'app_version', type: 'string', example: '2.0.0'),
                    new OA\Property(property: 'server_id', type: 'string', description: 'The cloud\'s own instance id.'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Address saved'),
            new OA\Response(response: 401, description: 'Invalid instance credentials'),
            new OA\Response(response: 404, description: 'Feature disabled or unknown instance'),
            new OA\Response(response: 422, description: 'Address is not on this cloud'),
        ]
    )]
    public function deliveryUrl(Request $request): JsonResponse
    {
        $this->guard(null);
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $hasUrl = array_key_exists('delivery_url', $body);
        $hasCapabilities = array_key_exists('app_capabilities', $body);
        $hasField = $hasUrl || $hasCapabilities
            || array_key_exists('oidc_client_id', $body)
            || array_key_exists('app_version', $body)
            || array_key_exists('server_id', $body);
        if (!$hasField) {
            return $this->json(['success' => false, 'error' => 'Nothing to save.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $url = $hasUrl && is_string($body['delivery_url']) ? $body['delivery_url'] : ($hasUrl ? '' : null);
        $capabilities = null;
        if ($hasCapabilities && is_array($body['app_capabilities'])) {
            $capabilities = array_values(array_filter($body['app_capabilities'], 'is_string'));
        }
        $text = static fn (string $field): ?string => array_key_exists($field, $body)
            ? (is_string($body[$field]) ? $body[$field] : '')
            : null;

        try {
            $saved = $this->deliveryAddress->update(
                (string) $request->headers->get('X-Instance-Id', ''),
                (string) $request->headers->get('X-Instance-Secret', ''),
                $url,
                $capabilities,
                $text('oidc_client_id'),
                $text('app_version'),
                $text('server_id'),
            );
        } catch (PlatformInstanceNotFoundException) {
            return $this->json(['success' => false, 'error' => 'Instance not found.'], Response::HTTP_NOT_FOUND);
        } catch (PlatformInstanceSecretException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        } catch (PlatformLinkValidationException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['success' => true, ...$saved]);
    }

    #[Route('/{instanceId}/public', name: 'public', methods: ['GET'], requirements: ['instanceId' => 'pi_[a-f0-9]+|outlook-builtin'])]
    #[OA\Get(
        path: '/api/v1/platform-links/instances/{instanceId}/public',
        operationId: 'getPlatformInstancePublic',
        summary: 'Public host and client for the confirm card',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'instanceId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Active instance',
                content: new OA\JsonContent(
                    required: ['success', 'client', 'host'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'client', type: 'string', example: 'nextcloud'),
                        new OA\Property(property: 'host', type: 'string', example: 'files.example.org'),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Feature disabled or instance not active'),
        ]
    )]
    public function public(string $instanceId): JsonResponse
    {
        $this->guard(null);

        try {
            $result = $this->instanceService->describePublic($instanceId);
        } catch (PlatformInstanceNotFoundException) {
            return $this->json(['success' => false, 'error' => 'Instance not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, ...$result]);
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
