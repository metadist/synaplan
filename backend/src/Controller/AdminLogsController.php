<?php

declare(strict_types=1);

namespace App\Controller;

use App\Observability\EventRingStore;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin Logs Controller.
 *
 * SECURITY: Requires ROLE_ADMIN (enforced by class-level IsGranted).
 * Exposes the redacted operational event ring — never raw logs. Every field is
 * assembled from an allow-list and free text is scrubbed, so the response
 * carries no chat content, user emails, document text or secrets.
 */
#[Route('/api/v1/admin/logs')]
#[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
#[OA\Tag(name: 'Admin Logs')]
final class AdminLogsController extends AbstractController
{
    private const DEFAULT_SUMMARY_WINDOW_MINUTES = 60;

    /**
     * Longest queryable window, in minutes. Matches the ring's 7-day TTL, so a
     * larger value could not surface anything anyway, and keeps the derived
     * `time() - $minutes * 60` inside the integer range that
     * {@see EventRingStore} declares.
     */
    private const MAX_WINDOW_MINUTES = 10080;

    private const MAX_LIMIT = 500;

    public function __construct(
        private readonly EventRingStore $store,
    ) {
    }

    #[Route('', name: 'admin_logs', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/logs',
        summary: 'Query the redacted operational event ring',
        description: 'Returns recent warning-and-above events (mode=recent) or an aggregate '
            .'overview (mode=summary) from the redacted event ring. All fields are allow-listed '
            .'and free text is scrubbed — no chat content, emails, document text or secrets are '
            .'ever returned (admin only).',
        security: [['Bearer' => []]],
        tags: ['Admin Logs'],
        parameters: [
            new OA\Parameter(name: 'mode', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['recent', 'summary'], default: 'recent')),
            new OA\Parameter(name: 'level', in: 'query', required: false, description: 'Filter by exact level (recent mode).', schema: new OA\Schema(type: 'string', enum: EventRingStore::LEVELS)),
            new OA\Parameter(name: 'since_minutes', in: 'query', required: false, description: 'Only events from the last N minutes (capped at 7 days, the ring retention).', schema: new OA\Schema(type: 'integer', maximum: self::MAX_WINDOW_MINUTES, minimum: 1)),
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'Case-insensitive substring match across event/message/exception/route/provider/model (recent mode).', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'request_id', in: 'query', required: false, description: 'Filter by correlation id (recent mode).', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: self::MAX_LIMIT, default: 50)),
        ],
    )]
    #[OA\Response(
        response: 200,
        description: 'Event ring query result',
        content: new OA\JsonContent(
            required: ['success', 'mode'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'mode', type: 'string', enum: ['recent', 'summary'], example: 'recent'),
                new OA\Property(
                    property: 'events',
                    type: 'array',
                    description: 'Present in recent mode. Newest first. Every field is allow-listed; free text is scrubbed.',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'string', example: 'a1b2c3d4e5f6a7b8'),
                            new OA\Property(property: 'ts', type: 'integer', description: 'Unix timestamp (seconds).', example: 1735689600),
                            new OA\Property(property: 'level', type: 'string', example: 'error'),
                            new OA\Property(property: 'channel', type: 'string', example: 'app'),
                            new OA\Property(property: 'event', type: 'string', example: 'exception'),
                            new OA\Property(property: 'message', type: 'string', nullable: true, example: 'RAG context loading failed'),
                            new OA\Property(property: 'exception_class', type: 'string', nullable: true, example: 'RuntimeException'),
                            new OA\Property(property: 'exception_message', type: 'string', nullable: true, description: 'Scrubbed exception message, or the log context\'s `error` detail when no exception object was attached.'),
                            new OA\Property(property: 'stack', type: 'array', items: new OA\Items(type: 'string'), description: 'Compact file:line frames (max 15).'),
                            new OA\Property(property: 'request_id', type: 'string', nullable: true, example: 'trace-abc123'),
                            new OA\Property(property: 'host', type: 'string', nullable: true, description: 'Cluster node that produced the event.', example: 'web2'),
                            new OA\Property(property: 'route', type: 'string', nullable: true, example: 'chat_send'),
                            new OA\Property(property: 'method', type: 'string', nullable: true, example: 'POST'),
                            new OA\Property(property: 'status_code', type: 'integer', nullable: true, example: 500),
                            new OA\Property(property: 'user_id', type: 'integer', nullable: true, description: 'Pseudonymous user id.', example: 4711),
                            new OA\Property(property: 'provider', type: 'string', nullable: true, example: 'anthropic'),
                            new OA\Property(property: 'model', type: 'string', nullable: true, example: 'claude-sonnet'),
                            new OA\Property(property: 'worker', type: 'string', nullable: true, example: 'ReVectorizeMessageHandler'),
                            new OA\Property(property: 'duration_ms', type: 'integer', nullable: true, example: 812),
                        ],
                    ),
                ),
                new OA\Property(
                    property: 'summary',
                    description: 'Present in summary mode.',
                    properties: [
                        new OA\Property(property: 'window_start', type: 'integer', example: 1735689600),
                        new OA\Property(property: 'total', type: 'integer', example: 42),
                        new OA\Property(property: 'by_level', type: 'object', description: 'Map of level => count.', example: ['error' => 12, 'warning' => 30]),
                        new OA\Property(property: 'by_event', type: 'object', description: 'Map of event type => count.', example: ['exception' => 12, 'log' => 30]),
                        new OA\Property(property: 'by_route', type: 'object', description: 'Map of route => count.', example: ['chat_send' => 8]),
                        new OA\Property(property: 'recent_errors', type: 'array', items: new OA\Items(type: 'object')),
                    ],
                    type: 'object',
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function query(Request $request): JsonResponse
    {
        $mode = 'summary' === $request->query->get('mode') ? 'summary' : 'recent';

        $sinceMinutes = $request->query->getInt('since_minutes', 0);
        $sinceMinutes = $sinceMinutes > 0 ? min($sinceMinutes, self::MAX_WINDOW_MINUTES) : null;

        if ('summary' === $mode) {
            $window = $sinceMinutes ?? self::DEFAULT_SUMMARY_WINDOW_MINUTES;

            return $this->json([
                'success' => true,
                'mode' => 'summary',
                'summary' => $this->store->summary(time() - $window * 60),
            ]);
        }

        $level = $request->query->get('level');
        $level = \is_string($level) && \in_array($level, EventRingStore::LEVELS, true) ? $level : null;

        $query = $request->query->get('q');
        $query = \is_string($query) && '' !== trim($query) ? $query : null;

        $requestId = $request->query->get('request_id');
        $requestId = \is_string($requestId) && '' !== trim($requestId) ? $requestId : null;

        $limit = $request->query->getInt('limit', 50);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $sinceTs = null === $sinceMinutes ? null : time() - $sinceMinutes * 60;

        return $this->json([
            'success' => true,
            'mode' => 'recent',
            'events' => $this->store->recent($level, $sinceTs, $query, $requestId, $limit),
        ]);
    }
}
