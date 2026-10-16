<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\Message;
use App\Service\Message\ChatErrorView;
use App\Service\Multitask\TaskPlanExecutor;
use Psr\Log\LoggerInterface;

/**
 * The metadata a streamed turn writes onto its messages, and the model
 * badges its SSE `complete` event carries.
 *
 * The badges use the shape the history endpoint returns, so a reply shows
 * the same chat, sorting and audio models live and after a reload (#603).
 */
final readonly class StreamMessageMeta
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function stampAgentId(Message $message, ?int $agentId, ?int $agentVersionId = null): void
    {
        if (null === $agentId || $agentId < 1) {
            return;
        }
        $message->setMeta('AGENTID', (string) $agentId);
        if (null !== $agentVersionId && $agentVersionId > 0) {
            $message->setMeta('AGENTVERSIONID', (string) $agentVersionId);
        }
    }

    /**
     * Store source ids (never the passage text) so a reload can show the row.
     *
     * @param array<string, mixed> $metadata
     *
     * @return list<array<string, mixed>>|null
     */
    public function persistRagSources(Message $message, array $metadata): ?array
    {
        $sources = $metadata['rag_sources'] ?? null;
        if (!is_array($sources) || [] === $sources) {
            return null;
        }

        $clean = [];
        foreach ($sources as $row) {
            if (!is_array($row) || '' === (string) ($row['chunkId'] ?? '')) {
                continue;
            }
            $clean[] = [
                'n' => (int) ($row['n'] ?? 0),
                'chunkId' => (string) $row['chunkId'],
                'fileId' => (int) ($row['fileId'] ?? 0),
                'fileName' => (string) ($row['fileName'] ?? ''),
                'groupKey' => (string) ($row['groupKey'] ?? ''),
                'score' => is_numeric($row['score'] ?? null) ? (float) $row['score'] : null,
                'startLine' => (int) ($row['startLine'] ?? 0),
                'endLine' => (int) ($row['endLine'] ?? 0),
            ];
        }
        if ([] === $clean) {
            return null;
        }

        $encoded = json_encode($clean, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            $message->setMeta('rag_sources', $encoded);
        }

        return $clean;
    }

    /**
     * The routing model the classifier picked. Without it an error row (an
     * unpulled Ollama model, a failed image) drops the sorting badge even
     * though the classifier ran (#603).
     *
     * @param array<string, mixed>|null $classification
     */
    public function persistClassificationSortingMeta(Message $message, ?array $classification): void
    {
        if (!is_array($classification)) {
            return;
        }

        if (!empty($classification['sorting_provider'])) {
            $message->setMeta('ai_sorting_provider', (string) $classification['sorting_provider']);
        }
        if (!empty($classification['sorting_model_name'])) {
            $message->setMeta('ai_sorting_model', (string) $classification['sorting_model_name']);
        }
        if (!empty($classification['sorting_model_id'])) {
            $message->setMeta('ai_sorting_model_id', (string) $classification['sorting_model_id']);
        }
    }

    /**
     * The executed DAG's node definitions. "Schedule this" turns them into
     * the Saved Task's graph, so a rerun replays the steps the person saw
     * work instead of re-planning from the instruction text.
     *
     * @param array<string, mixed> $metadata handler-result metadata
     */
    public function persistTaskPlanDefinition(Message $message, array $metadata): void
    {
        $definition = $metadata[TaskPlanExecutor::PLAN_DEFINITION_KEY] ?? null;
        if (!is_array($definition) || !is_array($definition['tasks'] ?? null) || [] === $definition['tasks']) {
            return;
        }

        $encoded = json_encode($definition, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            return;
        }

        $message->setMeta(TaskPlanExecutor::PLAN_DEFINITION_META, $encoded);
    }

    /**
     * `original_topic` / `original_media_type` on a MEDIAMAKER reply, so the
     * badge label (#583) and the "Again" model list show the media type live
     * and after a reload (#624). Other replies stay untouched.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $metadata
     */
    public function persistOriginalMediaMeta(Message $message, array $classification, array $metadata = []): void
    {
        if ('mediamaker' !== ($classification['topic'] ?? null)) {
            return;
        }

        $message->setMeta('original_topic', 'mediamaker');

        // The handler's media type reflects the pipeline that actually ran;
        // the classifier's is only the predicted intent.
        $mediaType = $metadata['media_type']
            ?? $classification['media_type']
            ?? null;

        if (!empty($mediaType)) {
            $message->setMeta('original_media_type', (string) $mediaType);
        }
    }

    /**
     * The web search behind an answer, on both messages, so the Sources
     * list survives a reload.
     *
     * @param array<string, mixed> $searchResults
     *
     * @return array{query: string, results_count: int, pages_read: int}|null what was stored; null without results
     */
    public function persistWebSearch(Message $incoming, Message $outgoing, array $searchResults): ?array
    {
        if (empty($searchResults['results'])) {
            return null;
        }

        $searchQuery = (string) ($searchResults['query'] ?? '');
        $searchCount = count($searchResults['results']);
        $incoming->setMeta('web_search_query', $searchQuery);
        $incoming->setMeta('web_search_results_count', (string) $searchCount);
        $outgoing->setMeta('web_search_query', $searchQuery);
        $outgoing->setMeta('web_search_results_count', (string) $searchCount);
        $pagesRead = (int) ($searchResults['pages_read'] ?? 0);
        if ($pagesRead > 0) {
            $outgoing->setMeta('web_search_pages_read', (string) $pagesRead);
        }

        return ['query' => $searchQuery, 'results_count' => $searchCount, 'pages_read' => $pagesRead];
    }

    public function persistChatErrorMeta(Message $outgoing, ChatErrorView $view): void
    {
        // `canRetryWithOtherModel` is derived from the reason, so persisting it
        // separately would only create a second source of truth to keep in sync.
        $outgoing->setMeta('error_reason', $view->reason->value);
        $outgoing->setMeta('error_debug', $view->rawMessage);
    }

    /**
     * The nested `aiModels` payload of the history endpoint
     * (`/api/v1/chats/{id}/messages`): chat, sorting and audio model.
     *
     * @return array{
     *   chat?: array{provider: ?string, model: ?string, model_id: ?int},
     *   sorting?: array{provider: ?string, model: ?string, model_id: ?int},
     *   audio?: array{provider: ?string, model: ?string, model_id: ?int},
     * }|null
     */
    public function buildAiModelsPayload(Message $message): ?array
    {
        $aiModels = [];

        $chatProvider = $message->getMeta('ai_chat_provider');
        $chatModel = $message->getMeta('ai_chat_model');
        if ($chatProvider || $chatModel) {
            $aiModels['chat'] = [
                'provider' => $chatProvider,
                'model' => $chatModel,
                'model_id' => $this->normalizeModelId($message->getMeta('ai_chat_model_id'), 'aiModels_chat'),
            ];
        }

        $sortingProvider = $message->getMeta('ai_sorting_provider');
        $sortingModel = $message->getMeta('ai_sorting_model');
        if ($sortingProvider || $sortingModel) {
            $aiModels['sorting'] = [
                'provider' => $sortingProvider,
                'model' => $sortingModel,
                'model_id' => $this->normalizeModelId($message->getMeta('ai_sorting_model_id'), 'aiModels_sorting'),
            ];
        }

        // The TTS model is separate from `chat`: voice reply pipes the
        // answer through its own provider (e.g. Piper), and labelling the
        // chat model "Audio Model" showed the wrong identifier (#583).
        $audioProvider = $message->getMeta('ai_audio_provider');
        $audioModel = $message->getMeta('ai_audio_model');
        if ($audioProvider || $audioModel) {
            $aiModels['audio'] = [
                'provider' => $audioProvider,
                'model' => $audioModel,
                'model_id' => $this->normalizeModelId($message->getMeta('ai_audio_model_id'), 'aiModels_audio'),
            ];
        }

        return [] === $aiModels ? null : $aiModels;
    }

    /**
     * A model id from the request, provider metadata or a message meta, as an
     * int or null.
     *
     * Providers emit ints, numeric strings and occasionally junk. A blind
     * `(int)` cast turns "abc" into 0 and corrupts the SSE `model_id` and
     * every consumer downstream (PR #833 review), so only numeric input is
     * accepted and the rest is logged.
     */
    public function normalizeModelId(mixed $raw, string $context): ?int
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && is_numeric($raw)) {
            return (int) $raw;
        }

        $this->logger->warning('StreamController: non-numeric model_id received, discarding', [
            'context' => $context,
            'type' => get_debug_type($raw),
            'value' => is_scalar($raw) ? (string) $raw : null,
        ]);

        return null;
    }
}
