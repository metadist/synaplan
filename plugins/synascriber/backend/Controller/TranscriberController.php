<?php

declare(strict_types=1);

namespace Plugin\SynaScriber\Controller;

use App\Entity\User;
use OpenApi\Attributes as OA;
use Plugin\SynaScriber\Service\SessionException;
use Plugin\SynaScriber\Service\SessionService;
use Plugin\SynaScriber\Service\Settings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The transcriber's session API (docs/02_architecture.md §5), called with the
 * plugin owner's API key. Every call is checked against a live session: no
 * session, no transcription.
 */
#[Route('/api/v1/plugins/synascriber/public/transcriber/sessions/{ref}', name: 'api_plugin_synascriber_transcriber_', requirements: ['ref' => '[A-Za-z0-9_-]{22}'])]
#[OA\Tag(name: 'synaScriber Plugin')]
final class TranscriberController extends AbstractController
{
    /** Seconds the transcriber waits before retrying when speech recognition is down. */
    private const RETRY_AFTER_SECONDS = 5;

    public function __construct(
        private readonly SessionService $sessions,
        private readonly Settings $settings,
    ) {
    }

    #[Route('', name: 'bind', methods: ['GET'])]
    #[OA\Get(summary: 'Bind a bridge connection to a live session (on connect and every 30 s)')]
    #[OA\Parameter(name: 'meetingId', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Binding', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'sessionId', type: 'string'),
        new OA\Property(property: 'state', type: 'string', enum: ['starting', 'running', 'stopping']),
        new OA\Property(property: 'language', type: 'string', example: 'de'),
        new OA\Property(property: 'profile', type: 'string', example: 'balanced'),
        new OA\Property(property: 'captions', type: 'boolean'),
        new OA\Property(property: 'glossary', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'speakers', type: 'object', additionalProperties: new OA\AdditionalProperties(properties: [new OA\Property(property: 'name', type: 'string')])),
        new OA\Property(property: 'excluded', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'maxConcurrency', type: 'integer', example: 2),
    ]))]
    #[OA\Response(response: 404, description: 'Unknown session')]
    #[OA\Response(response: 409, description: 'Session ended or belongs to another meeting: close the connection')]
    public function bind(string $ref, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->denied($user)) {
            return $denied;
        }
        try {
            return $this->json($this->sessions->bind($ref, (string) $request->query->get('meetingId', '')));
        } catch (SessionException $e) {
            return $this->error($e);
        }
    }

    #[Route('/audio', name: 'audio', methods: ['POST'])]
    #[OA\Post(summary: 'One speech window of one speaker (the only way audio enters Synaplan)')]
    #[OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['audio', 'endpointId', 'seq', 't0Ms', 't1Ms', 'cut'], properties: [
        new OA\Property(property: 'audio', type: 'string', format: 'binary', description: 'Ogg Opus, at most 2 MB and 30 s'),
        new OA\Property(property: 'endpointId', type: 'string'),
        new OA\Property(property: 'seq', type: 'integer'),
        new OA\Property(property: 't0Ms', type: 'integer', description: 'Window start, ms after the transcriber connected'),
        new OA\Property(property: 't1Ms', type: 'integer'),
        new OA\Property(property: 'cut', type: 'string', enum: ['pause', 'soft', 'hard', 'stop']),
        new OA\Property(property: 'prompt', type: 'string', description: 'Previous text and glossary (at most 500 characters)'),
    ])))]
    #[OA\Response(response: 200, description: 'Recognised text', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'seq', type: 'integer'),
        new OA\Property(property: 'text', type: 'string'),
        new OA\Property(property: 'language', type: 'string'),
        new OA\Property(property: 'speaker', type: 'string'),
        new OA\Property(property: 'dropped', type: 'string', nullable: true, enum: ['no_speech', 'hallucination', 'duplicate']),
        new OA\Property(property: 'segmentSeq', type: 'integer'),
    ]))]
    #[OA\Response(response: 409, description: 'Session does not accept audio any more')]
    #[OA\Response(response: 503, description: 'Speech recognition unavailable; retry after Retry-After seconds')]
    public function audio(string $ref, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->denied($user)) {
            return $denied;
        }
        try {
            return $this->json($this->sessions->window($ref, $this->windowFrom($request) + ['cut' => (string) $request->request->get('cut', '')]));
        } catch (SessionException $e) {
            return $this->error($e);
        }
    }

    #[Route('/preview', name: 'preview', methods: ['POST'])]
    #[OA\Post(summary: 'Live caption of the window so far: recognised, never stored')]
    #[OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['audio', 'endpointId', 'seq', 't0Ms', 't1Ms'], properties: [
        new OA\Property(property: 'audio', type: 'string', format: 'binary'),
        new OA\Property(property: 'endpointId', type: 'string'),
        new OA\Property(property: 'seq', type: 'integer', description: 'Number the final window will have'),
        new OA\Property(property: 't0Ms', type: 'integer'),
        new OA\Property(property: 't1Ms', type: 'integer'),
    ])))]
    #[OA\Response(response: 200, description: 'Recognised text so far', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'seq', type: 'integer'),
        new OA\Property(property: 'text', type: 'string'),
        new OA\Property(property: 'language', type: 'string'),
        new OA\Property(property: 'dropped', type: 'string', nullable: true, enum: ['no_speech', 'hallucination']),
        new OA\Property(property: 'speechEndMs', type: 'integer', nullable: true, description: 'Where the last speech ends, ms from the window start'),
        new OA\Property(property: 'segmentEndsMs', type: 'array', items: new OA\Items(type: 'integer'), description: 'Where each recognised phrase ends, ms from the window start, ascending'),
    ]))]
    #[OA\Response(response: 403, description: 'Live captions are switched off')]
    #[OA\Response(response: 409, description: 'Session is not running')]
    public function preview(string $ref, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->denied($user)) {
            return $denied;
        }
        try {
            return $this->json($this->sessions->preview($ref, $this->windowFrom($request)));
        } catch (SessionException $e) {
            return $this->error($e);
        }
    }

    #[Route('/events', name: 'events', methods: ['POST'])]
    #[OA\Post(summary: 'Transcriber lifecycle event')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['type'], properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['connected', 'speaker-start', 'speaker-stop', 'stt-failure', 'session-end', 'finished']),
        new OA\Property(property: 'endpointId', type: 'string'),
        new OA\Property(property: 'at', type: 'integer', description: 'ms since epoch'),
        new OA\Property(property: 'detail', type: 'string'),
    ]))]
    #[OA\Response(response: 204, description: 'Accepted')]
    #[OA\Response(response: 404, description: 'Unknown session')]
    public function events(string $ref, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (null !== $denied = $this->denied($user)) {
            return $denied;
        }
        try {
            $this->sessions->event($ref, $request->toArray());
        } catch (SessionException $e) {
            return $this->error($e);
        } catch (JsonException) {
            return $this->json(['error' => 'invalid_event', 'message' => 'The event is not valid JSON.'], 422);
        }

        return new Response(null, 204);
    }

    /**
     * @return array{endpointId: string, seq: int, t0Ms: int, t1Ms: int, audio: string}
     */
    private function windowFrom(Request $request): array
    {
        $file = $request->files->get('audio');
        $audio = $file instanceof UploadedFile && $file->isValid() ? (string) file_get_contents($file->getPathname()) : '';

        return [
            'endpointId' => (string) $request->request->get('endpointId', ''),
            'seq' => (int) $request->request->get('seq', '-1'),
            't0Ms' => (int) $request->request->get('t0Ms', '-1'),
            't1Ms' => (int) $request->request->get('t1Ms', '-1'),
            'audio' => $audio,
        ];
    }

    private function error(SessionException $e): JsonResponse
    {
        $status = in_array($e->errorCode, ['stt_unreachable', 'stt_error'], true) ? 503 : $e->httpStatus;
        $response = $this->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $status);
        if (503 === $status) {
            $response->headers->set('Retry-After', (string) self::RETRY_AFTER_SECONDS);
        }

        return $response;
    }

    private function denied(?User $user): ?JsonResponse
    {
        $owner = $this->settings->ownerUserId();
        if (!$user instanceof User || $owner <= 0 || (int) $user->getId() !== $owner) {
            return $this->json(['error' => 'not_allowed', 'message' => 'Only the plugin owner\'s API key may call the transcriber routes.'], 403);
        }

        return null;
    }
}
