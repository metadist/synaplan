<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\VoiceLiveRejectedException;
use App\Service\Voice\VoiceLiveSessionService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Live voice for a signed-in user. Audio never arrives here.
 */
#[Route('/api/v1/voice/live', name: 'api_voice_live_')]
#[OA\Tag(name: 'Live voice')]
final class VoiceLiveController extends AbstractController
{
    public function __construct(
        private readonly VoiceLiveSessionService $sessions,
    ) {
    }

    #[Route('/options', name: 'options', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/voice/live/options',
        summary: 'Whether this user can start a live conversation',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Availability, models, budget and consent',
                content: new OA\JsonContent(
                    required: ['available', 'budgetExhausted', 'consentGiven', 'maxSessionSeconds'],
                    properties: [
                        new OA\Property(property: 'available', type: 'boolean'),
                        new OA\Property(property: 'reason', type: 'string'),
                        new OA\Property(property: 'models', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'id', type: 'integer', nullable: true),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'provider', type: 'string'),
                            new OA\Property(property: 'voice', type: 'string'),
                            new OA\Property(property: 'voices', type: 'array', items: new OA\Items(type: 'string')),
                            new OA\Property(property: 'priceIn', type: 'number', description: 'Typical voice cost in dollars per minute. Zero means free. The picker compares models with this, not the raw catalog unit.'),
                            new OA\Property(property: 'priceOut', type: 'number', description: 'Always 0. The picker averages priceIn and priceOut like the other model menus.'),
                            new OA\Property(property: 'priceKnown', type: 'boolean'),
                        ], type: 'object')),
                        new OA\Property(property: 'defaultModelId', type: 'integer', nullable: true),
                        new OA\Property(property: 'estimatedSecondsLeft', type: 'integer', nullable: true),
                        new OA\Property(property: 'budgetExhausted', type: 'boolean'),
                        new OA\Property(property: 'costBudget', required: ['limit', 'used', 'remaining', 'userLevel', 'phoneVerified', 'topupAvailable'], nullable: true, properties: [
                            new OA\Property(property: 'limit', type: 'string'),
                            new OA\Property(property: 'used', type: 'string'),
                            new OA\Property(property: 'remaining', type: 'string'),
                            new OA\Property(property: 'userLevel', type: 'string'),
                            new OA\Property(property: 'phoneVerified', type: 'boolean'),
                            new OA\Property(property: 'topupAvailable', type: 'boolean'),
                        ], type: 'object'),
                        new OA\Property(property: 'consentGiven', type: 'boolean'),
                        new OA\Property(property: 'maxSessionSeconds', type: 'integer'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not signed in'),
        ]
    )]
    public function options(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        return $this->json($this->sessions->options($user));
    }

    #[Route('/sessions', name: 'sessions_start', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/voice/live/sessions',
        summary: 'Start a live conversation and mint a one-time socket ticket',
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'modelId', type: 'integer', nullable: true),
                    new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                    new OA\Property(property: 'voice', type: 'string', nullable: true),
                    new OA\Property(property: 'language', type: 'string'),
                    new OA\Property(property: 'client', type: 'string', enum: ['web', 'ios', 'android', 'carplay']),
                    new OA\Property(property: 'incognito', type: 'boolean'),
                    new OA\Property(property: 'useSavedContext', type: 'boolean', description: 'Incognito only. False skips saved memories and knowledge. Omitted keeps them.'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Ticket, socket URL, audio format and allowed seconds',
                content: new OA\JsonContent(
                    required: ['sessionId', 'ticket', 'wsUrl', 'provider', 'model', 'transport'],
                    properties: [
                        new OA\Property(property: 'sessionId', type: 'integer'),
                        new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                        new OA\Property(property: 'ticket', type: 'string'),
                        new OA\Property(property: 'wsUrl', type: 'string'),
                        new OA\Property(property: 'audio', properties: [
                            new OA\Property(property: 'format', type: 'string', example: 'pcm16'),
                            new OA\Property(property: 'inRate', type: 'integer'),
                            new OA\Property(property: 'outRate', type: 'integer'),
                        ], type: 'object'),
                        new OA\Property(property: 'allowedSeconds', type: 'integer'),
                        new OA\Property(property: 'warnAtSeconds', type: 'integer'),
                        new OA\Property(property: 'provider', type: 'string'),
                        new OA\Property(property: 'model', type: 'string'),
                        new OA\Property(property: 'voice', type: 'string'),
                        new OA\Property(property: 'transport', type: 'string', enum: ['relay', 'webrtc']),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not signed in'),
            new OA\Response(response: 403, description: 'Consent is missing'),
            new OA\Response(response: 404, description: 'Live voice is off'),
            new OA\Response(response: 409, description: 'Already in a conversation, or no model'),
            new OA\Response(response: 429, description: 'Monthly budget or start rate'),
            new OA\Response(response: 503, description: 'Gateway capacity or Redis unavailable'),
        ]
    )]
    public function start(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        return $this->call(fn (): array => $this->sessions->start($user, $this->body($request), $request));
    }

    #[Route('/sessions/{id}/ticket', name: 'sessions_ticket', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/voice/live/sessions/{id}/ticket',
        summary: 'Mint a fresh socket ticket for a live conversation that is still open',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Ticket and socket URL for the same session',
                content: new OA\JsonContent(
                    required: ['sessionId', 'ticket', 'wsUrl', 'transport'],
                    properties: [
                        new OA\Property(property: 'sessionId', type: 'integer'),
                        new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                        new OA\Property(property: 'ticket', type: 'string'),
                        new OA\Property(property: 'wsUrl', type: 'string'),
                        new OA\Property(property: 'audio', properties: [
                            new OA\Property(property: 'format', type: 'string'),
                            new OA\Property(property: 'inRate', type: 'integer'),
                            new OA\Property(property: 'outRate', type: 'integer'),
                        ], type: 'object'),
                        new OA\Property(property: 'transport', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Session not found or already ended'),
        ]
    )]
    public function resumeTicket(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        return $this->call(fn (): array => $this->sessions->resumeTicket($user, $id, $request));
    }

    #[Route('/sessions/{id}/end', name: 'sessions_end', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/voice/live/sessions/{id}/end',
        summary: 'End a live conversation',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Session status',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'sessionId', type: 'integer'),
                        new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'seconds', type: 'integer'),
                        new OA\Property(property: 'endReason', type: 'string', nullable: true),
                        new OA\Property(property: 'provider', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Session not found'),
        ]
    )]
    public function end(Request $request, int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        return $this->call(fn (): array => $this->sessions->end($user, $id, $this->stringReason($this->body($request))));
    }

    #[Route('/sessions/{id}', name: 'sessions_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/voice/live/sessions/{id}',
        summary: 'Status of one live conversation',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Session status',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'sessionId', type: 'integer'),
                        new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'seconds', type: 'integer'),
                        new OA\Property(property: 'endReason', type: 'string', nullable: true),
                        new OA\Property(property: 'provider', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Session not found'),
        ]
    )]
    public function status(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        return $this->call(fn (): array => $this->sessions->status($user, $id));
    }

    #[Route('/consent', name: 'consent', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/voice/live/consent',
        summary: 'Remember whether this user allows audio to be sent to the voice provider',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['given'],
                properties: [new OA\Property(property: 'given', type: 'boolean')]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Consent stored',
                content: new OA\JsonContent(
                    required: ['given'],
                    properties: [new OA\Property(property: 'given', type: 'boolean')]
                )
            ),
            new OA\Response(response: 401, description: 'Not signed in'),
        ]
    )]
    public function consent(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $body = $this->body($request);
        $this->sessions->setConsent($user, true === ($body['given'] ?? false));

        return $this->json(['given' => true === ($body['given'] ?? false)]);
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    private function call(callable $action): JsonResponse
    {
        try {
            return $this->json($action());
        } catch (VoiceLiveRejectedException $e) {
            return $this->json($e->payload(), $e->statusCode());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }

        try {
            return $request->toArray();
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringReason(array $body): string
    {
        $reason = $body['reason'] ?? 'user_end';

        return is_string($reason) ? $reason : 'user_end';
    }
}
