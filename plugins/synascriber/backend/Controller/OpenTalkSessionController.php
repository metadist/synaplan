<?php

declare(strict_types=1);

namespace Plugin\SynaScriber\Controller;

use OpenApi\Attributes as OA;
use Plugin\SynaScriber\Service\SessionException;
use Plugin\SynaScriber\Service\SessionService;
use Plugin\SynaScriber\Service\Settings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OpenTalk's transcriber opens a session here. The same shared secret as the
 * other transcriber routes; no Synaplan account is involved.
 */
#[OA\Tag(name: 'synaScriber Plugin')]
final class OpenTalkSessionController extends AbstractController
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly Settings $settings,
    ) {
    }

    #[Route('/api/v1/plugins/synascriber/public/transcriber/sessions', name: 'api_plugin_synascriber_transcriber_create', methods: ['POST'])]
    #[OA\Post(summary: 'Open a subtitle session for one OpenTalk meeting')]
    #[OA\Parameter(name: 'X-Transcriber-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['room'], properties: [
        new OA\Property(property: 'room', type: 'string', example: 'd7f813a4-b294-45f4-b560-86467c8c4be7'),
        new OA\Property(property: 'language', type: 'string', example: 'de'),
    ]))]
    #[OA\Response(response: 201, description: 'Session opened, or the one already running in this meeting', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'ref', type: 'string', example: 'Ab3dEf9hJkLmN0pQrStUvW'),
        new OA\Property(property: 'language', type: 'string', example: 'de'),
    ]))]
    #[OA\Response(response: 403, description: 'OpenTalk subtitles are turned off, or the secret does not match')]
    public function create(Request $request): JsonResponse
    {
        $secret = $this->settings->transcriberSecret();
        if ('' === $secret) {
            return $this->json(['error' => 'not_configured', 'message' => 'Meeting notes have no transcriber secret yet. An administrator sets transcriber_secret.'], 503);
        }
        if (!hash_equals($secret, (string) $request->headers->get(TranscriberController::SECRET_HEADER, ''))) {
            return $this->json(['error' => 'not_allowed', 'message' => 'Only the transcriber may call these routes; the transcriber secret does not match.'], 403);
        }

        try {
            $body = $request->toArray();
        } catch (JsonException) {
            return $this->json(['error' => 'invalid_request', 'message' => 'Expected a JSON body with the meeting.'], 400);
        }

        try {
            $session = $this->sessions->beginOpenTalk((string) ($body['room'] ?? ''), (string) ($body['language'] ?? ''));
        } catch (SessionException $e) {
            return $this->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->httpStatus);
        }

        return $this->json(['ref' => $session['ref'], 'language' => $session['language']], 201);
    }
}
