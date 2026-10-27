<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ResearchErrorView;
use App\DTO\ResearchRunStateView;
use App\Entity\User;
use App\Module\Chat\ResearchModule;
use App\Service\Research\Run\ResearchCapabilities;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchEventStream;
use App\Service\Research\Run\ResearchQueueWait;
use App\Service\Research\Run\ResearchRejected;
use App\Service\Research\Run\ResearchRunNotFound;
use App\Service\Research\Run\ResearchRunService;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchRunView;
use App\Service\Research\Run\ResearchStateStore;
use App\Service\Research\Run\ResearchStoreUnavailable;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Research runs for the signed-in app and for an API key.
 *
 * The same actions live on `/api/v1/research` and `/v1/research`. A new run,
 * the capability list, a reply and a follow-up answer 404 while research is
 * off. Reading, stopping and deleting a run that already exists still work.
 */
#[OA\Tag(name: 'Research')]
final class ResearchController extends AbstractController
{
    private const SESSION_ROUTE_PREFIX = 'api_research_';

    public function __construct(
        private readonly ResearchRunService $runs,
        private readonly ResearchCapabilities $capabilities,
        private readonly ResearchEventLog $events,
        private readonly ResearchStateStore $store,
        private readonly ResearchConfig $config,
        private readonly ResearchQueueWait $queue,
    ) {
    }

