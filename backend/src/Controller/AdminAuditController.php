<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\AuditLogEntryRepository;
use App\Service\Iam\IamConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin/audit', name: 'admin_audit_')]
#[OA\Tag(name: 'IAM Audit')]
final class AdminAuditController extends AbstractController
{
    public function __construct(
        private readonly IamConfig $iamConfig,
        private readonly AuditLogEntryRepository $auditLogEntryRepository,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/audit',
        operationId: 'listAdminAudit',
        summary: 'List IAM audit events',
        tags: ['IAM Audit'],
        parameters: [
            new OA\Parameter(name: 'actor', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Audit rows (metadata only — never content)',
                content: new OA\JsonContent(
                    required: ['entries', 'nextCursor'],
                    properties: [
                        new OA\Property(
                            property: 'entries',
                            type: 'array',
                            items: new OA\Items(
                                required: ['id', 'actorId', 'action', 'kind', 'resourceId', 'subject', 'ip', 'created'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'actorId', type: 'integer'),
                                    new OA\Property(property: 'action', type: 'string'),
                                    new OA\Property(property: 'kind', type: 'string'),
                                    new OA\Property(property: 'resourceId', type: 'string'),
                                    new OA\Property(property: 'subject', type: 'object', nullable: true),
                                    new OA\Property(property: 'ip', type: 'string'),
                                    new OA\Property(property: 'created', type: 'integer'),
                                ]
                            )
                        ),
                        new OA\Property(property: 'nextCursor', type: 'integer', nullable: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Admin access required'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }

        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));
        $actor = $request->query->get('actor');
        $from = $request->query->get('from');
        $to = $request->query->get('to');
        $cursor = $request->query->get('cursor');
        $rows = $this->auditLogEntryRepository->listFiltered(
            is_numeric($actor) ? (int) $actor : null,
            $this->stringQuery($request, 'action'),
            $this->stringQuery($request, 'kind'),
            is_numeric($from) ? (int) $from : null,
            is_numeric($to) ? (int) $to : null,
            is_numeric($cursor) ? (int) $cursor : null,
            $limit + 1,
        );
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'id' => (int) $row->getId(),
                'actorId' => $row->getActorId(),
                'action' => $row->getAction(),
                'kind' => $row->getResourceKind(),
                'resourceId' => $row->getResourceId(),
                'subject' => $this->normalizeSubject($row->getSubject()),
                'ip' => $row->getIp(),
                'created' => $row->getCreated(),
            ];
        }
        $last = $entries[array_key_last($entries)] ?? null;

        return $this->json([
            'entries' => $entries,
            'nextCursor' => $hasMore && is_array($last) ? $last['id'] : null,
        ]);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Guarantee the wire shape the schema promises (`subject: object|null`).
     * A legacy row that stored an empty `[]` would otherwise serialize as a
     * JSON array and fail the client's response validation; cast any non-empty
     * value to an object so even a stray list is emitted as `{…}`.
     *
     * @param array<string, mixed>|null $subject
     */
    private function normalizeSubject(?array $subject): ?object
    {
        return null === $subject || [] === $subject ? null : (object) $subject;
    }

    private function guard(?User $user): ?JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->iamConfig->isGroupsEnabled((int) $user->getId())) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
