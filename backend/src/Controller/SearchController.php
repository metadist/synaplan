<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\SmartSearch\Interpret\InterpretCandidate;
use App\Service\SmartSearch\Interpret\InterpretResult;
use App\Service\SmartSearch\Interpret\SearchInterpreter;
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
    private const LANGUAGES = ['de', 'en', 'es', 'fr', 'tr'];

    public function __construct(
        private readonly SmartSearchService $smartSearch,
        private readonly SearchInterpreter $interpreter,
        private readonly RateLimiterFactoryInterface $smartSearchLimiter,
        private readonly RateLimiterFactoryInterface $smartSearchInterpretLimiter,
    ) {
    }

    #[Route('/api/v1/search', name: 'smart_search', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/search',
        operationId: 'smartSearch',
        summary: 'Search everything the user owns',
        description: 'Keyword and meaning search over chats, files (names and contents), widgets, AI assistants, saved tasks and memories, plus system settings for admins. Results are fused with reciprocal rank fusion. Pages and commands are searched in the browser and are not part of this response.',
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
                        required: ['id', 'kind', 'title', 'subtitle', 'snippet', 'route', 'score', 'matchedBy', 'action', 'sharedBy'],
                        properties: [
                            new OA\Property(property: 'id', type: 'string', example: 'file:42'),
                            new OA\Property(property: 'kind', type: 'string', enum: SmartSearchService::KINDS, example: 'file'),
                            new OA\Property(property: 'title', type: 'string', example: 'Invoice March.pdf'),
                            new OA\Property(property: 'subtitle', type: 'string', nullable: true, example: 'Accounting'),
                            new OA\Property(property: 'snippet', type: 'string', nullable: true, example: '…total amount due for March…'),
                            new OA\Property(property: 'route', type: 'string', example: '/files?file=42'),
                            new OA\Property(property: 'score', type: 'number', format: 'float', example: 0.032787),
                            new OA\Property(property: 'matchedBy', type: 'string', enum: ['lexical', 'semantic', 'both'], example: 'lexical'),
                            new OA\Property(
                                property: 'action',
                                description: 'Inline control for a setting. Writes go through the endpoint that owns the setting (system scope: PUT /api/v1/admin/config/values). Null: open the route.',
                                type: 'object',
                                nullable: true,
                                required: ['type', 'key', 'current', 'options', 'scope', 'envPinned'],
                                properties: [
                                    new OA\Property(property: 'type', type: 'string', enum: ['toggle', 'select'], example: 'toggle'),
                                    new OA\Property(property: 'key', type: 'string', example: 'FEATURE_IAM_GROUPS_ENABLED'),
                                    new OA\Property(property: 'current', type: 'string', description: 'Value in force (the pinned one when envPinned)', example: 'false'),
                                    new OA\Property(property: 'options', type: 'array', description: 'Choices of a select; empty for a toggle', items: new OA\Items(type: 'string'), example: []),
                                    new OA\Property(property: 'scope', type: 'string', enum: ['system'], description: 'system: changes it for everyone, needs a confirmation', example: 'system'),
                                    new OA\Property(property: 'envPinned', type: 'boolean', description: 'An environment variable pins the value; show it read-only', example: false),
                                ],
                            ),
                            new OA\Property(property: 'sharedBy', type: 'string', nullable: true, description: 'Display name of the owner when the item is shared with the caller; null for own items', example: null),
                        ],
                    ),
                ),
                new OA\Property(property: 'semanticAvailable', type: 'boolean', description: 'False when no embedding model could answer, so only keyword search ran', example: true),
                new OA\Property(property: 'degraded', type: 'array', description: 'Providers that were skipped or failed', items: new OA\Items(type: 'string'), example: []),
                new OA\Property(property: 'indexing', type: 'boolean', description: 'True while the index for this user is being built or re-embedded', example: false),
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

    #[Route('/api/v1/search/interpret', name: 'smart_search_interpret', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/search/interpret',
        operationId: 'smartSearchInterpret',
        summary: 'Let the AI pick the best search result for a question',
        description: 'One model call. By default that is the signed-in person\'s chat model; an admin can pin one model for everyone. It reads the question and the results the palette already shows and points at the best of them. It never changes anything. Answers 404 when FEATURE_SEARCH_AI_ENABLED is off or that model cannot answer.',
        security: [['Bearer' => []]],
        tags: ['Smart Search'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['q', 'candidates'],
            properties: [
                new OA\Property(property: 'q', type: 'string', maxLength: 200, minLength: 1, example: 'how do I turn on groups'),
                new OA\Property(property: 'language', type: 'string', enum: self::LANGUAGES, example: 'en', description: 'Language of the answer sentence; defaults to en'),
                new OA\Property(
                    property: 'candidates',
                    type: 'array',
                    maxItems: InterpretCandidate::MAX_CANDIDATES,
                    minItems: 1,
                    items: new OA\Items(
                        required: ['id', 'kind', 'title'],
                        properties: [
                            new OA\Property(property: 'id', type: 'string', example: 'setting:FEATURE_IAM_GROUPS_ENABLED'),
                            new OA\Property(property: 'kind', type: 'string', enum: InterpretCandidate::KINDS, example: 'setting'),
                            new OA\Property(property: 'title', type: 'string', example: 'Users & groups'),
                            new OA\Property(property: 'subtitle', type: 'string', nullable: true, example: 'Features › Users & sharing'),
                            new OA\Property(property: 'value', type: 'string', nullable: true, description: 'Current value of a setting', example: 'false'),
                        ],
                    ),
                ),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'The pick. outcome "failed" means the model gave no usable answer; "limit_reached" means the message allowance is used up and no model was called. The palette keeps its list in both cases. Setting candidates and the change_setting intent are only honoured for admins.',
        content: new OA\JsonContent(
            required: ['outcome', 'intent', 'targetIds', 'answer'],
            properties: [
                new OA\Property(property: 'outcome', type: 'string', enum: InterpretResult::OUTCOMES, example: 'ok'),
                new OA\Property(property: 'intent', type: 'string', enum: InterpretResult::INTENTS, nullable: true, example: 'change_setting'),
                new OA\Property(property: 'targetIds', type: 'array', description: 'Candidate ids, best first; never an id that was not sent', items: new OA\Items(type: 'string'), example: ['setting:FEATURE_IAM_GROUPS_ENABLED']),
                new OA\Property(property: 'answer', type: 'string', nullable: true, description: 'One sentence for the user', example: 'Turns on groups for everyone — you confirm it first.'),
            ],
        ),
    )]
    #[OA\Response(response: 400, description: 'Missing query or unusable candidates')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 404, description: 'AI search is off or the search model cannot answer')]
    #[OA\Response(response: 429, description: 'Too many AI searches')]
    public function interpret(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->interpreter->isAvailable($user)) {
            return $this->json(['error' => 'AI search is not available on this instance.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $query = is_array($data) && is_string($data['q'] ?? null) ? trim($data['q']) : '';
        if ('' === $query || mb_strlen($query) > SmartSearchService::MAX_QUERY_LENGTH) {
            return $this->json(['error' => sprintf('Send a question of 1 to %d characters in "q".', SmartSearchService::MAX_QUERY_LENGTH)], Response::HTTP_BAD_REQUEST);
        }
        try {
            $candidates = InterpretCandidate::listFromPayload($data['candidates'] ?? null, $user->isAdmin());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->smartSearchInterpretLimiter->create('user:'.$user->getId())->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many AI searches. Try again in a minute.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $language = in_array($data['language'] ?? null, self::LANGUAGES, true) ? $data['language'] : 'en';

        return $this->json($this->interpreter->interpret($user, $query, $candidates, $language)->toArray());
    }
}
