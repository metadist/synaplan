<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Module\Sidecar\OpendeskSttModule;
use App\Service\Opendesk\MeetingNoteStore;
use App\Service\Opendesk\OpendeskConnectSnippet;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Saved transcripts and the operator snippet for Meeting notes.
 * The sidecar holds the audio. These routes see text only.
 */
#[OA\Tag(name: 'Meeting notes', description: 'openDesk meeting transcripts (Jitsi and Element)')]
class MeetingNotesController extends AbstractController
{
    public function __construct(
        private readonly OpendeskSttModule $module,
        private readonly MeetingNoteStore $notes,
        private readonly OpendeskConnectSnippet $snippet,
    ) {
    }

    #[Route('/api/v1/opendesk/meeting-notes/connect', name: 'api_opendesk_meeting_notes_connect', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/opendesk/meeting-notes/connect',
        summary: 'Operator snippet for Jitsi and Element',
        security: [['Bearer' => []]],
        tags: ['Meeting notes']
    )]
    #[OA\Response(response: 200, description: 'Snippet')]
    #[OA\Response(response: 404, description: 'Meeting notes are not configured')]
    public function connect(#[CurrentUser] ?User $user): JsonResponse
    {
        $ready = $this->ready($user);
        if ($ready instanceof JsonResponse) {
            return $ready;
        }

        return new JsonResponse($this->snippet->toArray());
    }

    #[Route('/api/v1/opendesk/meeting-notes', name: 'api_opendesk_meeting_notes_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/opendesk/meeting-notes',
        summary: 'List meeting notes for this account',
        security: [['Bearer' => []]],
        tags: ['Meeting notes']
    )]
    #[OA\Response(
        response: 200,
        description: 'Notes, newest first',
        content: new OA\JsonContent(
            required: ['object', 'data', 'message'],
            properties: [
                new OA\Property(property: 'object', type: 'string', example: 'list'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'message', type: 'string', example: 'These are the meeting notes saved for this account. Audio was not kept.'),
            ]
        )
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        $ready = $this->ready($user);
        if ($ready instanceof JsonResponse) {
            return $ready;
        }

        return new JsonResponse([
            'object' => 'list',
            'data' => $this->notes->list((int) $ready->getId()),
            'message' => 'These are the meeting notes saved for this account. Audio was not kept.',
        ]);
    }

    #[Route('/api/v1/opendesk/meeting-notes/{id}', name: 'api_opendesk_meeting_notes_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/opendesk/meeting-notes/{id}',
        summary: 'Read one meeting note',
        security: [['Bearer' => []]],
        tags: ['Meeting notes']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Note')]
    #[OA\Response(response: 404, description: 'Note not found')]
    public function get(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $ready = $this->ready($user);
        if ($ready instanceof JsonResponse) {
            return $ready;
        }

        $note = $this->notes->get((int) $ready->getId(), $id);
        if (null === $note) {
            return new JsonResponse([
                'error' => 'not_found',
                'message' => 'That meeting note is not in this account.',
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($note);
    }

    #[Route('/api/v1/opendesk/meeting-notes', name: 'api_opendesk_meeting_notes_save', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/opendesk/meeting-notes',
        summary: 'Save a finished transcript',
        description: 'Called by the Meeting notes sidecar when a meeting or voice message ends. Audio is not accepted.',
        security: [['Bearer' => []]],
        tags: ['Meeting notes']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['source', 'text'],
            properties: [
                new OA\Property(property: 'source', type: 'string', enum: ['jitsi', 'element'], example: 'jitsi'),
                new OA\Property(property: 'text', type: 'string', example: 'Ada: We ship on Friday.'),
                new OA\Property(property: 'room', type: 'string', example: '!project:example'),
                new OA\Property(property: 'folder', type: 'string', example: '/Meetings'),
                new OA\Property(property: 'language', type: 'string', example: 'de'),
                new OA\Property(property: 'started_by', type: 'string', example: 'ada'),
                new OA\Property(property: 'meeting_id', type: 'string', example: 'standup'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Saved')]
    #[OA\Response(response: 400, description: 'The transcript could not be saved')]
    public function save(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $ready = $this->ready($user);
        if ($ready instanceof JsonResponse) {
            return $ready;
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return $this->badRequest('Send the transcript as JSON.');
        }

        try {
            $note = $this->notes->save((int) $ready->getId(), $payload);
        } catch (\InvalidArgumentException $e) {
            return $this->badRequest($e->getMessage());
        }

        return new JsonResponse([
            'id' => $note['id'],
            'saved' => true,
            'audio_retained' => false,
            'source' => $note['source'],
            'room' => $note['room'],
            'folder' => $note['folder'],
            'created_at' => $note['created_at'],
            'message' => 'Notes saved in Synaplan. Audio was not kept.',
        ], Response::HTTP_CREATED);
    }

    private function ready(?User $user): User|JsonResponse
    {
        if (!$user) {
            return new JsonResponse(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        if ($this->module->isConfigured()) {
            return $user;
        }

        return new JsonResponse([
            'error' => 'feature_not_configured',
            'module' => OpendeskSttModule::ID,
            'docs' => $this->module->docsAnchor(),
        ], Response::HTTP_NOT_FOUND);
    }

    private function badRequest(string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => 'invalid_request',
            'message' => $message,
        ], Response::HTTP_BAD_REQUEST);
    }
}
