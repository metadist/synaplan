<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\MessageDigestRepository;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

    public function __construct(
        private readonly MessageDigestRepository $digestRepository,
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
}
