<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Admin\SystemConfigService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Admin System Configuration Controller.
 *
 * SECURITY: All endpoints require ROLE_ADMIN. Sensitive values are NEVER exposed.
 */
#[Route('/api/v1/admin/config')]
#[OA\Tag(name: 'Admin System Config')]
final class AdminSystemConfigController extends AbstractController
{
    public function __construct(
        private readonly SystemConfigService $configService,
    ) {
    }

    /**
     * Get configuration schema.
     */
    #[Route('/schema', name: 'admin_config_schema', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/config/schema',
        summary: 'Get configuration schema',
        description: 'Returns field definitions, types, sections, and validation rules (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin System Config']
    )]
    #[OA\Response(
        response: 200,
        description: 'Configuration schema',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'schema', type: 'object'),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function getSchema(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => true,
            'schema' => $this->configService->getSchema(),
        ]);
    }

    /**
     * Get current configuration values.
     */
    #[Route('/values', name: 'admin_config_values', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/config/values',
        summary: 'Get configuration values',
        description: 'Returns current values with sensitive fields masked (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin System Config']
    )]
    #[OA\Response(
        response: 200,
        description: 'Configuration values',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'values', type: 'object'),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function getValues(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => true,
            'values' => $this->configService->getValues(),
        ]);
    }

    /**
     * Update a configuration value.
     */
    #[Route('/values', name: 'admin_config_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/admin/config/values',
        summary: 'Update configuration value',
        description: 'Update a single configuration key (admin only). Creates backup before writing.',
        security: [['Bearer' => []]],
        tags: ['Admin System Config']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['key', 'value'],
            properties: [
                new OA\Property(property: 'key', type: 'string', example: 'OLLAMA_BASE_URL'),
                new OA\Property(property: 'value', type: 'string', example: 'http://localhost:11434'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Value updated',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'requiresRestart', type: 'boolean', example: true),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function updateValue(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->toArray();

        if (!isset($data['key']) || !isset($data['value'])) {
            return $this->json(['error' => 'Missing key or value'], Response::HTTP_BAD_REQUEST);
        }

        $key = trim((string) $data['key']);
        $value = (string) $data['value'];

        if ('' === $key) {
            return $this->json(['error' => 'Key cannot be empty'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->configService->setValue($key, $value);

        if (!$result['success']) {
            return $this->json([
                'success' => false,
                'error' => $result['message'] ?? 'Failed to update value',
            ], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'success' => true,
            'requiresRestart' => $result['requiresRestart'],
        ]);
    }

    /**
     * Test service connection.
     */
    #[Route('/test/{service}', name: 'admin_config_test', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/config/test/{service}',
        summary: 'Test service connection',
        description: 'Test connection to a configured service (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin System Config']
    )]
    #[OA\Parameter(
        name: 'service',
        description: 'Service to test (ollama, tika, qdrant, mailer)',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string', enum: ['ollama', 'tika', 'qdrant', 'mailer'])
    )]
    #[OA\Response(
        response: 200,
        description: 'Test result',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'details', type: 'object', nullable: true),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function testConnection(string $service, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $result = $this->configService->testConnection($service);

        return $this->json($result);
    }

    /**
     * Get available backups.
     */
    #[Route('/backups', name: 'admin_config_backups', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/config/backups',
        summary: 'List configuration backups',
        description: 'Get list of available .env backups (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin System Config']
    )]
    #[OA\Response(
        response: 200,
        description: 'List of backups',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'backups',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'string', example: '20260129_143022'),
                            new OA\Property(property: 'timestamp', type: 'string', example: '2026-01-29 14:30:22'),
                            new OA\Property(property: 'size', type: 'integer', example: 2048),
                        ]
                    )
                ),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function getBackups(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => true,
            'backups' => $this->configService->getBackups(),
        ]);
    }

    /**
     * Restore a backup.
     */
    #[Route('/restore/{backupId}', name: 'admin_config_restore', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/config/restore/{backupId}',
        summary: 'Restore configuration backup',
        description: 'Restore .env from a backup file (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin System Config']
    )]
    #[OA\Parameter(
        name: 'backupId',
        description: 'Backup ID (timestamp)',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string', example: '20260129_143022')
    )]
    #[OA\Response(
        response: 200,
        description: 'Restore result',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 404, description: 'Backup not found')]
    public function restoreBackup(string $backupId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        // Validate backup ID format (timestamp: YYYYMMDD_HHMMSS)
        if (!preg_match('/^\d{8}_\d{6}$/', $backupId)) {
            return $this->json(['error' => 'Invalid backup ID format'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->configService->restoreBackup($backupId);

        if (!$result['success']) {
            return $this->json($result, Response::HTTP_NOT_FOUND);
        }

        return $this->json($result);
    }
}
