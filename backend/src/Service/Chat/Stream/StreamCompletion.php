<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Service\Chat\GuestSessionService;
use App\Service\Chat\ProviderTranscriptCapture;
use App\Service\Chat\Run\ChatRun;
use App\Service\Chat\Run\ChatRunRecorder;
use App\Service\Realtime\SseWriter;
use App\Service\Widget\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Ends a successful streamed turn: the follow-up jobs, the usage, the
 * spoken reply, the visitor counters, and the `complete` event.
 *
 * `complete` mirrors the shape of the history endpoint — model badges, web
 * results, knowledge sources, memory and feedback ids — so the reply looks
 * the same live and after a reload. Everything the client needs before it
 * closes the stream (audio, `guest_remaining`, `perf`) goes out first, and
 * the resumable run closes only after `complete` was recorded.
 */
final readonly class StreamCompletion
{
    /** Message meta: seconds from the first reasoning chunk to the first answer token. */
    public const THINKING_SECONDS = 'thinking_seconds';

    /**
     * A continuation appends to the message that already has a duration.
     * Writing the continuation's own seconds would replace that one, and
     * reload would show it on the first thinking block.
     */
    public static function measuredDuration(?int $seconds, bool $continuation): ?int
    {
        return $continuation ? null : $seconds;
    }

    public function __construct(
        private StreamTurnFinisher $finisher,
        private StreamUsageRecorder $usage,
        private VoiceReplyStep $voiceReply,
        private StreamMessageMeta $meta,
        private WidgetSessionService $widgetSessionService,
        private GuestSessionService $guestSessionService,
        private LoggerInterface $logger,
        private ProviderTranscriptCapture $transcripts,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $result the successful processing result
     */
    public function complete(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state, array $result, StreamReply $reply, StreamChunkRelay $relay, ?ChatRunRecorder $run): void
    {
        $classification = $result['classification'];
        $metadata = $result['response']['metadata'] ?? [];

        $this->finisher->afterReply($turn, $state->chat, $metadata, $reply->text);
        $chatTitle = $this->finisher->titleChat($turn, $state->chat);
        $usage = $this->usage->recordStreamedReply($turn, $state, $reply->outgoing, $metadata, $classification, $reply->text, $reply->generatedFile);

        // A continuation appends to the original message. Its own reasoning
        // must not replace the duration already stored for the first block.
        $thinkingSeconds = self::measuredDuration($relay->reasoningSeconds(), null !== $state->continued);
        if (null !== $thinkingSeconds) {
            $reply->outgoing->setMeta(self::THINKING_SECONDS, (string) $thinkingSeconds);
            $this->entityManager->flush();
        }

        $event = $this->completeEvent($turn, $classification, $metadata, $reply, $usage, $chatTitle);
        if (null !== $thinkingSeconds) {
            $event->set('thinking_seconds', $thinkingSeconds);
        }
        if ('length' === $relay->finishReason) {
            $event->set('truncated', true);
        }
        $this->addHandlerContext($event, $metadata, $reply);

        if ($this->voiceReply->applies($turn, $classification)) {
            $this->voiceReply->run($sse, $event, $reply->outgoing, $turn->caller->user, $relay->responseText, $classification['language'] ?? 'en', $turn->incognito);
        }

        $this->countVisit($sse, $turn, $state);

        // Where the wall-clock time went; the client shows it in debug mode.
        $sse->send('perf', $state->perfTimer->toArray());

        $totals = $this->usage->liveTotals($turn);
        if (null !== $totals) {
            $event->set('usage_totals', $totals);
        }

        $transcript = $this->transcripts->payload();
        $this->transcripts->reset();
        if (null !== $transcript && null === $turn->caller->widgetSession && null === $turn->caller->guestSession) {
            $event->set('providerMessages', $transcript);
        }

        $sse->send('complete', $event->data());
        $run?->finish(ChatRun::STATUS_COMPLETE, $reply->outgoing->getId());

        usleep(100000);

        $this->logger->info('Streamed message processed', [
            'user_id' => $turn->caller->user->getId(),
            'message_id' => $reply->outgoing->getId(),
            'topic' => $classification['topic'],
        ]);
    }

    /**
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $metadata
     */
    private function completeEvent(StreamTurnRequest $turn, array $classification, array $metadata, StreamReply $reply, StreamUsage $usage, ?string $chatTitle): StreamCompleteEvent
    {
        $searchResults = self::searchResultsForSse($reply->searchResults);
        if (null !== $searchResults) {
            $this->logger->info('StreamController: Including search results', [
                'results_count' => count($searchResults),
                'query' => $reply->searchResults['query'] ?? '',
            ]);
        }

        $outgoing = $reply->outgoing;
        $event = new StreamCompleteEvent([
            'messageId' => $outgoing->getId(),
            'trackId' => $turn->trackId,
            'provider' => $metadata['provider'] ?? 'test',
            'model' => $metadata['model'] ?? 'unknown',
            // int|null, like the history endpoint and the nested aiModels (PR #833).
            'model_id' => $this->meta->normalizeModelId($metadata['model_id'] ?? $turn->options->modelId ?? null, 'streaming_complete'),
            'topic' => $classification['topic'],
            // The history endpoint ships both too; they keep the badge label
            // and the "Again" selection right live (#624).
            'originalTopic' => $outgoing->getMeta('original_topic'),
            'originalMediaType' => $outgoing->getMeta('original_media_type'),
            'language' => $classification['language'],
            'searchResults' => $searchResults,
            'ragSources' => $reply->ragSources,
            'aiModels' => $this->meta->buildAiModelsPayload($outgoing),
        ]);

        // Only on the turn that named the chat, so the sidebar needs no refetch.
        if (null !== $chatTitle) {
            $event->set('chatTitle', $chatTitle);
        }
        if (null !== $usage->chat) {
            $event->set('usage', $usage->chat);
        }
        $event->withUsageExtra($usage->extra);

        return $event;
    }

    /**
     * The handler context the client loads from its stores — ids only — plus
     * the media job, the document edit and the generated file.
     *
     * @param array<string, mixed> $metadata
     */
    private function addHandlerContext(StreamCompleteEvent $event, array $metadata, StreamReply $reply): void
    {
        if (!empty($metadata['media_job']) && is_array($metadata['media_job'])) {
            $event->set('mediaJob', $metadata['media_job']);
        }

        if (isset($metadata['memories']) && is_array($metadata['memories'])) {
            $event->set('memoryIds', array_map(fn ($m) => $m['id'], $metadata['memories']));
            $this->logger->info('StreamController: Including memory IDs in complete event', [
                'count' => count($metadata['memories']),
            ]);
        }

        if (isset($metadata['docs']) && is_array($metadata['docs']) && [] !== $metadata['docs']) {
            $event->set('docs', $metadata['docs']);
        }

        if (isset($metadata['feedbacks']) && is_array($metadata['feedbacks'])) {
            $feedbackIds = array_filter(
                array_map(fn ($f) => $f['id'] ?? null, $metadata['feedbacks']),
                fn ($id) => null !== $id
            );
            $event->set('feedbackIds', $feedbackIds);
            $this->logger->debug('StreamController: Including feedback IDs in complete event', [
                'count' => count($feedbackIds),
            ]);
        }

        if (isset($metadata['documentChanges']) && is_array($metadata['documentChanges'])) {
            $event->set('documentChanges', $metadata['documentChanges']);
        }
        if (isset($metadata['documentVersion'])) {
            $event->set('documentVersion', (int) $metadata['documentVersion']);
        }
        if (!empty($metadata['documentFidelityLossy'])) {
            $event->set('documentFidelityLossy', true);
        }

        $generatedFile = $reply->generatedFile;
        if ($generatedFile) {
            $event->set('generatedFile', [
                'id' => $generatedFile->getId(),
                'filename' => $generatedFile->getFileName(),
                'path' => $generatedFile->getFilePath(),
                'size' => $generatedFile->getFileSize(),
                'type' => $generatedFile->getFileType(),
                'mime' => $generatedFile->getFileMime(),
            ]);

            $this->logger->info('StreamController: Including generated file in complete event', [
                'file_id' => $generatedFile->getId(),
                'filename' => $generatedFile->getFileName(),
            ]);
        }
    }

    /**
     * A widget visit counts against the session limit; a guest gets the
     * remaining trial messages before `complete` closes the stream.
     */
    private function countVisit(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state): void
    {
        $widgetSession = $turn->caller->widgetSession;
        if (null !== $widgetSession) {
            $this->widgetSessionService->incrementMessageCount($widgetSession);
            $this->logger->info('Widget session message count incremented', [
                'session_id' => $widgetSession->getSessionId(),
                'message_count' => $widgetSession->getMessageCount(),
            ]);
        }

        $guestSession = $turn->caller->guestSession;
        if (null !== $guestSession) {
            $this->guestSessionService->attachChat($guestSession, (int) $state->chatId);
            $this->guestSessionService->incrementCount($guestSession);
            $sse->send('guest_remaining', [
                'remaining' => $this->guestSessionService->getRemainingMessages($guestSession),
                'maxMessages' => $guestSession->getMaxMessages(),
                'limitReached' => $this->guestSessionService->isLimitReached($guestSession),
            ]);
        }
    }

    /**
     * @param array<string, mixed>|null $rawSearchResults
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function searchResultsForSse(?array $rawSearchResults): ?array
    {
        if (empty($rawSearchResults['results']) || !is_array($rawSearchResults['results'])) {
            return null;
        }

        return array_map(static function (array $result): array {
            return [
                'title' => $result['title'] ?? '',
                'url' => $result['url'] ?? '',
                'description' => $result['description'] ?? '',
                'published' => $result['age'] ?? null,
                'source' => $result['profile']['name'] ?? null,
                'thumbnail' => $result['thumbnail'] ?? null,
                'fetched' => (bool) ($result['fetched'] ?? false),
                'final_url' => is_string($result['final_url'] ?? null) ? $result['final_url'] : null,
            ];
        }, $rawSearchResults['results']);
    }
}
