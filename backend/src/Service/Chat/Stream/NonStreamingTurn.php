<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\Message;
use App\Service\Chat\ProviderTranscriptCapture;
use App\Service\Media\MediaJobRebinder;
use App\Service\Message\MessageProcessor;
use App\Service\Realtime\SseWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * A streamed turn on a model that cannot stream (e.g. o1).
 *
 * The answer arrives in one piece and goes out as one `data` chunk — after
 * the reasoning, when the model reports it — over the same event contract
 * as a streamed answer, so the client needs no second code path.
 */
final readonly class NonStreamingTurn
{
    public function __construct(
        private MessageProcessor $messageProcessor,
        private EntityManagerInterface $em,
        private StreamRelayFactory $relays,
        private StreamFailureReporter $failures,
        private StreamMessageMeta $meta,
        private MediaJobRebinder $mediaJobs,
        private StreamTurnFinisher $finisher,
        private StreamUsageRecorder $usage,
        private LoggerInterface $logger,
        private ProviderTranscriptCapture $transcripts,
    ) {
    }

    /**
     * @param array<string, mixed> $processingOptions
     */
    public function run(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state, array $processingOptions, ?int $intendedModelId): void
    {
        try {
            $sse->send('status', ['message' => 'Processing with non-streaming model...']);

            $result = $this->messageProcessor->process($state->incoming(), $processingOptions, $this->relays->statuses($sse));
            if (!$result['success']) {
                $this->failures->reportFailedBufferedResult($sse, $turn, $state, $result, $intendedModelId);

                return;
            }

            $this->deliver($sse, $turn, $state, $processingOptions, $result);
        } catch (\Exception $e) {
            $this->failures->reportBufferedException($sse, $turn, $state, $e);
        }
    }

    /**
     * @param array<string, mixed> $processingOptions
     * @param array<string, mixed> $result
     */
    private function deliver(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state, array $processingOptions, array $result): void
    {
        [$content, $metadata] = $this->unpack($state->incoming(), $result);
        $classification = $result['classification'] ?? [];

        $reasoning = null;
        if (isset($metadata['reasoning'])) {
            $reasoning = $metadata['reasoning'];
            unset($metadata['reasoning']);
        }
        if ($reasoning) {
            $sse->send('reasoning_complete', ['reasoning' => $reasoning]);
        }

        // One chunk stands in for the stream.
        $sse->send('data', ['chunk' => $content]);
        if (isset($metadata['file'])) {
            $sse->send('file', [
                'type' => $metadata['file']['type'] ?? 'image',
                'url' => $metadata['file']['path'] ?? '',
            ]);
        }

        $text = is_scalar($content) ? (string) $content : '';
        $outgoing = $this->storeReply($turn, $state, $classification, $metadata, $text);
        $searchResults = $this->recordContext($state, $outgoing, $processingOptions, $result, $classification, $metadata);
        $ragSources = $this->meta->persistRagSources($outgoing, $metadata);

        $incoming = $state->incoming();
        $incoming->setTopic((string) ($classification['topic'] ?? $incoming->getTopic()));
        $incoming->setLanguage((string) ($classification['language'] ?? $incoming->getLanguage()));
        $incoming->setStatus('complete');

        $this->finisher->afterReply($turn, $state->chat, $metadata, $text);
        $usage = $this->usage->recordBufferedReply($turn, $state, $outgoing, $metadata, $classification, $text);

        $completeData = $this->completePayload($turn, $outgoing, $classification, $metadata, $usage, $searchResults, $ragSources);
        $transcript = $this->transcripts->payload();
        $this->transcripts->reset();
        if (null !== $transcript && null === $turn->caller->widgetSession && null === $turn->caller->guestSession) {
            $completeData['providerMessages'] = $transcript;
        }

        $sse->send('complete', $completeData);
    }

    /**
     * process() nests the answer under `response`; a flat result means an
     * unexpected shape and is logged.
     *
     * @param array<string, mixed> $result
     *
     * @return array{0: mixed, 1: array<string, mixed>}
     */
    private function unpack(Message $incoming, array $result): array
    {
        $response = $result['response'] ?? [];

        $usedFlatContent = !isset($response['content']) && \array_key_exists('content', $result);
        $usedFlatMetadata = !isset($response['metadata']) && \array_key_exists('metadata', $result);
        if ($usedFlatContent || $usedFlatMetadata) {
            $this->logger->warning('StreamController: Fell back to flat response structure from process()', [
                'usedFlatContent' => $usedFlatContent,
                'usedFlatMetadata' => $usedFlatMetadata,
                'messageId' => $incoming->getId(),
            ]);
        }

        return [
            $response['content'] ?? $result['content'] ?? '',
            $response['metadata'] ?? $result['metadata'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $metadata
     */
    private function storeReply(StreamTurnRequest $turn, StreamTurnState $state, array $classification, array $metadata, string $text): Message
    {
        $incoming = $state->incoming();
        $outgoing = new Message();
        $outgoing->setUserId($turn->caller->user->getId());
        $outgoing->setChat($state->chat);
        $outgoing->setTrackingId((int) $turn->trackId);
        $outgoing->setProviderIndex($incoming->getProviderIndex());
        $outgoing->setUnixTimestamp(time());
        $outgoing->setDateTime(date('YmdHis'));
        $outgoing->setMessageType('WEB');
        $outgoing->setFile(isset($metadata['file']) ? 1 : 0);
        $outgoing->setFilePath((string) ($metadata['file']['path'] ?? ''));
        $outgoing->setFileType(StreamGeneratedFiles::safeMessageFileType($metadata['file'] ?? null));
        $outgoing->setTopic((string) ($classification['topic'] ?? $incoming->getTopic()));
        $outgoing->setLanguage((string) ($classification['language'] ?? $incoming->getLanguage()));
        $outgoing->setText($text);
        $outgoing->setDirection('OUT');
        $outgoing->setStatus('complete');

        if (!$turn->incognito) {
            $this->em->persist($outgoing);
            $this->em->flush();
        }

        if (!empty($metadata['provider']) || !empty($metadata['model'])) {
            $outgoing->setMeta('ai_chat_provider', (string) ($metadata['provider'] ?? ''));
            $outgoing->setMeta('ai_chat_model', (string) ($metadata['model'] ?? ''));
            if (isset($metadata['model_id']) && '' !== $metadata['model_id']) {
                $outgoing->setMeta('ai_chat_model_id', (string) $metadata['model_id']);
            }
        }

        if (!empty($metadata['usage'])) {
            $outgoing->setMeta('ai_chat_usage', (string) json_encode($metadata['usage']));
        }

        if (isset($metadata['docs']) && is_array($metadata['docs']) && [] !== $metadata['docs']) {
            $outgoing->setMeta('docs', (string) json_encode($metadata['docs'], JSON_UNESCAPED_SLASHES));
        }

        if (!empty($metadata['response_id'])) {
            $outgoing->setMeta('openai_response_id', (string) $metadata['response_id']);
        }

        return $outgoing;
    }

    /**
     * The same reply context as a streamed answer: routing model, media
     * intent, multi-task plan with its rebound jobs, and the web search.
     *
     * @param array<string, mixed> $processingOptions
     * @param array<string, mixed> $result
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>|null the web search behind the answer
     */
    private function recordContext(StreamTurnState $state, Message $outgoing, array $processingOptions, array $result, array $classification, array $metadata): ?array
    {
        $this->meta->persistClassificationSortingMeta($outgoing, $classification);
        // Email and webhook callers get the right "Audio Model" badge and
        // "Again" capability after a reload too.
        $this->meta->persistOriginalMediaMeta($outgoing, $classification, $metadata);

        if (!empty($metadata['multitask'])) {
            $outgoing->setMeta('multitask', '1');
        }
        if (!empty($metadata['task_plan_render'])) {
            $outgoing->setMeta('task_plan', (string) json_encode($metadata['task_plan_render'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }
        $this->meta->persistTaskPlanDefinition($outgoing, $metadata);
        $this->mediaJobs->rebindTaskCards($outgoing, $metadata);

        $incoming = $state->incoming();
        if (!empty($processingOptions['web_search'])) {
            $incoming->setMeta('web_search_enabled', 'true');
        }

        // A multi-task turn searched in its own step; its results arrive in
        // the handler metadata.
        $searchResults = $result['search_results'] ?? ($metadata['search_results'] ?? null);
        if (!is_array($searchResults)) {
            return null;
        }
        $this->meta->persistWebSearch($incoming, $outgoing, $searchResults);

        return $searchResults;
    }

    /**
     * @param array<string, mixed>            $classification
     * @param array<string, mixed>            $metadata
     * @param array<string, mixed>|null       $searchResults
     * @param list<array<string, mixed>>|null $ragSources
     *
     * @return array<string, mixed>
     */
    private function completePayload(StreamTurnRequest $turn, Message $outgoing, array $classification, array $metadata, StreamUsage $usage, ?array $searchResults, ?array $ragSources): array
    {
        $completeData = [
            'messageId' => $outgoing->getId(),
            'provider' => $metadata['provider'] ?? 'unknown',
            'model' => $metadata['model'] ?? 'unknown',
            // Providers emit the id as int, string or junk; coerced once.
            'model_id' => $this->meta->normalizeModelId($metadata['model_id'] ?? null, 'non_streaming_complete'),
            'trackId' => $turn->trackId,
            'topic' => $classification['topic'] ?? null,
            'originalTopic' => $outgoing->getMeta('original_topic'),
            'originalMediaType' => $outgoing->getMeta('original_media_type'),
            'language' => $classification['language'] ?? null,
            'searchResults' => StreamCompletion::searchResultsForSse($searchResults),
            'ragSources' => $ragSources,
            'aiModels' => $this->meta->buildAiModelsPayload($outgoing),
        ];

        if (null !== $usage->chat) {
            $completeData['usage'] = $usage->chat;
        }
        if ([] !== $usage->extra) {
            $completeData['usage_extra'] = $usage->extra;
        }
        $totals = $this->usage->liveTotals($turn);
        if (null !== $totals) {
            $completeData['usage_totals'] = $totals;
        }

        if (isset($metadata['memories']) && is_array($metadata['memories'])) {
            $completeData['memoryIds'] = array_map(fn ($memory) => $memory['id'], $metadata['memories']);
        }

        if (isset($metadata['docs']) && is_array($metadata['docs']) && [] !== $metadata['docs']) {
            $completeData['docs'] = $metadata['docs'];
            $outgoing->setMeta('docs', (string) json_encode($metadata['docs'], JSON_UNESCAPED_SLASHES));
        }

        if (isset($metadata['feedbacks']) && is_array($metadata['feedbacks'])) {
            $completeData['feedbackIds'] = array_filter(
                array_map(fn ($feedback) => $feedback['id'] ?? null, $metadata['feedbacks']),
                fn ($id) => null !== $id
            );
        }

        return $completeData;
    }
}
