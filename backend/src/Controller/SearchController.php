<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\SmartSearch\SmartSearchService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[OA\Tag(name: 'Smart Search')]
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly SmartSearchService $smartSearch,
        private readonly RateLimiterFactoryInterface $smartSearchLimiter,
    ) {
    }

    #[Route('/api/v1/search', name: 'smart_search', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/search',
        operationId: 'smartSearch',
        summary: 'Search everything the user owns',
        description: 'Keyword search over chats, files, widgets, AI assistants and saved tasks, plus system settings for admins. Results are fused with reciprocal rank fusion. Pages and commands are searched in the browser and are not part of this response.',
        security: [['Bearer' => []]],
        tags: ['Smart Search'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['q'],
            properties: [
                new OA\Property(property: 'q', type: 'string', maxLength: 200, minLength: 1, example: 'invoice march'),
                new OA\Property(
                    property: 'kinds',
                    type: 'array',
                    nullable: true,
                    description: 'Limit to these kinds; omit for all.',
                    items: new OA\Items(type: 'string', enum: SmartSearchService::KINDS),
                ),
                new OA\Property(property: 'limit', type: 'integer', maximum: 50, minimum: 1, example: 20),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Ranked results, best first',
        content: new OA\JsonContent(
            required: ['query', 'results', 'semanticAvailable', 'degraded', 'indexing'],
            properties: [
                new OA\Property(property: 'query', type: 'string', example: 'invoice march'),
                new OA\Property(
                    property: 'results',
                    type: 'array',
                    items: new OA\Items(
                        required: ['id', 'kind', 'title', 'subtitle', 'snippet', 'route', 'score', 'matchedBy'],
                        properties: [
                            new OA\Property(property: 'id', type: 'string', example: 'file:42'),
                            new OA\Property(property: 'kind', type: 'string', enum: SmartSearchService::KINDS, example: 'file'),
                            new OA\Property(property: 'title', type: 'string', example: 'Invoice March.pdf'),
                            new OA\Property(property: 'subtitle', type: 'string', nullable: true, example: 'Accounting'),
                            new OA\Property(property: 'snippet', type: 'string', nullable: true, example: '…total amount due for March…'),
                            new OA\Property(property: 'route', type: 'string', example: '/files?file=42'),
                            new OA\Property(property: 'score', type: 'number', format: 'float', example: 0.032787),
                            new OA\Property(property: 'matchedBy', type: 'string', enum: ['lexical', 'semantic', 'both'], example: 'lexical'),
                        ],
                    ),
                ),
                new OA\Property(property: 'semanticAvailable', type: 'boolean', description: 'False when only keyword search answered', example: false),
                new OA\Property(property: 'degraded', type: 'array', description: 'Providers that were skipped or failed', items: new OA\Items(type: 'string'), example: []),
                new OA\Property(property: 'indexing', type: 'boolean', description: 'True while the first index build for this user is running', example: false),
            ],
        ),
    )]
    #[OA\Response(response: 400, description: 'Missing or too long query')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 429, description: 'Too many searches')]
    public function search(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $query = is_array($data) && is_string($data['q'] ?? null) ? trim($data['q']) : '';
        if ('' === $query || mb_strlen($query) > SmartSearchService::MAX_QUERY_LENGTH) {
            return $this->json(['error' => sprintf('Send a search text of 1 to %d characters in "q".', SmartSearchService::MAX_QUERY_LENGTH)], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->smartSearchLimiter->create('user:'.$user->getId())->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many searches. Try again in a minute.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $kinds = is_array($data['kinds'] ?? null)
            ? array_values(array_filter($data['kinds'], 'is_string'))
            : null;
        $limit = is_int($data['limit'] ?? null) ? $data['limit'] : SmartSearchService::DEFAULT_LIMIT;

        return $this->json($this->smartSearch->search($user, $query, $kinds, $limit)->toArray());
    }
}
