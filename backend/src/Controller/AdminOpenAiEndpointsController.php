<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Credential\ChatReadinessService;
use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\Entity\User;
use App\Service\FreeApi\FreeApiAutoLeave;
use App\Service\FreeApi\FreeApiConfig;
use App\Service\SelfAware\CapabilityInventory;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Admin CRUD for "OpenAI Compatible" upstream endpoints (LocalAI, vLLM,
 * LiteLLM, …). Secrets are stored encrypted; the API key is never returned in
 * any response (only a has_api_key flag).
 */
#[Route('/api/v1/admin/openai-endpoints')]
#[OA\Tag(name: 'Admin OpenAI-Compatible Endpoints')]
final class AdminOpenAiEndpointsController extends AbstractController
{
    public function __construct(
        private readonly OpenAiCompatibleEndpointRegistry $endpoints,
        private readonly ChatReadinessService $chatReadiness,
        private readonly ?CapabilityInventory $capabilityInventory = null,
        private readonly ?FreeApiAutoLeave $freeApiAutoLeave = null,
    ) {
    }

    #[Route('', name: 'admin_openai_endpoints_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/openai-endpoints',
        summary: 'List OpenAI-compatible endpoints',
        security: [['Bearer' => []]],
        tags: ['Admin OpenAI-Compatible Endpoints']
    )]
    #[OA\Response(response: 200, description: 'List of endpoints (API keys never included)')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        return $this->json([
            'success' => true,
            'endpoints' => $this->endpoints->listEndpoints(),
            'capabilities' => OpenAiCompatibleEndpointRegistry::CAPABILITIES,
        ]);
    }

    #[Route('', name: 'admin_openai_endpoints_save', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/openai-endpoints',
        summary: 'Create or update an OpenAI-compatible endpoint',
        security: [['Bearer' => []]],
        tags: ['Admin OpenAI-Compatible Endpoints']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'base_url'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'localai'),
                new OA\Property(property: 'label', type: 'string', example: 'Local AI (GPU 1)'),
                new OA\Property(property: 'base_url', type: 'string', example: 'https://localai.example.com/v1'),
                new OA\Property(property: 'api_key', type: 'string', nullable: true, description: 'Omit or send null to keep the existing key; empty string clears it'),
                new OA\Property(property: 'headers', type: 'object', nullable: true),
                new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string'), example: ['chat', 'vectorize', 'pic2text', 'text2pic']),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Endpoint saved',
        content: new OA\JsonContent(
            required: ['success', 'endpoints', 'demoLeft'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'endpoints',
                    type: 'array',
                    items: new OA\Items(
                        required: ['name', 'label', 'base_url', 'has_api_key', 'headers', 'capabilities'],
                        properties: [
                            new OA\Property(property: 'name', type: 'string', example: 'localai'),
                            new OA\Property(property: 'label', type: 'string', example: 'Local AI (GPU 1)'),
                            new OA\Property(property: 'base_url', type: 'string', example: 'https://localai.example.com/v1'),
                            new OA\Property(property: 'has_api_key', type: 'boolean', example: true),
                            new OA\Property(property: 'headers', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
                            new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string'), example: ['chat']),
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(property: 'demoLeft', type: 'boolean', example: false, description: 'True when the Synaplan Demo was active and turned itself off because this endpoint answers chat'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid input')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function save(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }
        if ($resp = $this->refuseDemoEndpoint((string) ($data['name'] ?? ''))) {
            return $resp;
        }

        try {
            $this->endpoints->saveEndpoint(
                (string) ($data['name'] ?? ''),
                (string) ($data['base_url'] ?? ''),
                array_key_exists('api_key', $data) ? (null === $data['api_key'] ? null : (string) $data['api_key']) : null,
                is_array($data['headers'] ?? null) ? $data['headers'] : [],
                isset($data['label']) ? (string) $data['label'] : null,
                is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [],
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // The model picker hides every model whose provider the availability
        // snapshot calls offline. That snapshot is taken before this endpoint
        // exists and lives for 30 s, so drop it before the next list request.
        $servesChat = in_array('chat', $this->endpoints->getEndpoint((string) $data['name'])['capabilities'] ?? [], true);
        $demoLeft = $servesChat && true === $this->freeApiAutoLeave?->ownProviderConnected(OpenAiCompatibleEndpointRegistry::SERVICE);
        $this->refreshModelPicker();

        return $this->json([
            'success' => true,
            'endpoints' => $this->endpoints->listEndpoints(),
            'demoLeft' => $demoLeft,
        ]);
    }

    #[Route('/test', name: 'admin_openai_endpoints_test', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/openai-endpoints/test',
        summary: 'Test connectivity to an OpenAI-compatible endpoint',
        description: 'Probes GET {base_url}/models. Accepts either a stored endpoint name or explicit base_url/api_key.',
        security: [['Bearer' => []]],
        tags: ['Admin OpenAI-Compatible Endpoints']
    )]
    #[OA\Response(response: 200, description: 'Test result (ok true/false)')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function test(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];

        $result = $this->endpoints->testConnection(
            isset($data['name']) ? (string) $data['name'] : null,
            isset($data['base_url']) ? (string) $data['base_url'] : null,
            array_key_exists('api_key', $data) ? (null === $data['api_key'] ? null : (string) $data['api_key']) : null,
            is_array($data['headers'] ?? null) ? $data['headers'] : [],
        );

        return $this->json($result);
    }

    #[Route('/{name}', name: 'admin_openai_endpoints_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/admin/openai-endpoints/{name}',
        summary: 'Delete an OpenAI-compatible endpoint',
        security: [['Bearer' => []]],
        tags: ['Admin OpenAI-Compatible Endpoints']
    )]
    #[OA\Parameter(name: 'name', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Endpoint deleted')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 404, description: 'Endpoint not found')]
    public function delete(string $name, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }
        if ($resp = $this->refuseDemoEndpoint($name)) {
            return $resp;
        }

        $deleted = $this->endpoints->deleteEndpoint($name);
        if (!$deleted) {
            return $this->json(['error' => 'Endpoint not found'], Response::HTTP_NOT_FOUND);
        }

        $this->refreshModelPicker();

        return $this->json(['success' => true]);
    }

    private function refreshModelPicker(): void
    {
        $this->chatReadiness->invalidate();
        $this->capabilityInventory?->forget();
    }

    /** The Demo endpoint belongs to the Demo's own start/leave flow; editing it by hand would desync its state. */
    private function refuseDemoEndpoint(string $name): ?JsonResponse
    {
        if (FreeApiConfig::ENDPOINT_NAME !== strtolower(trim($name))) {
            return null;
        }

        return $this->json(['error' => sprintf('"%s" is reserved for the Synaplan Demo. Turn the Demo off instead, or pick another name.', FreeApiConfig::ENDPOINT_NAME)], Response::HTTP_BAD_REQUEST);
    }

    private function requireAdmin(?User $user): ?JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
