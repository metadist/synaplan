<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Chat\Stream\CancelledTurns;
use App\Service\Chat\Stream\ChatStreamOrchestrator;
use App\Service\Chat\Stream\StreamRequestRejected;
use App\Service\Chat\Stream\StreamRequestResolver;
use App\Service\Realtime\SseWriter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The chat stream: one turn per request, narrated as server-sent events.
 *
 * {@see StreamRequestResolver} turns the request into a turn (or a rejection),
 * {@see ChatStreamOrchestrator} runs it inside the streamed response, and
 * {@see CancelledTurns} handles Stop. The SSE event names are an external
 * contract — web chat, widget, desktop and mobile apps read them.
 */
#[Route('/api/v1/messages', name: 'api_messages_')]
#[OA\Tag(name: 'Messages')]
class StreamController extends AbstractController
{
    /**
     * When true, the SSE writer skips ob_flush/flush so a test can capture
     * the frames with output buffering (#2282).
     */
    private bool $sseCaptureMode = false;

    public function __construct(
        private StreamRequestResolver $streamRequests,
        private ChatStreamOrchestrator $streams,
        private CancelledTurns $cancelledTurns,
    ) {
    }

    #[Route('/stream', name: 'stream', methods: ['GET', 'POST'])]
    #[OA\Get(
        path: '/api/v1/messages/stream',
        summary: 'Stream AI chat response',
        description: 'Stream AI chat messages with Server-Sent Events (SSE). Supports reasoning models, web search, and file attachments. Status events include memories_loaded and docs_loaded (documentation citations for the synaplan topic). NOTE: the message rides in the URL, so long texts can exceed proxy request-line limits (HTTP 431/414) — prefer the POST variant for anything beyond a few KB.',
        security: [['Bearer' => []]],
        tags: ['Messages']
    )]
    #[OA\Post(
        path: '/api/v1/messages/stream',
        summary: 'Stream AI chat response (POST body)',
        description: 'Same SSE stream as the GET variant, but all parameters travel in a JSON body so arbitrarily long messages never hit URL length limits. Accepts every parameter the GET variant documents (message, chatId, trackId, reasoning, reasoningEffort, webSearch, modelId, fileIds, voiceReply, isAgain, promptTopic, promptId, agentId, draft, ragGroupKey, quotedText, quotedMessageId, continueMessageId, disableMemories, guestSession, incognito, history) as JSON properties; body values override query parameters. This is the default transport used by the web chat.',
        security: [['Bearer' => []]],
        tags: ['Messages'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['message'],
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Summarize the following text: …'),
                    new OA\Property(property: 'chatId', type: 'string', example: '123', description: 'Required unless incognito is set.'),
                    new OA\Property(property: 'trackId', type: 'string', example: '1234567890'),
                    new OA\Property(property: 'reasoning', type: 'string', enum: ['0', '1'], example: '0'),
                    new OA\Property(property: 'reasoningEffort', type: 'string', enum: ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'], example: 'medium', description: 'Chosen reasoning level when the model publishes one. Omitted means the model default. none and minimal turn reasoning off; low is a real level.'),
                    new OA\Property(property: 'webSearch', type: 'string', enum: ['0', '1'], example: '0'),
                    new OA\Property(property: 'language', type: 'string', enum: ['de', 'en', 'es', 'fr', 'tr'], example: 'de', description: 'Active UI locale from the client. Seeds BLANG on the inbound message so the sorter / Brave search / reply language prefer the interface language when the message language cannot be detected.'),
                    new OA\Property(property: 'modelId', type: 'string', example: '53'),
                    new OA\Property(property: 'fileIds', type: 'string', example: '1,2,3'),
                    new OA\Property(property: 'guestSession', type: 'string', example: 'gs_abc123'),
                    new OA\Property(property: 'incognito', type: 'boolean', example: false, description: 'Incognito mode: the turn is processed entirely in-memory — no messages, metadata, search results or memory extractions are persisted. Memories/RAG/feedback are still READ. Files created during the turn are marked ephemeral and deleted after the session. Requires an authenticated user (not available for widget/guest).'),
                    new OA\Property(
                        property: 'history',
                        type: 'array',
                        description: 'Incognito only: the in-memory conversation history (oldest first) since the server has no stored chat to load context from. Capped server-side at 30 entries / ~15k chars.',
                        items: new OA\Items(
                            required: ['role', 'content'],
                            properties: [
                                new OA\Property(property: 'role', type: 'string', enum: ['user', 'assistant'], example: 'user'),
                                new OA\Property(property: 'content', type: 'string', example: 'What is the weather today?'),
                            ],
                            type: 'object'
                        )
                    ),
                ]
            )
        )
    )]
    #[OA\Parameter(
        name: 'message',
        in: 'query',
        required: true,
        description: 'The message text to send to AI',
        schema: new OA\Schema(type: 'string', example: 'What is the weather today?')
    )]
    #[OA\Parameter(
        name: 'chatId',
        in: 'query',
        required: true,
        description: 'The chat ID to send message to',
        schema: new OA\Schema(type: 'integer', example: 123)
    )]
    #[OA\Parameter(
        name: 'trackId',
        in: 'query',
        required: false,
        description: 'Optional tracking ID for message',
        schema: new OA\Schema(type: 'integer', example: 1234567890)
    )]
    #[OA\Parameter(
        name: 'reasoning',
        in: 'query',
        required: false,
        description: 'Enable reasoning/thinking mode (1 or 0)',
        schema: new OA\Schema(type: 'string', enum: ['0', '1'], example: '1')
    )]
    #[OA\Parameter(
        name: 'reasoningEffort',
        in: 'query',
        required: false,
        description: 'Chosen reasoning level when the model publishes one (none, minimal, low, medium, high, xhigh, max). Omitted means the model default.',
        schema: new OA\Schema(type: 'string', enum: ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'], example: 'medium')
    )]
    #[OA\Parameter(
        name: 'webSearch',
        in: 'query',
        required: false,
        description: 'Enable web search (1 or 0)',
        schema: new OA\Schema(type: 'string', enum: ['0', '1'], example: '0')
    )]
    #[OA\Parameter(
        name: 'language',
        in: 'query',
        required: false,
        description: 'Active UI locale from the client (de/en/es/tr). Seeds inbound BLANG for sorting, Brave search_lang/ui_lang, and the reply language directive.',
        schema: new OA\Schema(type: 'string', enum: ['de', 'en', 'es', 'fr', 'tr'], example: 'de')
    )]
    #[OA\Parameter(
        name: 'modelId',
        in: 'query',
        required: false,
        description: 'Specific model ID to use (optional)',
        schema: new OA\Schema(type: 'integer', example: 53)
    )]
    #[OA\Parameter(
        name: 'fileIds',
        in: 'query',
        required: false,
        description: 'Comma-separated list of file IDs to attach',
        schema: new OA\Schema(type: 'string', example: '1,2,3')
    )]
    #[OA\Parameter(
        name: 'voiceReply',
        in: 'query',
        required: false,
        description: 'Generate audio (MP3) voice reply in addition to text (1 or 0)',
        schema: new OA\Schema(type: 'string', enum: ['0', '1'], example: '0')
    )]
    #[OA\Parameter(
        name: 'isAgain',
        in: 'query',
        required: false,
        description: 'Whether this is an "Again" request (retry with specific model, skip classification). Required when modelId is set to distinguish model override from retry.',
        schema: new OA\Schema(type: 'string', enum: ['0', '1'], example: '0')
    )]
    #[OA\Parameter(
        name: 'promptTopic',
        in: 'query',
        required: false,
        description: 'Topic of a custom prompt to use (e.g., "customersupport", "legal-review"). Overrides auto-classification. Use GET /api/v1/prompts to list available topics.',
        schema: new OA\Schema(type: 'string', example: 'customersupport')
    )]
    #[OA\Parameter(
        name: 'promptId',
        in: 'query',
        required: false,
        description: 'ID of a specific prompt to use. Takes precedence over promptTopic. The prompt must belong to the current user or be a system prompt.',
        schema: new OA\Schema(type: 'integer', example: 42)
    )]
    #[OA\Parameter(
        name: 'agentId',
        in: 'query',
        required: false,
        description: 'ID of an assistant to pin this chat to. When AGENTS.ENABLED is on, classification skips the sorter and the reply uses that assistant. Ignored when the flag is off. The prompt must belong to the current user.',
        schema: new OA\Schema(type: 'integer', example: 7)
    )]
    #[OA\Parameter(
        name: 'draft',
        in: 'query',
        required: false,
        description: 'When 1, pin the owner\'s unpublished draft (test panel). Non-owners receive 403. Ignored when agentId is absent.',
        schema: new OA\Schema(type: 'string', enum: ['0', '1'], example: '0')
    )]
    #[OA\Parameter(
        name: 'ragGroupKey',
        in: 'query',
        required: false,
        description: 'Knowledge-base folder (file group key) to scope this message\'s RAG retrieval to. Use GET /api/v1/files/groups to list available folders.',
        schema: new OA\Schema(type: 'string', example: 'project:helios')
    )]
    #[OA\Parameter(
        name: 'quotedText',
        in: 'query',
        required: false,
        description: 'Text the user selected from an earlier message to use as the primary reference point for this request ("Mention in chat"). Injected into the AI system prompt as a quoted-reference context block.',
        schema: new OA\Schema(type: 'string', example: 'The deployment runs on FrankenPHP.')
    )]
    #[OA\Parameter(
        name: 'quotedMessageId',
        in: 'query',
        required: false,
        description: 'Optional backend id of the message the quoted text was taken from. Must belong to the same chat and user.',
        schema: new OA\Schema(type: 'integer', example: 456)
    )]
    #[OA\Response(
        response: 200,
        description: 'SSE stream of AI response chunks',
        content: new OA\MediaType(
            mediaType: 'text/event-stream',
            schema: new OA\Schema(
                type: 'string',
                example: "event: data\ndata: {\"chunk\":\"Hello\"}\n\nevent: complete\ndata: {\"status\":\"complete\",\"messageId\":123}\n\n"
            )
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Not authenticated'
    )]
    public function streamMessage(
        Request $request,
        #[CurrentUser] ?User $user,
    ): Response {
        try {
            $turn = $this->streamRequests->resolve($request, $user);
        } catch (StreamRequestRejected $rejected) {
            return $this->rejectedStream($rejected);
        }

        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');
        $response->setCallback(function () use ($turn): void {
            $this->streams->run($turn, new SseWriter($this->sseCaptureMode));
        });

        return $response;
    }

    /**
     * The answer for a request that ends before a turn starts: its JSON
     * error, or — for a guest who used the trial — one SSE event.
     */
    private function rejectedStream(StreamRequestRejected $rejected): Response
    {
        if (null === $rejected->sseEvent) {
            return $this->json($rejected->payload, $rejected->httpStatus);
        }

        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->setCallback(function () use ($rejected): void {
            (new SseWriter($this->sseCaptureMode))->send((string) $rejected->sseEvent, $rejected->payload);
        });

        return $response;
    }

    /**
     * Save cancelled message - persist cancelled streaming message to database.
     */
    #[Route('/save-cancelled', name: 'save_cancelled', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/messages/save-cancelled',
        summary: 'Save cancelled streaming message',
        description: 'Save a cancelled streaming message to the database',
        security: [['Bearer' => []]],
        tags: ['Messages']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'trackId', type: 'integer', example: 1234567890),
                new OA\Property(property: 'chatId', type: 'integer', example: 123),
                new OA\Property(property: 'content', type: 'string', example: 'Partial response\n\n_Cancelled by user_'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Message saved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'messageId', type: 'integer', example: 456),
            ]
        )
    )]
    public function saveCancelled(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            return $this->json($this->cancelledTurns->saveReply($user, json_decode($request->getContent(), true)));
        } catch (StreamRequestRejected $rejected) {
            return $this->json($rejected->payload, $rejected->httpStatus);
        }
    }

    /**
     * Stop streaming endpoint - allows frontend to explicitly stop streaming.
     */
    #[Route('/stop-stream', name: 'stop_stream', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/messages/stop-stream',
        summary: 'Stop streaming AI response',
        description: 'Explicitly stop an ongoing streaming response',
        security: [['Bearer' => []]],
        tags: ['Messages']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'trackId', type: 'integer', example: 1234567890),
                new OA\Property(property: 'chatId', type: 'integer', example: 42, description: 'Owner chat id. Required only for an incognito turn, which has no persisted message.'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Stream stop acknowledged',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Stream stop requested'),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'The track belongs to another user')]
    public function stopStream(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->cancelledTurns->stop($user, json_decode($request->getContent(), true));
        } catch (StreamRequestRejected $rejected) {
            return $this->json($rejected->payload, $rejected->httpStatus);
        }

        return $this->json([
            'success' => true,
            'message' => 'Stream stop requested',
        ]);
    }

    /**
     * Cancel a single in-flight multitask media node (per-card Stop button)
     * without stopping the rest of the turn.
     */
    #[Route('/cancel-node', name: 'cancel_node', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/messages/cancel-node',
        summary: 'Cancel a single multitask media node',
        description: 'Flags one running media-generation step (by track id + node id) for cancellation. The streaming worker aborts that node and tells the provider to cancel, while sibling steps keep running.',
        security: [['Bearer' => []]],
        tags: ['Messages']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['trackId', 'nodeId'],
            properties: [
                new OA\Property(property: 'trackId', type: 'string', example: '1234567890'),
                new OA\Property(property: 'nodeId', type: 'string', example: 'n2'),
                new OA\Property(property: 'chatId', type: 'integer', example: 42, description: 'Owner chat id. Required only for an incognito turn, which has no persisted message.'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Cancellation requested',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'The track belongs to another user')]
    public function cancelNode(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->cancelledTurns->cancelNode($user, json_decode($request->getContent(), true));
        } catch (StreamRequestRejected $rejected) {
            return $this->json($rejected->payload, $rejected->httpStatus);
        }

        return $this->json(['success' => true]);
    }
}
