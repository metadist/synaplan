<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\MessageDigestRepository;
use App\Service\Digest\LongTermMemoryService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Read-only lookup for `[Message:ID]` badge references (deep-memory digests).
 *
 * During streaming the references arrive via the `digests_loaded` SSE event;
 * this endpoint re-resolves them after a page reload, when the AI response
 * containing `[Message:ID]` tags is loaded from history. Only the digest
 * title and chat routing info are exposed — never the message body.
 */
#[Route('/api/v1/user/message-digests')]
#[OA\Tag(name: 'User Memories')]
class MessageDigestController extends AbstractController
{
    private const MAX_IDS_PER_REQUEST = 100;

    private const NOT_FOUND_ERROR = 'Long-term memory entry not found';

    public function __construct(
        private readonly MessageDigestRepository $digestRepository,
        private readonly LongTermMemoryService $longTermMemory,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/message-digests',
        summary: 'Resolve message digest references',
        description: 'Returns the digest (searchable title + chat routing info) for the given message ids, scoped to the current user. Used to render [Message:ID] badges after a page reload. Unknown or foreign ids are silently omitted.',
        parameters: [
            new OA\Parameter(
                name: 'ids',
                in: 'query',
                required: true,
                description: 'Comma-separated message ids (max 100)',
                schema: new OA\Schema(type: 'string', example: '1234,5678')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resolved digest references',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'digests',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'messageId', type: 'integer', example: 1234),
                                    new OA\Property(property: 'chatId', type: 'integer', example: 42),
                                    new OA\Property(property: 'title', type: 'string', example: 'office rent letter to realtor about the increase of payments'),
                                    new OA\Property(property: 'channel', type: 'string', example: 'web'),
                                    new OA\Property(property: 'sourceDate', type: 'integer', example: 1747216800),
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(property: 'total', type: 'integer', example: 1),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Missing or invalid ids parameter'),
        ]
    )]
    public function resolve(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $rawIds = (string) $request->query->get('ids', '');
        if ('' === trim($rawIds)) {
            return $this->json(['error' => 'Query parameter "ids" is required'], 400);
        }

        $ids = [];
        foreach (explode(',', $rawIds) as $part) {
            $part = trim($part);
            if ('' === $part || !ctype_digit($part)) {
                continue;
            }
            $ids[] = (int) $part;
        }
        $ids = array_slice(array_values(array_unique($ids)), 0, self::MAX_IDS_PER_REQUEST);

        if ([] === $ids) {
            return $this->json(['error' => 'Query parameter "ids" contains no valid message ids'], 400);
        }

        $digests = $this->digestRepository->findActiveByUserAndMessageIds($user->getId(), $ids);

        return $this->json([
            'digests' => array_map(static fn ($d): array => [
                'messageId' => $d->getMessageId(),
                'chatId' => $d->getChatId(),
                'title' => $d->getTitle(),
                'channel' => $d->getChannel(),
                'sourceDate' => $d->getSourceDate(),
            ], $digests),
            'total' => count($digests),
        ]);
    }

    #[Route('/entries', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/message-digests/entries',
        operationId: 'getUserMessageDigestEntries',
        summary: 'List long-term memory entries',
        description: 'Returns the current user\'s active long-term memory entries, newest source message first. Inactive entries and other users\' entries are omitted.',
        tags: ['User Memories'],
        parameters: [
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                description: 'Page number, starting at 1',
                schema: new OA\Schema(type: 'integer', minimum: LongTermMemoryService::MIN_PAGE, default: LongTermMemoryService::MIN_PAGE, example: 1)
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                required: false,
                description: 'Entries per page',
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: LongTermMemoryService::MIN_LIMIT,
                    maximum: LongTermMemoryService::MAX_LIMIT,
                    default: LongTermMemoryService::DEFAULT_LIMIT,
                    example: 25
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Active long-term memory entries',
                content: new OA\JsonContent(
                    required: ['enabled', 'memoriesEnabled', 'entries', 'total', 'page', 'limit'],
                    properties: [
                        new OA\Property(property: 'enabled', type: 'boolean', example: true, description: 'Server switch for long-term memory'),
                        new OA\Property(property: 'memoriesEnabled', type: 'boolean', example: true, description: 'The current user\'s memories switch'),
                        new OA\Property(
                            property: 'entries',
                            type: 'array',
                            items: new OA\Items(
                                required: ['id', 'title', 'messageId', 'chatId', 'chatTitle', 'channel', 'sourceDate', 'created'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1234),
                                    new OA\Property(property: 'title', type: 'string', example: 'office rent letter to realtor about the increase of payments'),
                                    new OA\Property(property: 'messageId', type: 'integer', example: 5678),
                                    new OA\Property(
                                        property: 'chatId',
                                        type: 'integer',
                                        nullable: true,
                                        example: 42,
                                        description: 'Source chat id, or null when the message had no chat. A deleted chat still has an id; chatTitle is then null.'
                                    ),
                                    new OA\Property(
                                        property: 'chatTitle',
                                        type: 'string',
                                        nullable: true,
                                        example: 'Q3 planning',
                                        description: 'Title of the source chat when it still exists, belongs to the user, and is not a placeholder; otherwise null.'
                                    ),
                                    new OA\Property(property: 'channel', type: 'string', example: 'web'),
                                    new OA\Property(property: 'sourceDate', type: 'integer', example: 1747216800, description: 'Unix timestamp of the source message, in seconds'),
                                    new OA\Property(property: 'created', type: 'integer', example: 1747216900, description: 'Unix timestamp when the entry was stored, in seconds'),
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(property: 'total', type: 'integer', example: 1),
                        new OA\Property(property: 'page', type: 'integer', example: 1),
                        new OA\Property(property: 'limit', type: 'integer', example: 25),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function listEntries(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        return $this->json($this->longTermMemory->listEntries(
            $user,
            $request->query->getInt('page', LongTermMemoryService::MIN_PAGE),
            $request->query->getInt('limit', LongTermMemoryService::DEFAULT_LIMIT),
        ));
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/user/message-digests/{id}',
        operationId: 'deleteUserMessageDigest',
        summary: 'Delete one long-term memory entry',
        description: 'Deactivates one active entry of the current user and removes its search point. Another user\'s id, an unknown id, and an already inactive id all answer 404.',
        tags: ['User Memories'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'Long-term memory entry id',
                schema: new OA\Schema(type: 'integer', example: 1234)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entry deleted',
                content: new OA\JsonContent(
                    required: ['success'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'No active entry with this id for the current user',
                content: new OA\JsonContent(
                    required: ['error'],
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: self::NOT_FOUND_ERROR),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function deleteEntry(
        int $id,
        #[CurrentUser] User $user,
    ): JsonResponse {
        if (!$this->longTermMemory->deleteEntry($user, $id)) {
            return $this->json(['error' => self::NOT_FOUND_ERROR], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true]);
    }

    #[Route('', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/message-digests',
        operationId: 'deleteAllUserMessageDigests',
        summary: 'Delete all long-term memory entries',
        description: 'Deactivates every active long-term memory entry of the current user and removes the search points. Other users\' entries are left in place.',
        tags: ['User Memories'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entries deleted',
                content: new OA\JsonContent(
                    required: ['success', 'deleted'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'deleted', type: 'integer', example: 12, description: 'How many active entries were deactivated'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function deleteAll(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json([
            'success' => true,
            'deleted' => $this->longTermMemory->deleteAll($user),
        ]);
    }
}
