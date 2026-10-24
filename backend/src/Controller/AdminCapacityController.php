<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Observability\CapacityService;
use App\Service\Observability\CapacityUnavailableException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Capacity of every server, as reported by its watchdog (admin only).
 *
 * Reads Redis only — one short request, no probe of any server. Absent
 * when `SYNAPLAN_WATCHDOG=0` (module gate, 404 `feature_not_configured`).
 */
#[Route('/api/v1/admin/capacity')]
#[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
#[OA\Tag(name: 'Admin Capacity')]
final class AdminCapacityController extends AbstractController
{
    public function __construct(
        private readonly CapacityService $capacity,
    ) {
    }

    #[Route('/servers', name: 'admin_capacity_servers', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/capacity/servers',
        summary: 'Capacity of every server of this installation',
        description: 'Every server runs a watchdog that reports its PHP pools, memory, processor, place and log source to the shared cache every 5 seconds. This reads those reports; it never asks a server. `served_by` names the server that answered this request, the same value as the `X-Synaplan-Server` response header.',
        security: [['Bearer' => []]],
        tags: ['Admin Capacity'],
    )]
    #[OA\Response(
        response: 200,
        description: 'The servers known to this installation, sorted by id',
        content: new OA\JsonContent(
            required: ['success', 'served_by', 'generated_at', 'servers'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'served_by', type: 'string', description: 'The server that answered this request.', example: 'web2'),
                new OA\Property(property: 'generated_at', type: 'string', format: 'date-time', example: '2026-10-24T19:16:31+00:00'),
                new OA\Property(
                    property: 'servers',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        required: ['id', 'hostname', 'role', 'runtime', 'version', 'watchdog_version', 'reporting', 'updated_at', 'last_seen_at', 'php_ready', 'admin_ok', 'streams', 'api', 'memory', 'cpu', 'place', 'logs', 'threads', 'peak_today'],
                        properties: [
                            new OA\Property(property: 'id', type: 'string', description: 'SYNAPLAN_SERVER_ID, else the hostname.', example: 'web1'),
                            new OA\Property(property: 'hostname', type: 'string', description: 'The container hostname; what the event ring stamps as host.', example: '79e4f90bc8c6'),
                            new OA\Property(property: 'role', type: 'string', enum: ['web', 'worker', 'scheduler'], example: 'web'),
                            new OA\Property(property: 'runtime', type: 'string', enum: ['compose', 'kubernetes', 'host', ''], example: 'compose'),
                            new OA\Property(property: 'version', type: 'string', example: '5.6.0'),
                            new OA\Property(property: 'watchdog_version', type: 'string', example: '0.1.0'),
                            new OA\Property(property: 'reporting', type: 'boolean', description: 'False when the last report is older than 20 seconds.', example: true),
                            new OA\Property(property: 'updated_at', type: 'string', description: 'Time of the live report, or of the last one seen.', example: '2026-10-24T19:16:31Z'),
                            new OA\Property(property: 'last_seen_at', type: 'string', example: '2026-10-24T19:16:31Z'),
                            new OA\Property(property: 'php_ready', type: 'boolean', example: true),
                            new OA\Property(property: 'admin_ok', type: 'boolean', description: 'FrankenPHP answered its admin API on the last tick.', example: true),
                            new OA\Property(
                                property: 'streams',
                                type: 'object',
                                description: 'The PHP pool that holds AI answers.',
                                required: ['total', 'busy'],
                                properties: [
                                    new OA\Property(property: 'total', type: 'integer', example: 16),
                                    new OA\Property(property: 'busy', type: 'integer', example: 4),
                                ],
                            ),
                            new OA\Property(
                                property: 'api',
                                type: 'object',
                                description: 'The PHP pool for every other request.',
                                required: ['total', 'busy'],
                                properties: [
                                    new OA\Property(property: 'total', type: 'integer', example: 8),
                                    new OA\Property(property: 'busy', type: 'integer', example: 1),
                                ],
                            ),
                            new OA\Property(
                                property: 'memory',
                                type: 'object',
                                required: ['rss_bytes', 'limit_bytes', 'limited'],
                                properties: [
                                    new OA\Property(property: 'rss_bytes', type: 'integer', example: 491044864),
                                    new OA\Property(property: 'limit_bytes', type: 'integer', description: '0 when no limit is set.', example: 6442450944),
                                    new OA\Property(property: 'limited', type: 'boolean', example: true),
                                ],
                            ),
                            new OA\Property(
                                property: 'cpu',
                                type: 'object',
                                required: ['cores', 'limited', 'usage_ratio'],
                                properties: [
                                    new OA\Property(property: 'cores', type: 'number', format: 'float', example: 8),
                                    new OA\Property(property: 'limited', type: 'boolean', example: true),
                                    new OA\Property(property: 'usage_ratio', type: 'number', format: 'float', nullable: true, description: '0..1 of the cores; null on the first sample.', example: 0.14),
                                ],
                            ),
                            new OA\Property(
                                property: 'place',
                                type: 'object',
                                description: 'Where the server runs. Empty strings where a field does not apply to the runtime.',
                                required: ['host', 'pod', 'namespace', 'deployment', 'container', 'cpu_request', 'memory_limit'],
                                properties: [
                                    new OA\Property(property: 'host', type: 'string', example: 'synweb100'),
                                    new OA\Property(property: 'pod', type: 'string', example: ''),
                                    new OA\Property(property: 'namespace', type: 'string', example: ''),
                                    new OA\Property(property: 'deployment', type: 'string', example: ''),
                                    new OA\Property(property: 'container', type: 'string', example: ''),
                                    new OA\Property(property: 'cpu_request', type: 'string', example: ''),
                                    new OA\Property(property: 'memory_limit', type: 'string', example: ''),
                                ],
                            ),
                            new OA\Property(
                                property: 'logs',
                                type: 'object',
                                required: ['collector', 'event_ring_host'],
                                properties: [
                                    new OA\Property(property: 'collector', type: 'string', enum: ['docker', 'kubernetes', 'process', ''], example: 'docker'),
                                    new OA\Property(property: 'event_ring_host', type: 'string', example: '79e4f90bc8c6'),
                                ],
                            ),
                            new OA\Property(
                                property: 'threads',
                                type: 'object',
                                required: ['count', 'busy'],
                                properties: [
                                    new OA\Property(property: 'count', type: 'integer', example: 24),
                                    new OA\Property(property: 'busy', type: 'integer', example: 3),
                                ],
                            ),
                            new OA\Property(
                                property: 'peak_today',
                                type: 'object',
                                description: 'The most AI answers this server held at once today (UTC), and when.',
                                required: ['streams_busy', 'streams_total', 'at'],
                                properties: [
                                    new OA\Property(property: 'streams_busy', type: 'integer', example: 22),
                                    new OA\Property(property: 'streams_total', type: 'integer', example: 24),
                                    new OA\Property(property: 'at', type: 'string', nullable: true, example: '2026-10-24T11:40:05Z'),
                                ],
                            ),
                        ],
                    ),
                ),
            ],
        ),
    )]
    #[OA\Response(
        response: 503,
        description: 'The shared cache did not answer; the servers keep working',
        content: new OA\JsonContent(
            required: ['success', 'error'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'error', type: 'string', enum: ['capacity_unavailable'], example: 'capacity_unavailable'),
            ],
        ),
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 404, description: 'The watchdog is off on this installation (feature_not_configured)')]
    public function servers(): JsonResponse
    {
        try {
            $report = $this->capacity->report();
        } catch (CapacityUnavailableException) {
            return $this->json(['success' => false, 'error' => 'capacity_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json(['success' => true] + $report);
    }
}
