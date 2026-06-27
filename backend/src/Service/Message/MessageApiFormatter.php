<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\File\DataUrlFixer;

/**
 * Serializes a persisted Message entity into the canonical API row shape.
 *
 * Single source of truth for issue #1070: the chat history endpoint
 * (GET /api/v1/chats/{id}/messages) and the single-message endpoint
 * (GET /api/v1/messages/{id}) must produce the exact same shape, because
 * the frontend reconciles the live SSE-streamed state against this
 * payload after `complete`. Keeping one formatter guarantees streaming
 * and reload can never diverge on files/media/metadata.
 */
final readonly class MessageApiFormatter
{
    public function __construct(
        private MessageRepository $messageRepository,
        private SearchResultRepository $searchResultRepository,
        private DataUrlFixer $dataUrlFixer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function format(Message $m): array
    {
        $filesData = [];
        if ($m->hasFiles()) {
            foreach ($m->getFiles() as $file) {
                $filesData[] = [
                    'id' => $file->getId(),
                    'filename' => $file->getFileName(),
                    'fileType' => $file->getFileType(),
                    'filePath' => $file->getFilePath(),
                    'fileSize' => $file->getFileSize(),
                    'fileMime' => $file->getFileMime(),
                ];
            }
        }

        // Get AI model metadata for assistant messages
        $aiModels = [];
        $webSearchData = null;
        $searchResultsData = [];
        $wasMultitask = false;

        if ('OUT' === $m->getDirection()) {
            // Multi-task routing: the turn ran the DAG executor, so the
            // frontend shows the simple "Again" (full re-plan) control.
            $wasMultitask = '1' === $m->getMeta('multitask');

            // Chat model (used for generating the response)
            $chatProvider = $m->getMeta('ai_chat_provider');
            $chatModel = $m->getMeta('ai_chat_model');
            $chatModelIdMeta = $m->getMeta('ai_chat_model_id');
            if ($chatProvider || $chatModel) {
                $aiModels['chat'] = [
                    'provider' => $chatProvider,
                    'model' => $chatModel,
                    'model_id' => $chatModelIdMeta ? (int) $chatModelIdMeta : null,
                ];
            }

            // Sorting model (used for classification/routing)
            $sortingProvider = $m->getMeta('ai_sorting_provider');
            $sortingModel = $m->getMeta('ai_sorting_model');
            $sortingModelId = $m->getMeta('ai_sorting_model_id');
            if ($sortingProvider || $sortingModel) {
                $aiModels['sorting'] = [
                    'provider' => $sortingProvider,
                    'model' => $sortingModel,
                    'model_id' => $sortingModelId ? (int) $sortingModelId : null,
                ];
            }

            // Audio model (TTS pipeline used for voice reply, e.g. Piper).
            // Surfaced separately from `chat` so a page reload also shows
            // the actual TTS model under the "Audio Model" badge — see
            // issue #583.
            $audioProvider = $m->getMeta('ai_audio_provider');
            $audioModel = $m->getMeta('ai_audio_model');
            $audioModelId = $m->getMeta('ai_audio_model_id');
            if ($audioProvider || $audioModel) {
                $aiModels['audio'] = [
                    'provider' => $audioProvider,
                    'model' => $audioModel,
                    'model_id' => $audioModelId ? (int) $audioModelId : null,
                ];
            }

            // Web Search metadata
            $searchQuery = $m->getMeta('web_search_query');
            $searchResultsCount = $m->getMeta('web_search_results_count');
            if ($searchQuery || $searchResultsCount) {
                $webSearchData = [
                    'query' => $searchQuery,
                    'resultsCount' => $searchResultsCount ? (int) $searchResultsCount : 0,
                ];

                // Load actual search results from DB.
                // Search results are stored on the INCOMING (user) message, but we need to display them
                // on the OUTGOING (AI) message. So we need to find the previous incoming message.
                $incomingMessage = $this->messageRepository->createQueryBuilder('prev')
                    ->where('prev.chatId = :chatId')
                    ->andWhere('prev.direction = :direction')
                    ->andWhere('prev.unixTimestamp < :timestamp')
                    ->setParameter('chatId', $m->getChatId())
                    ->setParameter('direction', 'IN')
                    ->setParameter('timestamp', $m->getUnixTimestamp())
                    ->orderBy('prev.unixTimestamp', 'DESC')
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();

                if ($incomingMessage) {
                    $searchResults = $this->searchResultRepository->findByMessage($incomingMessage);
                    foreach ($searchResults as $sr) {
                        $searchResultsData[] = [
                            'title' => $sr->getTitle(),
                            'url' => $sr->getUrl(),
                            'description' => $sr->getDescription(),
                            'published' => $sr->getPublished(),
                            'source' => $sr->getSource(),
                            'thumbnail' => $sr->getThumbnail(),
                        ];
                    }
                }
            }
        } elseif ('IN' === $m->getDirection()) {
            // Check if web search was enabled for incoming message
            $webSearchEnabled = $m->getMeta('web_search_enabled');
            if ('true' === $webSearchEnabled) {
                $webSearchData = [
                    'enabled' => true,
                ];
            }
        }

        // Fix data URL to file if needed (legacy migration)
        $filePath = $m->getFilePath();
        if ($m->getFile() && $filePath && str_starts_with($filePath, 'data:')) {
            $filePath = $this->dataUrlFixer->ensureFileOnDisk($m);
        }

        $originalTopic = $m->getMeta('original_topic');
        $originalMediaType = $m->getMeta('original_media_type');

        // Quoted reference the user attached when composing this message
        // ("Mention in chat"). Stored as message meta so it survives reload.
        $quotedText = $m->getMeta('quoted_text');
        $quotedMessageIdMeta = $m->getMeta('quoted_message_id');

        $mediaJob = $this->decodeMediaJobMeta($m);
        $text = $m->getText();
        if ('' === trim((string) $text) && null !== $mediaJob && 'running' === ($mediaJob['state'] ?? null)) {
            $text = match ($mediaJob['type'] ?? 'video') {
                'image' => '__IMAGE_GENERATING__',
                'audio' => '__AUDIO_GENERATING__',
                default => '__VIDEO_GENERATING__',
            };
        }

        return [
            'id' => $m->getId(),
            'text' => $text,
            'direction' => $m->getDirection(),
            'timestamp' => $m->getUnixTimestamp(),
            'provider' => $m->getProviderIndex(),
            'topic' => $m->getTopic(),
            'originalTopic' => $originalTopic,
            'originalMediaType' => $originalMediaType,
            'language' => $m->getLanguage(),
            'createdAt' => $m->getDateTime(),
            'quotedText' => $quotedText,
            'quotedMessageId' => null !== $quotedMessageIdMeta ? (int) $quotedMessageIdMeta : null,
            'files' => $filesData, // Attached files (user uploads)
            'aiModels' => !empty($aiModels) ? $aiModels : null, // AI model metadata
            'webSearch' => $webSearchData, // Web search metadata
            'searchResults' => !empty($searchResultsData) ? $searchResultsData : null, // Actual search results
            'multitask' => $wasMultitask, // True when the turn ran the multi-task DAG
            // Per-node task-plan render state for reload (issue #1070 — DAG divergence).
            // Null for non-DAG turns, non-null only on OUT messages of DAG turns.
            'taskPlan' => $this->decodeTaskPlanMeta($m),
            // Background media job (Release 4.0 async video) — lets the UI show
            // a persistent "generating in background" banner after reload.
            'mediaJob' => $mediaJob,
            // Generated content (images, videos, audio from AI)
            'file' => ($m->getFile() && $filePath) ? [
                'path' => $filePath,
                'type' => $m->getFileType(),
            ] : null,
        ];
    }

    /**
     * Decode the persisted `task_plan` meta into the API shape expected by the
     * frontend mapper. Returns null for non-DAG messages or if the meta is absent.
     *
     * Shape: { reply_node: string, cards: list<{nodeId, capability, kind, state,
     *          text?, url?, type?, error?}> }
     *
     * @return array<string, mixed>|null
     */
    private function decodeTaskPlanMeta(Message $m): ?array
    {
        $raw = $m->getMeta('task_plan');
        if (null === $raw || '' === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['cards']) || !is_array($decoded['cards'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * Decode the persisted `media_job` meta into the API shape expected by the
     * frontend mapper. Returns null when no background job is attached.
     *
     * Shape: { job_id: string, type: string, state: string }
     *
     * @return array<string, mixed>|null
     */
    private function decodeMediaJobMeta(Message $m): ?array
    {
        $raw = $m->getMeta('media_job');
        if (null === $raw || '' === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['job_id']) || !is_string($decoded['job_id'])) {
            return null;
        }

        return $decoded;
    }
}
