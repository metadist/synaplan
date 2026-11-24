<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Widget;
use App\Repository\WidgetRepository;
use App\Service\Widget\WidgetVisitorIdentity;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The key a widget's embedding site signs visitor ids with. The owner reads
 * it to put it on their server, and replaces it when it has leaked.
 */
#[Route('/api/v1/widgets/{widgetId}/visitor-key', name: 'api_widget_visitor_key_')]
#[OA\Tag(name: 'Widgets')]
final class WidgetVisitorKeyController extends AbstractController
{
    public function __construct(
        private readonly WidgetRepository $widgetRepository,
        private readonly WidgetVisitorIdentity $visitorIdentity,
    ) {
    }

    #[Route('', name: 'show', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets/{widgetId}/visitor-key',
        summary: 'Signing key for visitor ids (widget owner only)',
        description: 'The embedding site signs `externalUserId` on its server with this key. Sign `widgetId + "\n" + externalUserId + "\n" + expiry` with HMAC-SHA256 and pass `<expiry>.<hex mac>` to the widget as `externalUserIdSignature`. `expiry` is a Unix timestamp at most `maxLifetimeSeconds` ahead.',
        security: [['Bearer' => []]],
        tags: ['Widgets']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'wdg_abc123'))]
    #[OA\Response(response: 200, description: 'The signing key', content: new OA\JsonContent(
        required: ['key', 'maxLifetimeSeconds', 'signatureRequired'],
        properties: [
            new OA\Property(property: 'key', type: 'string', description: 'Hex HMAC-SHA256 key; keep it on the server', example: '5f2b…'),
            new OA\Property(property: 'maxLifetimeSeconds', type: 'integer', description: 'How far ahead a signature may expire', example: 86400),
            new OA\Property(property: 'signatureRequired', type: 'boolean', description: 'False only for a widget from before signing whose owner has not turned signing on', example: true),
        ]
    ))]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Not the widget owner')]
    #[OA\Response(response: 404, description: 'Widget not found')]
    public function show(string $widgetId, #[CurrentUser] ?User $user): JsonResponse
    {
        $widget = $this->ownedWidget($widgetId, $user);
        if ($widget instanceof JsonResponse) {
            return $widget;
        }

        return $this->keyResponse($widget);
    }

    #[Route('/rotate', name: 'rotate', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widgets/{widgetId}/visitor-key/rotate',
        summary: 'Replace the signing key for visitor ids (widget owner only)',
        description: 'Every signature made with the previous key stops working at once.',
        security: [['Bearer' => []]],
        tags: ['Widgets']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'wdg_abc123'))]
    #[OA\Response(response: 200, description: 'The new signing key', content: new OA\JsonContent(
        required: ['key', 'maxLifetimeSeconds', 'signatureRequired'],
        properties: [
            new OA\Property(property: 'key', type: 'string', description: 'Hex HMAC-SHA256 key; keep it on the server', example: '5f2b…'),
            new OA\Property(property: 'maxLifetimeSeconds', type: 'integer', description: 'How far ahead a signature may expire', example: 86400),
            new OA\Property(property: 'signatureRequired', type: 'boolean', description: 'False only for a widget from before signing whose owner has not turned signing on', example: true),
        ]
    ))]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Not the widget owner')]
    #[OA\Response(response: 404, description: 'Widget not found')]
    public function rotate(string $widgetId, #[CurrentUser] ?User $user): JsonResponse
    {
        $widget = $this->ownedWidget($widgetId, $user);
        if ($widget instanceof JsonResponse) {
            return $widget;
        }

        $this->visitorIdentity->rotate($widget);

        return $this->keyResponse($widget);
    }

    #[Route('/require-signature', name: 'require_signature', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widgets/{widgetId}/visitor-key/require-signature',
        summary: 'Stop accepting unsigned visitor ids (widget owner only)',
        description: 'For a widget from before signing: from now on an unsigned `externalUserId` is dropped. It cannot be turned off again. A widget that already requires the signature is left as it is.',
        security: [['Bearer' => []]],
        tags: ['Widgets']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'wdg_abc123'))]
    #[OA\Response(response: 200, description: 'Signing is required', content: new OA\JsonContent(
        required: ['signatureRequired'],
        properties: [
            new OA\Property(property: 'signatureRequired', type: 'boolean', example: true),
        ]
    ))]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Not the widget owner')]
    #[OA\Response(response: 404, description: 'Widget not found')]
    public function requireSignature(string $widgetId, #[CurrentUser] ?User $user): JsonResponse
    {
        $widget = $this->ownedWidget($widgetId, $user);
        if ($widget instanceof JsonResponse) {
            return $widget;
        }

        $this->visitorIdentity->requireSignature($widget);

        return $this->json(['signatureRequired' => $this->visitorIdentity->signatureRequired($widget)]);
    }

    private function ownedWidget(string $widgetId, ?User $user): Widget|JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $widget = $this->widgetRepository->findByWidgetId($widgetId);
        if (!$widget instanceof Widget) {
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }
        if ($widget->getOwnerId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        return $widget;
    }

    private function keyResponse(Widget $widget): JsonResponse
    {
        $response = $this->json([
            'key' => $this->visitorIdentity->signingKey($widget),
            'maxLifetimeSeconds' => WidgetVisitorIdentity::MAX_LIFETIME_SECONDS,
            'signatureRequired' => $this->visitorIdentity->signatureRequired($widget),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
