<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\VoiceLiveRejectedException;
use App\Service\Voice\VoiceLiveInternalAuth;
use App\Service\Voice\VoiceLiveSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Callbacks from the voice sidecar. HMAC is the authentication.
 * These routes are not part of the public API document.
 */
#[Route('/internal/voice', name: 'internal_voice_')]
final class InternalVoiceController extends AbstractController
{
    public function __construct(
        private readonly VoiceLiveInternalAuth $auth,
        private readonly VoiceLiveSessionService $sessions,
    ) {
    }

    #[Route('/tickets/redeem', name: 'redeem', methods: ['POST'])]
    public function redeem(Request $request): JsonResponse
    {
        return $this->run($request, function (array $body): array {
            $ticket = $body['ticket'] ?? '';

            return $this->sessions->redeem(is_string($ticket) ? $ticket : '');
        });
    }

    #[Route('/sessions/{id}/usage', name: 'usage', methods: ['POST'])]
    public function usage(int $id, Request $request): JsonResponse
    {
        return $this->run($request, fn (array $body): array => $this->sessions->applyUsage($id, $body));
    }

    #[Route('/sessions/{id}/delegate', name: 'delegate', methods: ['POST'])]
    public function delegate(int $id, Request $request): JsonResponse
    {
        return $this->run($request, function (array $body) use ($id): array {
            $question = $body['question'] ?? '';

            return $this->sessions->delegate($id, is_string($question) ? $question : '');
        });
    }

    #[Route('/sessions/{id}/transcript', name: 'transcript', methods: ['POST'])]
    public function transcript(int $id, Request $request): JsonResponse
    {
        return $this->run($request, function (array $body) use ($id): array {
            $this->sessions->recordTranscript($id, $body);

            return ['ok' => true];
        });
    }

    #[Route('/sessions/{id}/closed', name: 'closed', methods: ['POST'])]
    public function closed(int $id, Request $request): JsonResponse
    {
        return $this->run($request, function (array $body) use ($id): array {
            $this->sessions->markClosed($id, $body);

            return ['ok' => true];
        });
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $action
     */
    private function run(Request $request, callable $action): JsonResponse
    {
        try {
            $this->auth->assertValid($request);

            return $this->json($action($this->body($request)));
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
}
