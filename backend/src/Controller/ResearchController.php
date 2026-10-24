<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Module\Chat\ResearchModule;
use App\Service\Research\Run\ResearchCapabilities;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchEventStream;
use App\Service\Research\Run\ResearchRejected;
use App\Service\Research\Run\ResearchRunNotFound;
use App\Service\Research\Run\ResearchRunService;
use App\Service\Research\Run\ResearchRunView;
use App\Service\Research\Run\ResearchStateStore;
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
    public function __construct(
        private readonly ResearchRunService $runs,
        private readonly ResearchCapabilities $capabilities,
        private readonly ResearchEventLog $events,
        private readonly ResearchStateStore $store,
        private readonly ResearchConfig $config,
    ) {
    }

    #[Route('/api/v1/research/capabilities', name: 'api_research_capabilities', methods: ['GET'])]
    #[Route('/v1/research/capabilities', name: 'v1_research_capabilities', methods: ['GET'])]
    #[OA\Get(path: '/api/v1/research/capabilities', summary: 'What research can do on this installation', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Response(response: 200, description: 'Research is on', content: new OA\JsonContent(
        required: ['enabled', 'research_protocol', 'depths', 'read_documents', 'max_active_thorough'],
        properties: [
            new OA\Property(property: 'enabled', type: 'boolean', example: true),
            new OA\Property(property: 'research_protocol', type: 'integer', example: 1),
            new OA\Property(property: 'depths', type: 'array', items: new OA\Items(type: 'string', enum: ['quick', 'standard', 'thorough'])),
            new OA\Property(property: 'web_search_available', type: 'boolean'),
            new OA\Property(property: 'read_documents', type: 'boolean', example: false),
            new OA\Property(property: 'strict_world', type: 'boolean', example: false),
            new OA\Property(property: 'max_active_thorough', type: 'integer', example: 1),
        ]
    ))]
    #[OA\Response(response: 404, description: 'Research is turned off')]
    public function capabilities(#[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->signedIn($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $closed = $this->closed();
        if (null !== $closed) {
            return $closed;
        }

        return new JsonResponse($this->capabilities->describe($user));
    }

    #[Route('/api/v1/research', name: 'api_research_start', methods: ['POST'])]
    #[Route('/v1/research', name: 'v1_research_start', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/research',
        summary: 'Start a research run',
        description: 'Returns 202 and the same id when the Idempotency-Key header repeats within 24 hours. The session route requires chat_id. POST /v1/research is the API-key route and rejects chat_id. The report is written into the chat by the server.',
        security: [['Bearer' => []], ['ApiKey' => []]],
        tags: ['Research']
    )]
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
    #[OA\Response(response: 400, description: 'The request is not valid')]
    #[OA\Response(response: 404, description: 'Research is turned off, or the chat was not found')]
    #[OA\Response(response: 429, description: 'Too many research runs are already going')]
    public function start(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->signedIn($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $closed = $this->closed();
        if (null !== $closed) {
            return $closed;
        }
        $body = $this->body($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        try {
            $state = $this->runs->start($user, $body, $this->session($request), $request->headers->get('Idempotency-Key'));
        } catch (ResearchRejected $rejected) {
            return new JsonResponse($rejected->body(), $rejected->status);
        }

        return new JsonResponse(ResearchRunView::accepted($state, $this->events->replay($state->ref->id, 0)), Response::HTTP_ACCEPTED);
    }

    #[Route('/api/v1/research', name: 'api_research_list', methods: ['GET'])]
    #[Route('/v1/research', name: 'v1_research_list', methods: ['GET'])]
    #[OA\Get(path: '/api/v1/research', summary: 'Research runs from the last 48 hours', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: '1'))]
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
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->signedIn($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        return new JsonResponse(['runs' => $this->runs->listRecent($user)]);
    }

    #[Route('/api/v1/research/{id}', name: 'api_research_show', methods: ['GET'])]
    #[Route('/v1/research/{id}', name: 'v1_research_show', methods: ['GET'])]
    #[OA\Get(path: '/api/v1/research/{id}', summary: 'Read one research run', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Response(response: 200, description: 'The run', content: new OA\JsonContent(
        required: ['id', 'status', 'depth', 'question', 'research_protocol'],
        properties: [
            new OA\Property(property: 'id', type: 'string', example: 'rr_0123456789abcdef'),
            new OA\Property(property: 'status', type: 'string', example: 'gathering'),
            new OA\Property(property: 'depth', type: 'string', example: 'standard'),
            new OA\Property(property: 'question', type: 'string'),
            new OA\Property(property: 'research_protocol', type: 'integer', example: 1),
            new OA\Property(property: 'parent', type: 'string', nullable: true),
            new OA\Property(property: 'stop_requested', type: 'boolean'),
            new OA\Property(property: 'outcome', type: 'object', nullable: true),
            new OA\Property(property: 'report', type: 'object', nullable: true),
        ]
    ))]
    #[OA\Response(response: 404, description: 'No such run for this caller')]
    public function show(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->showRun($user, $id);
    }

    #[Route('/api/v1/research/{id}/events', name: 'api_research_events', methods: ['GET'])]
    #[Route('/v1/research/{id}/events', name: 'v1_research_events', methods: ['GET'])]
    #[OA\Get(path: '/api/v1/research/{id}/events', summary: 'Updates recorded for one research run', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Parameter(name: 'after', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Server-sent events since the last one the client has')]
    public function events(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        $denied = $this->signedIn($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $missing = $this->missing($user, $id);
        if (null !== $missing) {
            return $missing;
        }
        $after = $request->headers->get('Last-Event-ID');
        if (!is_string($after) || '' === $after) {
            $after = $request->query->get('after', '0');
        }
        $seq = is_numeric($after) ? (int) $after : 0;

        return new Response(ResearchEventStream::body($this->events->replay($id, $seq)), Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ]);
    }

    #[Route('/api/v1/research/{id}/input', name: 'api_research_input', methods: ['POST'])]
    #[Route('/v1/research/{id}/input', name: 'v1_research_input', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/research/{id}/input', summary: 'Answer a research question or approve its plan', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Response(response: 200, description: 'The run continued')]
    #[OA\Response(response: 404, description: 'Research is turned off, or no such run')]
    #[OA\Response(response: 409, description: 'The run is not waiting for a reply')]
    public function input(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->change($request, $user, $id, true);
    }

    #[Route('/api/v1/research/{id}/cancel', name: 'api_research_cancel', methods: ['POST'])]
    #[Route('/v1/research/{id}/cancel', name: 'v1_research_cancel', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/research/{id}/cancel', summary: 'Stop a research run', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Response(response: 200, description: 'Stop was requested')]
    public function cancel(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->signedIn($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        try {
            $state = $this->runs->cancel($user, $id);
        } catch (ResearchRunNotFound) {
            return $this->notFound();
        }

        return new JsonResponse(ResearchRunView::one($state, true));
    }

    #[Route('/api/v1/research/{id}/followups', name: 'api_research_followups', methods: ['POST'])]
    #[Route('/v1/research/{id}/followups', name: 'v1_research_followups', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/research/{id}/followups', summary: 'Start a follow-up research run', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Response(response: 202, description: 'The follow-up was started')]
    #[OA\Response(response: 404, description: 'Research is turned off, or no such run')]
    public function followups(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->change($request, $user, $id, true);
    }

    #[Route('/api/v1/research/{id}', name: 'api_research_delete', methods: ['DELETE'])]
    #[Route('/v1/research/{id}', name: 'v1_research_delete', methods: ['DELETE'])]
    #[OA\Delete(path: '/api/v1/research/{id}', summary: 'Delete a research run', security: [['Bearer' => []], ['ApiKey' => []]], tags: ['Research'])]
    #[OA\Response(response: 204, description: 'The run, its updates and its cached pages were removed')]
    public function delete(string $id, #[CurrentUser] ?User $user): Response
    {
        $denied = $this->signedIn($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        try {
            $this->runs->delete($user, $id);
        } catch (ResearchRunNotFound) {
            return $this->notFound();
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function change(Request $request, ?User $user, string $id, bool $gated): JsonResponse
    {
        $denied = $this->signedIn($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        if ($gated) {
            $closed = $this->closed();
            if (null !== $closed) {
                return $closed;
            }
        }
        $body = $this->body($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        try {
            $state = str_contains((string) $request->attributes->get('_route'), 'followups')
                ? $this->runs->followUp($user, $id, $body)
                : $this->runs->input($user, $id, $body);
        } catch (ResearchRejected $rejected) {
            return new JsonResponse($rejected->body(), $rejected->status);
        } catch (ResearchRunNotFound) {
            return $this->notFound();
        }
        $status = str_contains((string) $request->attributes->get('_route'), 'followups')
            ? Response::HTTP_ACCEPTED
            : Response::HTTP_OK;
        $payload = Response::HTTP_ACCEPTED === $status
            ? ResearchRunView::accepted($state, $this->events->replay($state->ref->id, 0))
            : ResearchRunView::one($state, $this->store->stopRequested($state->ref->id));

        return new JsonResponse($payload, $status);
    }

    private function showRun(?User $user, string $id): JsonResponse
    {
        $denied = $this->signedIn($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $missing = $this->missing($user, $id);
        if (null !== $missing) {
            return $missing;
        }
        $state = $this->runs->state($user, $id);

        return new JsonResponse(ResearchRunView::one($state, $this->store->stopRequested($id)));
    }

    private function signedIn(?User $user): ?JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Sign in to continue.'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }

    private function closed(): ?JsonResponse
    {
        if ($this->config->isEnabled()) {
            return null;
        }

        return new JsonResponse([
            'error' => 'feature_not_configured',
            'module' => ResearchModule::ID,
            'docs' => 'modules/research',
        ], Response::HTTP_NOT_FOUND);
    }

    private function missing(User $user, string $id): ?JsonResponse
    {
        try {
            $this->runs->state($user, $id);
        } catch (ResearchRunNotFound) {
            return $this->notFound();
        }

        return null;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'not_found',
                'field' => null,
                'message' => 'That research was not found.',
            ],
        ], Response::HTTP_NOT_FOUND);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function body(Request $request): array|JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);
        if (!is_array($decoded)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'invalid',
                    'field' => null,
                    'message' => 'The request has to be a JSON object.',
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        return $decoded;
    }

    private function session(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        return is_string($route) && str_starts_with($route, 'api_research_');
    }
}
