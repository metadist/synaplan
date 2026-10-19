<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\FreeApiError;
use App\DTO\FreeApiStatus;
use App\Entity\User;
use App\Service\FreeApi\FreeApiActivator;
use App\Service\FreeApi\FreeApiException;
use App\Service\FreeApi\FreeApiStatusReader;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Synaplan Demo on this install: everyone signed in reads its state,
 * admins start and leave it. Starting needs the person's explicit consent,
 * because their messages are then answered by web.synaplan.com.
 *
 * All routes answer 404 `feature_not_configured` while the module is off.
 */
#[OA\Tag(name: 'Synaplan Demo')]
final class FreeApiController extends AbstractController
{
    public const CONSENT_REQUIRED = 'freeapi_consent_required';

    /** Codes that describe this install, not the Demo server. */
    private const CONFLICTS = [FreeApiException::ALREADY_ACTIVE, FreeApiException::OWN_ENDPOINT, FreeApiException::CHAT_READY];

    /** Statuses the Demo server answers on purpose; anything else is a bad gateway. */
    private const PASSED_ON = [Response::HTTP_FORBIDDEN, Response::HTTP_TOO_MANY_REQUESTS, Response::HTTP_SERVICE_UNAVAILABLE];

    public function __construct(
        private readonly FreeApiActivator $activator,
        private readonly FreeApiStatusReader $status,
    ) {
    }

    #[Route('/api/v1/freeapi/status', name: 'api_freeapi_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/freeapi/status',
        summary: 'Get the Synaplan Demo state',
        description: "Whether the Demo answers chat on this install, until when, and today's remaining messages (cached for a minute). activatedBy and installId are filled for admins only.",
        security: [['Bearer' => []]],
        tags: ['Synaplan Demo'],
        responses: [
            new OA\Response(response: 200, description: 'Demo state', content: new OA\JsonContent(ref: new Model(type: FreeApiStatus::class))),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'The Synaplan Demo module is turned off'),
        ]
    )]
    public function status(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($this->status->read($user->isAdmin(), time()));
    }

    #[Route('/api/v1/admin/freeapi/activate', name: 'api_freeapi_activate', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
    #[OA\Post(
        path: '/api/v1/admin/freeapi/activate',
        summary: 'Start the Synaplan Demo',
        description: 'Asks web.synaplan.com for a limited key and moves the chat, sorting, vision, image and (while no documents are indexed) embedding defaults onto the Demo models. The previous defaults are recorded and come back on leave. Admin only.',
        security: [['Bearer' => []]],
        tags: ['Synaplan Demo'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['consent'],
                properties: [
                    new OA\Property(property: 'consent', type: 'boolean', example: true, description: 'The admin accepted that messages and the files asked about are sent to web.synaplan.com. Must be true.'),
                    new OA\Property(property: 'force', type: 'boolean', example: false, description: 'Start even though chat already works with an own provider (used by Undo after the Demo was left automatically).'),
                    new OA\Property(property: 'locale', type: 'string', example: 'en', description: 'UI language, passed to the Demo server'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Started; the Demo state', content: new OA\JsonContent(ref: new Model(type: FreeApiStatus::class))),
            new OA\Response(response: 409, description: 'Already on, or this install has its own provider or endpoint', content: new OA\JsonContent(ref: new Model(type: FreeApiError::class))),
            new OA\Response(response: 422, description: 'Consent missing', content: new OA\JsonContent(ref: new Model(type: FreeApiError::class))),
            new OA\Response(response: 429, description: 'The Demo server is busy or full for today', content: new OA\JsonContent(ref: new Model(type: FreeApiError::class))),
            new OA\Response(response: 502, description: 'The Demo server could not be reached or sent an unreadable answer', content: new OA\JsonContent(ref: new Model(type: FreeApiError::class))),
            new OA\Response(response: 503, description: 'The Demo is paused', content: new OA\JsonContent(ref: new Model(type: FreeApiError::class))),
        ]
    )]
    public function activate(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $body = \is_array($body) ? $body : [];
        if (true !== ($body['consent'] ?? null)) {
            return $this->json(['error' => self::CONSENT_REQUIRED, 'message' => 'Starting the Synaplan Demo needs your consent to send messages to web.synaplan.com.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $locale = \is_string($body['locale'] ?? null) ? $body['locale'] : 'en';

        try {
            $this->activator->activate($locale, null, true === ($body['force'] ?? false), $user->getId());
        } catch (FreeApiException $e) {
            return $this->failure($e);
        } finally {
            $this->status->forget();
        }

        return $this->json($this->status->read(true, time()));
    }

    #[Route('/api/v1/admin/freeapi/leave', name: 'api_freeapi_leave', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
    #[OA\Post(
        path: '/api/v1/admin/freeapi/leave',
        summary: 'Leave the Synaplan Demo',
        description: 'Puts the defaults from before the start back, removes the Demo endpoint and models, and revokes the key (retried later when the server is unreachable). Safe to call when the Demo is off. Admin only.',
        security: [['Bearer' => []]],
        tags: ['Synaplan Demo'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Left',
                content: new OA\JsonContent(
                    required: ['wasActive', 'restoredCapabilities', 'keyRevoked', 'status'],
                    properties: [
                        new OA\Property(property: 'wasActive', type: 'boolean', example: true, description: 'false when the Demo was already off'),
                        new OA\Property(property: 'restoredCapabilities', type: 'array', items: new OA\Items(type: 'string', example: 'CHAT'), description: 'Purposes whose previous default came back'),
                        new OA\Property(property: 'keyRevoked', type: 'boolean', example: true, description: 'false when the revoke is retried later'),
                        new OA\Property(property: 'status', ref: new Model(type: FreeApiStatus::class)),
                    ]
                )
            ),
        ]
    )]
    public function leave(): JsonResponse
    {
        try {
            $left = $this->activator->leave();
        } finally {
            $this->status->forget();
        }

        return $this->json([
            'wasActive' => $left->wasActive,
            'restoredCapabilities' => $left->restoredCapabilities,
            'keyRevoked' => $left->keyRevoked,
            'status' => $this->status->read(true, time()),
        ]);
    }

    private function failure(FreeApiException $e): JsonResponse
    {
        $status = match (true) {
            \in_array($e->errorCode, self::CONFLICTS, true) => Response::HTTP_CONFLICT,
            \in_array($e->httpStatus, self::PASSED_ON, true) => $e->httpStatus,
            default => Response::HTTP_BAD_GATEWAY,
        };
        $headers = null !== $e->retryAfterSeconds ? ['Retry-After' => (string) $e->retryAfterSeconds] : [];

        return $this->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $status, $headers);
    }
}
