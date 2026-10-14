<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Service\Chat\Run\ChatRun;
use App\Service\Chat\Run\ChatRunRecorder;
use App\Service\Chat\Run\ChatRunService;
use App\Service\Exception\StreamCancelledException;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Message\MessageProcessor;
use App\Service\Realtime\SseWriter;
use Psr\Log\LoggerInterface;

/**
 * Runs one streamed chat turn and narrates it as server-sent events.
 *
 *  1. A person over a limit gets the report, and nothing is stored.
 *  2. The resumable run opens (not for incognito, which keeps nothing), so
 *     a client that reloads can re-attach.
 *  3. {@see StreamTurnPreparer} loads the chat and stores the incoming
 *     message with its files.
 *  4. {@see StreamModelRouting}: a model that cannot stream takes
 *     {@see NonStreamingTurn}.
 *  5. The message pipeline runs, relayed chunk by chunk
 *     ({@see StreamChunkRelay}, {@see StreamStatusRelay}).
 *  6. A cancellation ends quietly; a failure is reported
 *     ({@see StreamFailureReporter}).
 *  7. {@see StreamReplyPersister} stores the reply and
 *     {@see StreamCompletion} sends `complete`.
 *
 * Every path closes the run. An early return, or a Throwable that is not an
 * Exception, closes it with its recorded outcome — never left "running"
 * for a client waiting on a heartbeat that will not tick again.
 */
final readonly class ChatStreamOrchestrator
{
    public function __construct(
        private MessageProcessor $messageProcessor,
        private ChatRunService $chatRunService,
        private StreamTurnPreparer $preparer,
        private StreamModelRouting $routing,
        private NonStreamingTurn $nonStreaming,
        private StreamRelayFactory $relays,
        private StreamReplyPersister $replies,
        private StreamCompletion $completion,
        private StreamFailureReporter $failures,
        private LoggerInterface $logger,
    ) {
    }

    public function run(StreamTurnRequest $turn, SseWriter $sse): void
    {
        $sse->open();
        // Created inside the stream, so it measures the turn and not the
        // connection setup; `perf` reports it before `complete`.
        $perfTimer = new PerfTimer();

        if (null !== $turn->rateLimitError) {
            $sse->send('message', $turn->rateLimitError);

            return;
        }

        $state = new StreamTurnState($turn, $perfTimer, $this->routing->intendedChat($turn->options->modelIdAsInt(), $turn->caller->user));
        $run = $this->openRun($sse, $turn);

        try {
            $this->stream($sse, $turn, $state, $run);
        } catch (StreamCancelledException) {
            // An explicit Stop: the client stores the partial answer through
            // /save-cancelled, so the turn ends silently.
            $this->logCancelled($turn);
            $run?->finish(ChatRun::STATUS_CANCELLED);
        } catch (\Exception $e) {
            $run?->finish(ChatRun::STATUS_ERROR, $this->failures->reportException($sse, $turn, $state, $e));
        } finally {
            $run?->finishFromRecordedOutcome();
            $sse->attachRun(null);
        }
    }

    private function stream(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state, ?ChatRunRecorder $run): void
    {
        if (!$this->preparer->prepare($sse, $turn, $state)) {
            return;
        }

        $processingOptions = $this->preparer->processingOptions($turn, $state);
        $modelId = $this->routing->expectedModelId($turn, $processingOptions, $state->intendedChat);
        if (!$this->routing->supportsStreaming($modelId)) {
            $this->nonStreaming->run($sse, $turn, $state, $processingOptions, $modelId);

            return;
        }

        $relay = $this->relays->chunks($sse, $turn);
        $result = $this->messageProcessor->processStream(
            $state->incoming(),
            $relay->onChunk(...),
            $this->relays->statuses($sse),
            $processingOptions,
        );
        $relay->finish();

        // A cancel reported as a result: the client already stored the
        // cancelled turn, and an error row on top showed two cards.
        if ($this->failures->isCancellation($result)) {
            $this->logCancelled($turn);

            return;
        }

        if (!$result['success']) {
            $this->failures->reportFailedResult($sse, $turn, $state, $result);

            return;
        }

        $reply = $this->replies->persist($sse, $turn, $state, $result, $relay);
        $this->completion->complete($sse, $turn, $state, $result, $reply, $relay, $run);
    }

    /**
     * Every event of the turn is mirrored into a replayable log. Incognito
     * promises that nothing of the turn is kept server-side, so it streams
     * without resume.
     */
    private function openRun(SseWriter $sse, StreamTurnRequest $turn): ?ChatRunRecorder
    {
        if ($turn->incognito) {
            return null;
        }

        $run = $this->chatRunService->begin(
            $turn->caller->runOwnerKey(),
            is_numeric($turn->chatId) ? (int) $turn->chatId : null,
            (string) $turn->trackId,
        );
        $sse->attachRun($run);
        if (null !== $run) {
            $sse->send('run_started', ['runId' => $run->getRunId()]);
        }

        return $run;
    }

    private function logCancelled(StreamTurnRequest $turn): void
    {
        $this->logger->info('Stream stopped - cancelled by user', [
            'user_id' => $turn->caller->user->getId(),
            'track_id' => (string) $turn->trackId,
        ]);
    }
}
