<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\AdminUserProvisioningService;
use App\Service\Admin\UserProvisioningConflictException;
use App\Service\UsageStatsService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Admin-only provisioning API for trusted integrations (Nextcloud/ownCloud).
 *
 * Authenticated with an admin's API key (which already carries ROLE_ADMIN).
 * Lets the integration create Synaplan accounts for its own users and mint a
 * per-user API key so each end user acts only on their own account.
 */
#[Route('/api/v1/admin/users')]
#[OA\Tag(name: 'Admin User Provisioning')]
final class AdminUserProvisioningController extends AbstractController
{
    public function __construct(
        private readonly AdminUserProvisioningService $provisioning,
        private readonly UserRepository $userRepository,
        private readonly UsageStatsService $usageStats,
    ) {
    }

    #[Route('', name: 'admin_users_provision', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/users',
        summary: 'Provision (create or fetch) a user for an external identity',
        description: 'Idempotent on (source, external_id). Returns the existing user if already provisioned.',
        security: [['Bearer' => []]],
        tags: ['Admin User Provisioning']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['source', 'external_id', 'email'],
            properties: [
                new OA\Property(property: 'source', type: 'string', example: 'nextcloud'),
                new OA\Property(property: 'external_id', type: 'string', example: 'nc-instance-42:alice'),
                new OA\Property(property: 'email', type: 'string', example: 'alice@example.com'),
                new OA\Property(property: 'display_name', type: 'string', nullable: true, example: 'Alice Example'),
                new OA\Property(property: 'level', type: 'string', enum: ['NEW', 'PRO', 'TEAM', 'BUSINESS'], example: 'NEW'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'User already existed (idempotent hit)')]
    #[OA\Response(response: 201, description: 'User created')]
    #[OA\Response(response: 400, description: 'Invalid input')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 409, description: 'Email belongs to a different account')]
    public function provision(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->provisioning->provision(
                (string) ($data['source'] ?? ''),
                (string) ($data['external_id'] ?? ''),
                (string) ($data['email'] ?? ''),
                isset($data['display_name']) ? (string) $data['display_name'] : null,
                (string) ($data['level'] ?? 'NEW'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (UserProvisioningConflictException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'success' => true,
            'created' => $result['created'],
            'user' => $this->provisioning->serializeUser($result['user']),
        ], $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/{id}/api-keys', name: 'admin_users_mint_key', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/admin/users/{id}/api-keys',
        summary: 'Mint an API key on behalf of a user',
        description: 'Returns the plaintext key ONCE. Use it as that user\'s X-API-Key.',
        security: [['Bearer' => []]],
        tags: ['Admin User Provisioning']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: false,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'nextcloud-alice'),
                new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string'), example: ['chat', 'files', 'rag']),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'API key minted (plaintext returned once)')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 404, description: 'User not found')]
    public function mintKey(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $target = $this->userRepository->find($id);
        if (!$target) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        // Never let this endpoint mint a key for an admin account — that would
        // let an integration escalate to full admin. Admin keys are created
        // through the normal owner-only /api/v1/apikeys flow.
        if ($target->isAdmin()) {
            return $this->json(['error' => 'Refusing to mint a key for an admin account'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];

        $minted = $this->provisioning->mintApiKeyForUser(
            $target,
            (string) ($data['name'] ?? 'external-integration'),
            is_array($data['scopes'] ?? null) ? array_map('strval', $data['scopes']) : [],
        );

        return $this->json([
            'success' => true,
            'api_key' => [
                'id' => $minted['entity']->getId(),
                'name' => $minted['entity']->getName(),
                'key' => $minted['plainKey'],
                'scopes' => $minted['entity']->getScopes(),
                'owner_id' => $target->getId(),
            ],
            'message' => 'Store this key securely — it will not be shown again.',
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/usage', name: 'admin_users_usage', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/admin/users/{id}/usage',
        summary: 'Per-user usage stats (for cross-linking NC ↔ Synaplan)',
        security: [['Bearer' => []]],
        tags: ['Admin User Provisioning']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Usage stats')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 404, description: 'User not found')]
    public function usage(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $target = $this->userRepository->find($id);
        if (!$target) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'user' => $this->provisioning->serializeUser($target),
            'usage' => $this->usageStats->getUserStats($target),
        ]);
    }

    private function requireAdmin(?User $user): ?JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
