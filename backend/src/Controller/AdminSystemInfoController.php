<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Admin\SystemInfoService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin System Info Controller.
 *
 * SECURITY: Requires ROLE_ADMIN (enforced by class-level IsGranted).
 * Exposes only non-sensitive runtime diagnostics (PHP version, memory, disk).
 */
#[Route('/api/v1/admin/system-info')]
#[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
#[OA\Tag(name: 'Admin System Info')]
final class AdminSystemInfoController extends AbstractController
{
    public function __construct(
        private readonly SystemInfoService $systemInfoService,
    ) {
    }

    /**
     * Get server system info.
     */
    #[Route('', name: 'admin_system_info', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/system-info',
        summary: 'Get server system info',
        description: 'Returns non-sensitive runtime diagnostics: PHP version, memory settings + usage, request limits, and disk space for the app directory (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin System Info']
    )]
    #[OA\Response(
        response: 200,
        description: 'Server system info',
        content: new OA\JsonContent(
            required: ['success', 'system'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'system',
                    type: 'object',
                    required: ['php', 'memory', 'limits', 'disk', 'server', 'serverTime'],
                    properties: [
                        new OA\Property(
                            property: 'php',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'version', type: 'string', example: '8.5.7'),
                                new OA\Property(property: 'sapi', type: 'string', example: 'frankenphp'),
                                new OA\Property(property: 'opcacheEnabled', type: 'boolean', example: true),
                            ]
                        ),
                        new OA\Property(
                            property: 'memory',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'limit', type: 'string', example: '512M'),
                                new OA\Property(property: 'limitBytes', type: 'integer', example: 536870912),
                                new OA\Property(property: 'currentUsageBytes', type: 'integer', example: 33554432),
                                new OA\Property(property: 'peakUsageBytes', type: 'integer', example: 41943040),
                            ]
                        ),
                        new OA\Property(
                            property: 'limits',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'uploadMaxFilesize', type: 'string', example: '200M'),
                                new OA\Property(property: 'postMaxSize', type: 'string', example: '220M'),
                                new OA\Property(property: 'maxExecutionTime', type: 'integer', example: 300),
                            ]
                        ),
                        new OA\Property(
                            property: 'disk',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'freeBytes', type: 'integer', nullable: true, example: 12300000000),
                                new OA\Property(property: 'totalBytes', type: 'integer', nullable: true, example: 53600000000),
                                new OA\Property(property: 'usedBytes', type: 'integer', nullable: true, example: 41300000000),
                                new OA\Property(property: 'usedPercent', type: 'number', format: 'float', nullable: true, example: 77.1),
                            ]
                        ),
                        new OA\Property(
                            property: 'server',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'os', type: 'string', example: 'Linux'),
                                new OA\Property(property: 'software', type: 'string', nullable: true, example: 'Caddy'),
                                new OA\Property(property: 'hostname', type: 'string', nullable: true, example: 'synaplan-backend'),
                            ]
                        ),
                        new OA\Property(property: 'serverTime', type: 'string', format: 'date-time'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function getSystemInfo(): JsonResponse
    {
        return $this->json([
            'success' => true,
            'system' => $this->systemInfoService->collect(),
        ]);
    }
}
