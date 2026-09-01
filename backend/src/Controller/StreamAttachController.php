<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Chat\Run\ChatRun;
use App\Service\Chat\Run\ChatRunService;
use App\Service\GuestSessionService;
use App\Service\WidgetService;
use App\Service\WidgetSessionService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Re-attach to a chat turn that is still generating.
 *
 * A turn survives the client disconnect it was started from (see
 * {@see StreamController}, issues #1142/#1223/#1225) and mirrors every SSE event
 * into a replayable Redis log. This endpoint opens a second SSE connection on
 * that log: it replays what the client missed and then tails the live tail, so
 * reloading the page or navigating away and back continues the answer where it
 * stopped instead of leaving the user with a bare prompt.
 *
 * Deliberately a separate controller: {@see StreamController} is the write path
 * and already far past a reviewable size; nothing here touches the AI pipeline.
 */
#[Route('/api/v1/messages', name: 'api_messages_')]
#[OA\Tag(name: 'Messages')]
final class StreamAttachController extends AbstractController
{
    /**
     * Tail poll interval. Fast enough that replayed text feels live, slow
     * enough that an idle attach costs ~10 Redis reads per second.
     */
    private const POLL_INTERVAL_MICROSECONDS = 100000; // 100 ms

    /** Upper bound for one attach connection, matching the run's own retention. */
    private const MAX_ATTACH_SECONDS = 1800;

    /**
     * Idle time after which the stream emits an SSE comment.
     *
     * Two reasons, both load-bearing:
     *  - PHP only notices a gone client when a write fails, so a stream that
     *    sends nothing while it waits would never see `connection_aborted()` and
     *    would keep a worker busy polling Redis for the full MAX_ATTACH_SECONDS
     *    after the browser was closed.
     *  - Proxies drop a connection that has been silent for too long, which a
     *    turn between two slow phases can easily be.
     */
    private const IDLE_KEEPALIVE_SECONDS = 15;

    public function __construct(
        private readonly ChatRunService $chatRunService,
        private readonly WidgetService $widgetService,
        private readonly WidgetSessionService $widgetSessionService,
        private readonly GuestSessionService $guestSessionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/stream/attach', name: 'stream_attach', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/messages/stream/attach',
        summary: 'Re-attach to a running chat turn',
        description: <<<'DESC'
            Replays the Server-Sent Events of a turn that is still generating and then follows it live,
            so a client that reloaded or navigated away can continue rendering the answer.

            Events use the same envelope as `/api/v1/messages/stream` (`data: {"status": ...}`) and each
            carries the SSE `id:` field with its sequence number, so a dropped attach can resume via `from`.

            The stream ends without a terminal event when the run was cancelled, expired, or its worker
            died. Clients treat that as a recoverable drop and fall back to reloading the chat history.

            Authentication mirrors the streaming endpoint: session/Bearer for app users, the
            `X-Widget-Id` + `X-Widget-Session` headers for widgets, and `guestSession` for guest chats.
            DESC,
        tags: ['Messages']
    )]
    #[OA\Parameter(
        name: 'runId',
        in: 'query',
        required: true,
        description: 'Run id, delivered as the `run_started` event of the original stream or as `activeRun.runId` of the chat history response.',
        schema: new OA\Schema(type: 'string', format: 'uuid', example: '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')
    )]
    #[OA\Parameter(
        name: 'from',
        in: 'query',
        required: false,
        description: 'Replay events after this sequence number. Defaults to 0 (replay everything the run produced).',
        schema: new OA\Schema(type: 'integer', default: 0, example: 0)
    )]
    #[OA\Parameter(
        name: 'guestSession',
        in: 'query',
        required: false,
        description: 'Guest session id, for turns started from the guest chat.',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(
        response: 200,
        description: 'SSE stream replaying and then following the run',
        content: new OA\MediaType(
            mediaType: 'text/event-stream',
            schema: new OA\Schema(type: 'string', example: "id: 12\ndata: {\"status\":\"data\",\"chunk\":\"Hello\"}\n\n")
        )
    )]
    #[OA\Response(response: 400, description: 'Missing runId')]
    #[OA\Response(response: 401, description: 'No usable app, widget or guest identity on the request')]
    #[OA\Response(response: 404, description: 'Run unknown, expired, or owned by somebody else')]
    public function attach(Request $request, #[CurrentUser] ?User $user): Response
    {
        $runId = trim($request->query->getString('runId'));
        if ('' === $runId) {
            return $this->json(['error' => 'runId is required'], Response::HTTP_BAD_REQUEST);
        }

        $ownerKey = $this->resolveOwnerKey($request, $user);
        if (null === $ownerKey) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $run = $this->chatRunService->authorize($runId, $ownerKey);
        if (null === $run) {
            // Same answer for "unknown" and "not yours" so the endpoint cannot
            // be used to probe which run ids exist.
            return $this->json(['error' => 'Run not found'], Response::HTTP_NOT_FOUND);
        }

        $from = max(0, $request->query->getInt('from'));

        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        $response->setCallback(function () use ($run, $from): void {
            $this->pump($run, $from);
        });

        return $response;
    }

    /**
     * Replay from `$fromSeq` and then follow the run until it ends.
     *
     * Unlike the generating request this one does NOT set
     * `ignore_user_abort(true)`: an attach connection is a pure reader, so once
     * its client is gone there is nothing worth keeping alive.
     */
    private function pump(ChatRun $run, int $fromSeq): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_implicit_flush(true);
        set_time_limit(0);
        ignore_user_abort(false);

        $runId = $run->getRunId();
        $cursor = $fromSeq;
        $deadline = time() + self::MAX_ATTACH_SECONDS;
        $lastWriteAt = time();

        while (true) {
            $events = $this->chatRunService->readEvents($runId, $cursor);

            foreach ($events as $event) {
                $cursor = $event['seq'];
                $this->emit($cursor, $event['payload']);
                $lastWriteAt = time();

                if (connection_aborted()) {
                    return;
                }

                // `complete` / `error` end the turn on the wire, so the client
                // has everything it needs — no reason to keep polling.
                if ($this->isTerminalPayload($event['payload'])) {
                    return;
                }
            }

            if ([] !== $events) {
                // Events are still flowing: the writer is demonstrably alive,
                // so skip the snapshot read and go straight for the next batch.
                continue;
            }

            $current = $this->chatRunService->find($runId);

            // Everything below closes the stream WITHOUT a terminal event. The
            // client already treats that as a recoverable drop (it is exactly
            // what a mid-turn connection loss looks like) and recovers by
            // reloading the chat history — see isRecoverableStreamError() and
            // historyStore.recoverInterruptedTurn() on the frontend.
            if (null === $current) {
                $this->logger->info('StreamAttachController: run snapshot vanished while attached', ['run_id' => $runId]);

                return;
            }

            if ($current->isTruncated()) {
                $this->logger->info('StreamAttachController: run log was truncated, releasing client', ['run_id' => $runId]);

                return;
            }

            if ($current->isTerminal()) {
                // Terminal without a terminal event means the turn was
                // cancelled (StreamController ends those silently).
                return;
            }

            if ($this->chatRunService->isStale($current)) {
                $this->logger->warning('StreamAttachController: run heartbeat went stale, releasing client', [
                    'run_id' => $runId,
                    'last_heartbeat_age' => time() - $current->getUpdated(),
                ]);

                return;
            }

            if (time() >= $deadline || connection_aborted()) {
                return;
            }

            // The run is alive but quiet. Poke the socket so a client that left
            // in the meantime is actually detected on the next check instead of
            // holding this worker until the deadline.
            if ((time() - $lastWriteAt) >= self::IDLE_KEEPALIVE_SECONDS) {
                $this->emitKeepalive();
                $lastWriteAt = time();

                if (connection_aborted()) {
                    return;
                }
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
    }

    private function emit(int $seq, string $payload): void
    {
        echo 'id: '.$seq."\n";
        echo 'data: '.$payload."\n\n";

        $this->flushOutput();
    }

    /**
     * SSE comment frame. Carries no `data:` line, so both client parsers skip it
     * (chatApi's `readSseBody` filters on `data:`, the widget's
     * `consumeWidgetStream` bails when a frame has no data lines).
     */
    private function emitKeepalive(): void
    {
        echo ": keepalive\n\n";

        $this->flushOutput();
    }

    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private function isTerminalPayload(string $payload): bool
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return false;
        }

        return \in_array($decoded['status'] ?? null, [ChatRun::STATUS_COMPLETE, ChatRun::STATUS_ERROR], true);
    }

    /**
     * Rebuild the caller's owner scope, mirroring how the streaming endpoint
     * authenticates: app user first, then widget headers, then guest session.
     */
    private function resolveOwnerKey(Request $request, ?User $user): ?string
    {
        if (null !== $user) {
            return ChatRunService::ownerKeyForUser((int) $user->getId());
        }

        $widgetId = $request->headers->get('X-Widget-Id');
        $widgetSessionId = $request->headers->get('X-Widget-Session');
        if (null !== $widgetId && null !== $widgetSessionId && '' !== $widgetId && '' !== $widgetSessionId) {
            $widget = $this->widgetService->getWidgetById($widgetId);
            if (null === $widget) {
                return null;
            }

            // Look up only — a re-attach must never bring a session into
            // existence, otherwise the endpoint becomes a session factory.
            $session = $this->widgetSessionService->getSession($widgetId, $widgetSessionId);

            return null === $session ? null : ChatRunService::ownerKeyForWidget($session->getSessionId());
        }

        $guestSessionId = trim($request->query->getString('guestSession'));
        if ('' !== $guestSessionId) {
            $session = $this->guestSessionService->getSession($guestSessionId);
            if (null === $session || $session->isExpired()) {
                return null;
            }

            return ChatRunService::ownerKeyForGuest($session->getSessionId());
        }

        return null;
    }
}
