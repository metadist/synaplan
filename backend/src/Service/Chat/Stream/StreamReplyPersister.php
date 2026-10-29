<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\File;
use App\Entity\Message;
use App\Service\File\FileGenerationEnvelope;
use App\Service\File\GeneratedDocumentBundle;
use App\Service\File\UnreadSourceGuard;
use App\Service\Media\MediaJobRebinder;
use App\Service\Message\ChatModelId;
use App\Service\Realtime\SseWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores the reply of a successful streamed turn.
 *
 * The reply is the answer, or the file a document envelope became — never
 * the raw envelope (#1406). A continuation appends to the answer it
 * continues. The reply carries the models that answered, the media job it
 * waits for, the multi-task plan, the web search and the knowledge-base
 * sources, so a reload shows what the stream showed.
 */
final readonly class StreamReplyPersister
{
    private const FILE_GENERATION_FAILED = '__FILE_GENERATION_FAILED__';
    private const MEDIA_PLACEHOLDERS = ['image' => '__IMAGE_GENERATING__', 'audio' => '__AUDIO_GENERATING__'];

    public function __construct(
        private EntityManagerInterface $em,
        private StreamGeneratedFiles $files,
        private StreamMessageMeta $meta,
        private MediaJobRebinder $mediaJobs,
        private LoggerInterface $logger,
        private ?UnreadSourceGuard $unreadSourceGuard = null,
    ) {
    }

    /**
     * @param array<string, mixed> $result the successful processing result
     */
    public function persist(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state, array $result, StreamChunkRelay $relay): StreamReply
    {
        $classification = $result['classification'];
        $metadata = $result['response']['metadata'] ?? [];

        error_log('🔵 StreamController: Streaming complete, '.$relay->chunkCount.' chunks');
        $this->logger->info('StreamController: Streaming complete', [
            'chunks' => $relay->chunkCount,
            'length' => strlen($relay->responseText),
        ]);

        $taskFiles = $this->announceHandlerFiles($sse, $turn, $metadata);

        $generatedFile = null;
        if ($state->continued) {
            $state->continued->setText($state->continued->getText().$relay->responseText);
            $this->em->flush();
            $outgoing = $state->continued;
            $text = $relay->responseText;
        } else {
            [$outgoing, $text, $generatedFile] = $this->newReply($sse, $turn, $state, $classification, $metadata, $relay, $taskFiles);
        }

        $this->recordModels($turn, $outgoing, $classification, $metadata);
        $this->recordMediaAndPlan($outgoing, $classification, $metadata);
        $searchResults = $this->recordSearch($turn, $state, $outgoing, $result, $metadata);
        $ragSources = $this->meta->persistRagSources($outgoing, $metadata);

        $incoming = $state->incoming();
        if (!$state->continued) {
            $incoming->setTopic($classification['topic']);
            $incoming->setLanguage($classification['language']);
        }
        $incoming->setStatus('complete');

        return new StreamReply($outgoing, $text, $generatedFile, $ragSources, $searchResults);
    }

    /**
     * Files and links the handler produced. A multi-node plan can produce
     * more than one file; the first one rides the legacy single-file channel.
     *
     * @param array<string, mixed> $metadata
     *
     * @return list<File> the plan's files to attach to the reply
     */
    private function announceHandlerFiles(SseWriter $sse, StreamTurnRequest $turn, array $metadata): array
    {
        if (isset($metadata['file'])) {
            $sse->send('file', [
                'type' => $metadata['file']['type'],
                'url' => $metadata['file']['path'],
            ]);
            $this->logger->info('StreamController: Handler provided file', [
                'path' => $metadata['file']['path'],
                'type' => $metadata['file']['type'],
            ]);
        }

        $taskFiles = [];
        if (isset($metadata['files']) && is_array($metadata['files'])) {
            foreach (array_values($metadata['files']) as $idx => $taskFile) {
                if (0 === $idx || !is_array($taskFile) || empty($taskFile['path'])) {
                    continue;
                }
                $sse->send('file', [
                    'type' => is_string($taskFile['type'] ?? null) ? $taskFile['type'] : 'file',
                    'url' => $taskFile['path'],
                ]);
            }
            $taskFiles = $this->files->persistTaskPlanFiles($metadata['files'], $turn->caller->user->getId(), $turn->incognito);
        }

        if (isset($metadata['links'])) {
            $sse->send('links', [
                'links' => $metadata['links'],
            ]);
            $this->logger->info('StreamController: Handler provided links');
        }

        return $taskFiles;
    }

    /**
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $metadata
     * @param list<File>           $taskFiles
     *
     * @return array{0: Message, 1: string, 2: File|null}
     */
    private function newReply(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state, array $classification, array $metadata, StreamChunkRelay $relay, array $taskFiles): array
    {
        $outgoing = new Message();
        $outgoing->setUserId($turn->caller->user->getId());
        $outgoing->setChat($state->chat);
        $outgoing->setTrackingId((int) $turn->trackId);
        $outgoing->setProviderIndex($state->incoming()->getProviderIndex());
        $outgoing->setUnixTimestamp(time());
        $outgoing->setDateTime(date('YmdHis'));
        $outgoing->setMessageType('WEB');
        $outgoing->setFile(isset($metadata['file']) ? 1 : 0);
        $outgoing->setFilePath(isset($metadata['file']) ? (string) $metadata['file']['path'] : '');
        $outgoing->setFileType(StreamGeneratedFiles::safeMessageFileType($metadata['file'] ?? null));
        $outgoing->setTopic($classification['topic']);
        $outgoing->setLanguage($classification['language']);

        [$text, $generatedFile, $bundle] = $this->replyText($sse, $turn, $state, $classification, $relay);
        [$text, $generatedFile] = $this->settleReplyText($sse, $turn, $classification, $metadata, $relay, $text, $generatedFile);

        $outgoing->setText($text);
        $outgoing->setDirection('OUT');
        $outgoing->setStatus('complete');
        $this->meta->stampAgentId($outgoing, $turn->options->agent?->agentId, $turn->options->agent?->versionId);
        $this->meta->stampEffort($outgoing, $classification);

        // Incognito keeps the reply transient: the File rows exist (marked
        // ephemeral), but no attachment rows are written.
        if (!$turn->incognito) {
            $this->em->persist($outgoing);
            $this->em->flush();
        }

        $attached = $bundle?->files() ?? (null !== $generatedFile ? [$generatedFile] : []);
        foreach ([$attached, $taskFiles] as $files) {
            if ([] === $files) {
                continue;
            }
            foreach ($files as $file) {
                $outgoing->addFile($file);
            }
            if (!$turn->incognito) {
                $this->em->flush();
            }
        }

        return [$outgoing, $text, $generatedFile];
    }

    /**
     * The answer, the stored document of a document envelope (tolerating a
     * prose preamble or markdown fence, #1406), an honest refusal when the
     * document would rest on a named source URL nobody read (#2050), or the
     * text of a legacy {"BTEXT": …} envelope.
     *
     * @param array<string, mixed> $classification
     *
     * @return array{0: string, 1: File|null, 2: GeneratedDocumentBundle|null}
     */
    private function replyText(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state, array $classification, StreamChunkRelay $relay): array
    {
        $responseText = $relay->responseText;
        $incoming = $state->incoming();
        $fileEnvelope = FileGenerationEnvelope::extract($responseText);
        $refusal = null !== $fileEnvelope && null !== $this->unreadSourceGuard
            ? $this->unreadSourceGuard->refusalFor(
                (string) $incoming->getText(),
                $classification['url_pages_read'] ?? null,
                $classification['language'] ?? 'en',
            )
            : null;

        if (null !== $refusal) {
            $this->logger->info('StreamController: refusing file generation, unread source URL');

            return [$refusal, null, null];
        }

        if (null === $fileEnvelope) {
            return [$this->legacyText($responseText), null, null];
        }

        $this->logger->info('StreamController: Detected AI file generation', [
            'filename' => $fileEnvelope['filename'],
        ]);

        // The document text is complete; now it becomes the office file. The
        // client translates the stage — no user-facing text from here.
        $sse->send('generating_file', ['metadata' => [
            'stage' => 'converting',
            'filename' => $fileEnvelope['filename'],
            ...$this->files->pdfExportMeta($fileEnvelope, $incoming),
        ]]);

        $bundle = $this->files->storeDocument($fileEnvelope, $incoming, $turn->incognito);
        $generatedFile = $bundle?->primary();
        if (null === $generatedFile) {
            $this->logger->error('StreamController: File generation failed');

            return [self::FILE_GENERATION_FAILED, null, $bundle];
        }

        $this->logger->info('StreamController: File generation successful', [
            'file_id' => $generatedFile->getId(),
            'filename' => $generatedFile->getFileName(),
        ]);

        return ["__FILE_GENERATED__:{$generatedFile->getFileName()}", $generatedFile, $bundle];
    }

    /**
     * The BTEXT of a legacy {"BTEXT": …} envelope, unwrapped from a markdown
     * fence and repaired once (`"BFILE": ` without a value); the text itself
     * otherwise.
     */
    private function legacyText(string $responseText): string
    {
        $jsonContent = $responseText;
        if (preg_match('/```(?:json)?\s*\n(.*?)\n```/s', $responseText, $matches)) {
            $jsonContent = trim($matches[1]);
            $this->logger->info('StreamController: Extracted JSON from markdown code block');
        }

        if (!str_starts_with(trim($jsonContent), '{')) {
            return $responseText;
        }

        try {
            $jsonData = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $cleanedJson = preg_replace('/"BFILE":\s*\n/', '"BFILE": 0'."\n", $jsonContent);
            $cleanedJson = preg_replace('/"BFILE":\s*\r\n/', '"BFILE": 0'."\r\n", (string) $cleanedJson);
            $cleanedJson = preg_replace('/"BFILE":\s*}/', '"BFILE": 0}', (string) $cleanedJson);

            try {
                $jsonData = json_decode((string) $cleanedJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e2) {
                $this->logger->warning('StreamController: Failed to parse JSON', [
                    'error' => $e2->getMessage(),
                    'content_preview' => substr($jsonContent, 0, 200),
                ]);

                return $responseText;
            }
        }

        return is_array($jsonData) && isset($jsonData['BTEXT']) && is_scalar($jsonData['BTEXT']) ? (string) $jsonData['BTEXT'] : $responseText;
    }

    /**
     * Last corrections before the text is stored: the placeholder of a running
     * media job, no raw envelope for a document request, a document the tool
     * loop already wrote, and a failure marker instead of an empty bubble.
     * When the whole answer was buffered as JSON, the client got no data
     * chunk yet, so the file marker goes out live.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $metadata
     *
     * @return array{0: string, 1: File|null}
     */
    private function settleReplyText(SseWriter $sse, StreamTurnRequest $turn, array $classification, array $metadata, StreamChunkRelay $relay, string $text, ?File $generatedFile): array
    {
        $mediaJob = $metadata['media_job'] ?? null;
        if ('' === trim($text) && !empty($mediaJob) && is_array($mediaJob) && 'running' === ($mediaJob['state'] ?? null)) {
            $mediaType = $mediaJob['type'] ?? 'video';
            $text = is_string($mediaType) ? (self::MEDIA_PLACEHOLDERS[$mediaType] ?? '__VIDEO_GENERATING__') : '__VIDEO_GENERATING__';
        }

        $topic = is_string($classification['topic'] ?? null) ? $classification['topic'] : null;
        if (null === $generatedFile && 'officemaker' === $topic && FileGenerationEnvelope::hasSignature($text)) {
            $this->logger->warning('StreamController: officemaker reply carried an unparseable file envelope; suppressing raw blob', [
                'text_length' => strlen($text),
            ]);
            $text = self::FILE_GENERATION_FAILED;
        }

        if (null === $generatedFile && isset($metadata['generated_file']) && is_array($metadata['generated_file'])) {
            $fromToolsId = (int) ($metadata['generated_file']['id'] ?? 0);
            $fromTools = $fromToolsId > 0 ? $this->em->getRepository(File::class)->find($fromToolsId) : null;
            if ($fromTools && $fromTools->getUserId() === $turn->caller->user->getId()) {
                $generatedFile = $fromTools;
                $text = "__FILE_GENERATED__:{$generatedFile->getFileName()}";
            }
        }

        if ('' === trim($text) && 'officemaker' === ($classification['topic'] ?? '')) {
            $text = self::FILE_GENERATION_FAILED;
            $this->logger->error('StreamController: document generation produced neither file nor text');
        }

        if ('' === trim($relay->streamedVisibleText)
            && (self::FILE_GENERATION_FAILED === $text || str_starts_with($text, '__FILE_GENERATED__:'))) {
            $sse->send('data', ['chunk' => $text]);
        }

        return [$text, $generatedFile];
    }

    /**
     * The chat model that answered — the one the handler reports, the
     * requested id only as the fallback — and its usage, docs and response id.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $metadata
     */
    private function recordModels(StreamTurnRequest $turn, Message $outgoing, array $classification, array $metadata): void
    {
        $this->logger->info('StreamController: Saving model metadata', [
            'chat_provider' => $metadata['provider'] ?? 'unknown',
            'chat_model' => $metadata['model'] ?? 'unknown',
            'sorting_provider' => $classification['sorting_provider'] ?? null,
            'sorting_model' => $classification['sorting_model_name'] ?? null,
            'sorting_model_id' => $classification['sorting_model_id'] ?? null,
        ]);

        $outgoing->setMeta('ai_chat_provider', (string) ($metadata['provider'] ?? 'unknown'));
        $outgoing->setMeta('ai_chat_model', (string) ($metadata['model'] ?? 'unknown'));

        $persistedModelId = ChatModelId::persisted($metadata['model_id'] ?? null, $turn->options->modelId);
        if (null !== $persistedModelId) {
            $outgoing->setMeta('ai_chat_model_id', $persistedModelId);
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
    }

    /**
     * Media prompt and type, the media job (rebound to this reply), the
     * multi-task flag, the plan's render state and definition, the routing
     * model, and the original media intent.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $metadata
     */
    private function recordMediaAndPlan(Message $outgoing, array $classification, array $metadata): void
    {
        if (!empty($metadata['media_prompt'])) {
            $outgoing->setMeta('media_prompt', (string) $metadata['media_prompt']);
        }
        if (!empty($metadata['media_type'])) {
            $outgoing->setMeta('media_type', (string) $metadata['media_type']);
        }
        if (!empty($metadata['media_job']) && is_array($metadata['media_job'])) {
            $outgoing->setMeta('media_job', (string) json_encode($metadata['media_job'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

            $mediaJobKey = $metadata['media_job']['job_id'] ?? null;
            $outgoingId = $outgoing->getId();
            if (is_string($mediaJobKey) && '' !== $mediaJobKey && null !== $outgoingId) {
                $this->mediaJobs->rebind($mediaJobKey, $outgoingId);
            }
        }

        // A multi-task turn offers the plain "Again" (full re-plan) after a reload.
        if (!empty($metadata['multitask'])) {
            $outgoing->setMeta('multitask', '1');
        }

        // The per-node render state rebuilds the task cards on reload (#1070).
        if (!empty($metadata['task_plan_render'])) {
            $outgoing->setMeta('task_plan', (string) json_encode($metadata['task_plan_render'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }
        $this->meta->persistTaskPlanDefinition($outgoing, $metadata);
        $this->mediaJobs->rebindTaskCards($outgoing, $metadata);

        $this->meta->persistOriginalMediaMeta($outgoing, $classification, $metadata);
        $this->meta->persistClassificationSortingMeta($outgoing, $classification);
    }

    /**
     * The web search behind the answer. A multi-task turn searched in its
     * own step, so its results arrive in the handler metadata.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>|null
     */
    private function recordSearch(StreamTurnRequest $turn, StreamTurnState $state, Message $outgoing, array $result, array $metadata): ?array
    {
        $incoming = $state->incoming();
        if ($turn->options->webSearch) {
            $incoming->setMeta('web_search_enabled', 'true');
            $this->logger->info('StreamController: Web search was enabled for this message');
        }

        $searchResults = $result['search_results'] ?? ($metadata['search_results'] ?? null);
        if (!is_array($searchResults)) {
            return null;
        }

        $stored = $this->meta->persistWebSearch($incoming, $outgoing, $searchResults);
        if (null !== $stored) {
            $this->logger->info('StreamController: Stored search results metadata', $stored);
        }

        return $searchResults;
    }
}