    #[Route('/api/v1/research/capabilities', name: 'api_research_capabilities', methods: ['GET'])]
    #[Route('/v1/research/capabilities', name: 'v1_research_capabilities', methods: ['GET'])]
    #[OA\Get(path: '/api/v1/research/capabilities', summary: 'What research can do on this installation', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Response(response: 200, description: 'Research is on', content: new OA\JsonContent(
        required: ['enabled', 'research_protocol', 'depths', 'web_search_available', 'read_documents', 'max_active_thorough'],
        properties: [
            new OA\Property(property: 'enabled', type: 'boolean', example: true),
            new OA\Property(property: 'research_protocol', type: 'integer', example: 1),
            new OA\Property(property: 'depths', type: 'array', items: new OA\Items(type: 'string', enum: ['quick', 'standard', 'thorough'])),
            new OA\Property(property: 'web_search_available', type: 'boolean', example: true),
            new OA\Property(property: 'web_search_provider', type: 'object', nullable: true, properties: [
                new OA\Property(property: 'id', type: 'string', example: 'brave'),
                new OA\Property(property: 'self_hosted', type: 'boolean', example: false),
                new OA\Property(property: 'country', type: 'string', nullable: true, example: 'US'),
            ]),
            new OA\Property(property: 'knowledge_available', type: 'boolean', example: false),
            new OA\Property(property: 'knowledge_index_model', type: 'string', nullable: true, example: null),
            new OA\Property(property: 'read_documents', type: 'boolean', example: false),
            new OA\Property(property: 'strict_world', type: 'boolean', example: false),
            new OA\Property(property: 'max_active_thorough', type: 'integer', example: 1),
            new OA\Property(property: 'limits', type: 'object', properties: [
                new OA\Property(property: 'standard_per_day', type: 'integer', nullable: true, example: null),
                new OA\Property(property: 'thorough_per_day', type: 'integer', nullable: true, example: null),
            ]),
        ]
    ))]
    #[OA\Response(response: 401, description: 'Not signed in', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    #[OA\Response(response: 404, description: 'Research is turned off')]
    public function capabilities(#[CurrentUser] ?User $user): Response
    {
        return $this->respond($user, true, fn (User $user): Response => new JsonResponse($this->capabilities->describe($user)));
    }

    #[Route('/api/v1/research', name: 'api_research_start', methods: ['POST'])]
    #[Route('/v1/research', name: 'v1_research_start', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/research',
        summary: 'Start a research run',
        description: 'Returns 202 and the same id when the Idempotency-Key header repeats within 24 hours. The session route requires chat_id; the report and the outcome are written into that chat by the server. POST /v1/research is the API-key route and rejects chat_id.',
        security: [['Bearer' => []], ['ApiKey' => []]],
        tags: ['Research']
    )]
    #[OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string', maxLength: 64, pattern: '^[A-Za-z0-9._:-]+$'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['question', 'depth'],
        properties: [
            new OA\Property(property: 'question', type: 'string', maxLength: 4000, example: 'Which suppliers deliver X in Germany?'),
            new OA\Property(property: 'depth', type: 'string', enum: ['quick', 'standard', 'thorough']),
            new OA\Property(property: 'chat_id', type: 'integer', nullable: true, example: 4711),
        ]
    ))]
    #[OA\Response(response: 202, description: 'The run was started', content: new OA\JsonContent(
        required: ['id', 'status', 'events', 'research_protocol'],
        properties: [
            new OA\Property(property: 'id', type: 'string', example: 'rr_0123456789abcdef'),
            new OA\Property(property: 'status', type: 'string', example: 'briefing'),
            new OA\Property(property: 'research_protocol', type: 'integer', example: 1),
            new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'object')),
        ]
    ))]
    #[OA\Response(response: 400, description: 'The request is not valid', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    #[OA\Response(response: 404, description: 'Research is turned off, or the chat was not found')]
    #[OA\Response(response: 409, description: 'A start with the same Idempotency-Key is still being set up', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    #[OA\Response(response: 429, description: 'Too many research runs are already going', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    #[OA\Response(response: 503, description: 'The research store did not answer', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    public function start(Request $request, #[CurrentUser] ?User $user): Response
    {
        return $this->respond($user, true, function (User $user) use ($request): Response {
            $session = str_starts_with((string) $request->attributes->get('_route'), self::SESSION_ROUTE_PREFIX);
            $state = $this->runs->start($user, $this->body($request), $session, $request->headers->get('Idempotency-Key'));

            return $this->accepted($state);
        });
    }

    #[Route('/api/v1/research', name: 'api_research_list', methods: ['GET'])]
    #[Route('/v1/research', name: 'v1_research_list', methods: ['GET'])]
    #[OA\Get(path: '/api/v1/research', summary: 'Research runs from the last 48 hours, newest first', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Parameter(name: 'active', in: 'query', required: false, description: '1 lists only runs that are still going', schema: new OA\Schema(type: 'string', enum: ['0', '1'], example: '1'))]
    #[OA\Response(response: 200, description: 'The caller\'s runs', content: new OA\JsonContent(
        required: ['runs'],
        properties: [
            new OA\Property(property: 'runs', type: 'array', items: new OA\Items(
                required: ['id', 'status', 'depth', 'question', 'created_at'],
                properties: [
                    new OA\Property(property: 'id', type: 'string'),
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'depth', type: 'string'),
                    new OA\Property(property: 'question', type: 'string'),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                ],
                type: 'object'
            )),
        ]
    ))]
    #[OA\Response(response: 401, description: 'Not signed in', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    public function list(Request $request, #[CurrentUser] ?User $user): Response
    {
        return $this->respond($user, false, fn (User $user): Response => new JsonResponse([
            'runs' => $this->runs->listRecent($user, $request->query->getBoolean('active')),
        ]));
    }

    #[Route('/api/v1/research/{id}', name: 'api_research_show', methods: ['GET'])]
    #[Route('/v1/research/{id}', name: 'v1_research_show', methods: ['GET'])]
    #[OA\Get(path: '/api/v1/research/{id}', summary: 'Read one research run', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Response(response: 200, description: 'The run', content: new OA\JsonContent(ref: new Model(type: ResearchRunStateView::class)))]
    #[OA\Response(response: 404, description: 'No such run for this caller', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    public function show(string $id, #[CurrentUser] ?User $user): Response
    {
        return $this->respond($user, false, fn (User $user): Response => $this->current($this->runs->state($user, $id)));
    }

    #[Route('/api/v1/research/{id}/events', name: 'api_research_events', methods: ['GET'])]
    #[Route('/v1/research/{id}/events', name: 'v1_research_events', methods: ['GET'])]
    #[OA\Get(path: '/api/v1/research/{id}/events', summary: 'Updates recorded for one research run', description: 'Returns the updates recorded so far as server-sent events and closes. Resume with the Last-Event-ID header or ?after=<seq>. When the next step has waited at least 20 seconds for a worker, a last `queued` frame without an id carries since_seconds; it is computed on each request and never replayed.', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Parameter(name: 'after', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 0))]
    #[OA\Response(response: 200, description: 'Server-sent events since the last one the client has', content: new OA\MediaType(mediaType: 'text/event-stream', schema: new OA\Schema(type: 'string')))]
    #[OA\Response(response: 404, description: 'No such run for this caller', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    public function events(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        return $this->respond($user, false, function (User $user) use ($id, $request): Response {
            $state = $this->runs->state($user, $id);
            $after = $request->headers->get('Last-Event-ID');
            if (!is_string($after) || '' === $after) {
                $after = $request->query->get('after', '0');
            }
            $seq = is_numeric($after) ? max(0, (int) $after) : 0;
            $body = ResearchEventStream::body($this->events->replay($id, $seq), $this->queue->since($state));

            return new Response($body, Response::HTTP_OK, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
            ]);
        });
    }

    #[Route('/api/v1/research/{id}/input', name: 'api_research_input', methods: ['POST'])]
    #[Route('/v1/research/{id}/input', name: 'v1_research_input', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/research/{id}/input', summary: 'Answer a research question, approve its plan, or write the report now', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['type'],
        properties: [
            new OA\Property(property: 'type', type: 'string', enum: ['answer', 'skip_question', 'approve_plan', 'edit_plan', 'stop_gathering']),
            new OA\Property(property: 'question', type: 'string', nullable: true, example: 'q1'),
            new OA\Property(property: 'choice', type: 'string', nullable: true, example: 'EU'),
            new OA\Property(property: 'text', type: 'string', nullable: true),
            new OA\Property(
                property: 'points',
                description: 'edit_plan only. A point with an id keeps that point and may rename it; a point with only a title is added; a point left out is dropped.',
                type: 'array',
                nullable: true,
                items: new OA\Items(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'string', nullable: true, example: 'p1'),
                        new OA\Property(property: 'title', type: 'string', nullable: true, maxLength: 300, example: 'Delivery times'),
                    ],
                ),
            ),
        ]
    ))]
    #[OA\Response(response: 200, description: 'The run continued', content: new OA\JsonContent(ref: new Model(type: ResearchRunStateView::class)))]
    #[OA\Response(response: 400, description: 'That reply does not fit the question', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    #[OA\Response(response: 404, description: 'Research is turned off, or no such run')]
    #[OA\Response(response: 409, description: 'The run is not waiting for a reply', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    public function input(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        return $this->respond($user, true, fn (User $user): Response => $this->current(
            $this->runs->input($user, $id, $this->body($request)),
        ));
    }

    #[Route('/api/v1/research/{id}/cancel', name: 'api_research_cancel', methods: ['POST'])]
    #[Route('/v1/research/{id}/cancel', name: 'v1_research_cancel', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/research/{id}/cancel', summary: 'Stop a research run', description: 'A run nobody is working on stops at once. A step that is underway sees the stop before its next paid call; stop_requested is true until then.', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Response(response: 200, description: 'Stop was requested', content: new OA\JsonContent(ref: new Model(type: ResearchRunStateView::class)))]
    #[OA\Response(response: 404, description: 'No such run for this caller', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    public function cancel(string $id, #[CurrentUser] ?User $user): Response
    {
        return $this->respond($user, false, fn (User $user): Response => $this->current($this->runs->cancel($user, $id)));
    }

    #[Route('/api/v1/research/{id}/followups', name: 'api_research_followups', methods: ['POST'])]
    #[Route('/v1/research/{id}/followups', name: 'v1_research_followups', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/research/{id}/followups', summary: 'Start a follow-up research run', description: 'Starts a new run that keeps this run\'s sources and continues its source, evidence and point numbers. A question is short and searches only when the notes have nothing on it. A revision keeps the report and checks only sentences that changed. A deeper look uses the thorough budget and adds a point for each line under Not confirmed. A refresh checks sources older than the time frame and says what changed. The parent must already have finished. A run that is not the caller\'s is not found.', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['type'],
        properties: [
            new OA\Property(property: 'type', type: 'string', enum: ['question', 'revise', 'deeper', 'refresh']),
            new OA\Property(property: 'text', type: 'string', maxLength: 4000, nullable: true, description: 'Required unless type is refresh'),
        ]
    ))]
    #[OA\Response(response: 202, description: 'The follow-up was started', content: new OA\JsonContent(
        required: ['id', 'status', 'events', 'research_protocol'],
        properties: [
            new OA\Property(property: 'id', type: 'string', example: 'rr_0123456789abcdef'),
            new OA\Property(property: 'status', type: 'string', example: 'briefing'),
            new OA\Property(property: 'research_protocol', type: 'integer', example: 1),
            new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'object')),
        ]
    ))]
    #[OA\Response(response: 400, description: 'The follow-up is not valid', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    #[OA\Response(response: 404, description: 'Research is turned off, or no such run')]
    #[OA\Response(response: 429, description: 'Too many research runs are already going', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    public function followups(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        return $this->respond($user, true, fn (User $user): Response => $this->accepted(
            $this->runs->followUp($user, $id, $this->body($request)),
        ));
    }

    #[Route('/api/v1/research/{id}', name: 'api_research_delete', methods: ['DELETE'])]
    #[Route('/v1/research/{id}', name: 'v1_research_delete', methods: ['DELETE'])]
    #[OA\Delete(path: '/api/v1/research/{id}', summary: 'Delete a research run', description: 'Removes the run, its updates and its cached pages. A report already posted into a chat stays there.', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Response(response: 204, description: 'The run, its updates and its cached pages were removed')]
    #[OA\Response(response: 404, description: 'No such run for this caller', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    public function delete(string $id, #[CurrentUser] ?User $user): Response
    {
        return $this->respond($user, false, function (User $user) use ($id): Response {
            $this->runs->delete($user, $id);

            return new Response(null, Response::HTTP_NO_CONTENT);
        });
    }

    /**
     * Sign-in, the off switch and every refusal the service can raise, in
     * one place, so each refusal has the same shape on every route.
     *
     * @param \Closure(User): Response $action
     */
    private function respond(?User $user, bool $needsResearchOn, \Closure $action): Response
    {
        if (!$user instanceof User) {
            return $this->error(Response::HTTP_UNAUTHORIZED, 'unauthorized', 'Sign in to continue.');
        }
        if ($needsResearchOn && !$this->config->isEnabled()) {
            return new JsonResponse([
                'error' => 'feature_not_configured',
                'module' => ResearchModule::ID,
                'docs' => 'modules/research',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            return $action($user);
        } catch (ResearchRejected $rejected) {
            return new JsonResponse($rejected->body(), $rejected->status);
        } catch (ResearchRunNotFound) {
            return $this->error(Response::HTTP_NOT_FOUND, 'not_found', 'That research was not found.');
        } catch (ResearchStoreUnavailable $unavailable) {
            return $this->error(Response::HTTP_SERVICE_UNAVAILABLE, 'unavailable', $unavailable->getMessage());
        }
    }

    private function accepted(ResearchRunState $state): JsonResponse
    {
        return new JsonResponse(ResearchRunView::accepted($state, $this->events->replay($state->ref->id, 0)), Response::HTTP_ACCEPTED);
    }

    private function current(ResearchRunState $state): JsonResponse
    {
        return new JsonResponse(ResearchRunView::one(
            $state,
            $this->store->stopRequested($state->ref->id),
            $this->store->writeNowRequested($state->ref->id),
            $this->queue->since($state),
        ));
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'field' => null, 'message' => $message]], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);
        if (!is_array($decoded)) {
            throw new ResearchRejected(Response::HTTP_BAD_REQUEST, 'invalid', null, 'The request has to be a JSON object.');
        }

        return $decoded;
    }
}
