<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\VoiceSession;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Usage\RecordedUsage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * A spoken request that made something — a picture, a video, audio or a
 * document — gets the same assistant message the typed chat would show.
 * The live voice cannot show it, so it says one sentence pointing to the
 * chat instead of reading the storage marker aloud.
 *
 * The message carries `voice_artifact_session_id`, never `voice_session_id`:
 * the transcript writer merges and bills the spoken reply by that key and
 * must not mistake this row for it.
 */
final readonly class VoiceLiveArtifact
{
    public const META_SESSION = 'voice_artifact_session_id';

    private const MEDIA_TYPES = ['image', 'video', 'audio'];
    private const ACTIVE_JOB_STATES = ['queued', 'submitting', 'running', 'finalizing'];
    private const FAILED_JOB_STATES = ['failed', 'cancelled', 'timed_out'];
    private const MARKER_LINE = '/^__[A-Z_]+__(?::.*)?$/';

    public function __construct(
        private EntityManagerInterface $em,
        private MediaJobService $mediaJobs,
        private MediaJobMessageSync $mediaJobSync,
        private GeneratedFileRegistrar $registrar,
        private LoggerInterface $logger,
        private VoiceReplyStamp $stamp = new VoiceReplyStamp(),
    ) {
    }

    /**
     * @param array<string, mixed> $result  process() payload
     * @param array<string, mixed> $pending the Synaplan models and cost of this answer
     *
     * @return array{spoken: string, artifact: array<string, mixed>}|null null when nothing was made
     */
    public function capture(VoiceSession $session, Message $question, array $result, array $pending, bool $incognito): ?array
    {
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        $content = (string) ($response['content'] ?? '');

        $media = $this->mediaFile($metadata);
        $job = $this->mediaJob($metadata);
        $cardJobs = $this->cardJobs($metadata);
        $documents = array_values(array_filter(
            $question->getFiles()->toArray(),
            static fn (File $file): bool => 'generated' === $file->getSource(),
        ));
        $documentFailed = str_contains($content, '__FILE_GENERATION_FAILED__');
        $multitaskFiles = is_array($metadata['files'] ?? null) ? $metadata['files'] : [];

        if (null === $media && null === $job && [] === $cardJobs && [] === $documents && !$documentFailed && [] === $multitaskFiles) {
            return null;
        }

        $type = $this->kind($metadata, $media, $job, $documents, $documentFailed, $cardJobs, $multitaskFiles);
        $state = $this->state($job, $cardJobs, $documentFailed);
        $prose = $this->prose($content);
        $spoken = $this->sentence($type, $state, $prose);

        $frame = ['type' => $type, 'state' => $state];
        if ($incognito) {
            $frame['row'] = $this->transientRow($content, $metadata, $media, $job, $documents);

            return ['spoken' => $spoken, 'artifact' => $frame];
        }

        $chat = null !== $session->getChatId() ? $this->em->find(Chat::class, $session->getChatId()) : null;
        if (!$chat instanceof Chat) {
            return ['spoken' => $spoken, 'artifact' => $frame];
        }

        foreach ($documents as $document) {
            $question->removeFile($document);
        }
        $classification = is_array($result['classification'] ?? null) ? $result['classification'] : [];
        $answer = $this->persist($session, $chat, $question, $content, $metadata, $classification, $media, $documents, $multitaskFiles, $pending);
        $answerId = $answer->getId();
        if (null !== $answerId) {
            $frame['messageId'] = $answerId;
            $this->bindJobs(array_merge(null !== $job ? [$job['job_id']] : [], $cardJobs), $answerId);
        }

        $this->logger->info('Live voice artifact stored', [
            'session_id' => $session->getId(),
            'message_id' => $answerId,
            'type' => $type,
            'state' => $state,
        ]);

        return ['spoken' => $spoken, 'artifact' => $frame];
    }

    /**
     * @param array<string, mixed>                   $metadata
     * @param array<string, mixed>                   $classification
     * @param array{path: string, type: string}|null $media
     * @param list<File>                             $documents
     * @param list<mixed>                            $multitaskFiles
     * @param array<string, mixed>                   $pending
     */
    private function persist(
        VoiceSession $session,
        Chat $chat,
        Message $question,
        string $content,
        array $metadata,
        array $classification,
        ?array $media,
        array $documents,
        array $multitaskFiles,
        array $pending,
    ): Message {
        $answer = (new Message())
            ->setUserId($question->getUserId())
            ->setChat($chat)
            ->setTrackingId($question->getTrackingId())
            ->setProviderIndex('WEB')
            ->setUnixTimestamp(time())
            ->setDateTime(date('YmdHis'))
            ->setMessageType('WEB')
            ->setFile(null !== $media ? 1 : 0)
            ->setFilePath($media['path'] ?? '')
            ->setFileType($media['type'] ?? '')
            ->setTopic(is_string($classification['topic'] ?? null) && '' !== $classification['topic'] ? $classification['topic'] : 'CHAT')
            ->setLanguage($this->language($classification['language'] ?? null, $question->getLanguage()))
            ->setText('' !== trim($content) ? $content : '__FILE_GENERATION_FAILED__')
            ->setDirection('OUT')
            ->setStatus('complete')
            ->setFileText('');
        $this->em->persist($answer);
        $this->em->flush();

        foreach ($documents as $document) {
            $answer->addFile($document);
        }
        foreach ($this->registerMultitaskFiles($question->getUserId(), $multitaskFiles) as $file) {
            $answer->addFile($file);
        }

        $answer->setMeta('source', 'live_voice');
        $answer->setMeta(self::META_SESSION, (string) $session->getId());
        foreach (['media_prompt', 'media_type'] as $key) {
            if (is_string($metadata[$key] ?? null) && '' !== $metadata[$key]) {
                $answer->setMeta($key, $metadata[$key]);
            }
        }
        foreach (['media_job' => 'media_job', 'task_plan_render' => 'task_plan'] as $source => $key) {
            if (is_array($metadata[$source] ?? null) && [] !== $metadata[$source]) {
                $answer->setMeta($key, (string) json_encode($metadata[$source], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            }
        }
        if (!empty($metadata['multitask'])) {
            $answer->setMeta('multitask', '1');
        }

        $recorded = $metadata['media_recorded_usage'] ?? null;
        if ($recorded instanceof RecordedUsage && null !== $media) {
            $extra = is_array($pending['usage_extra'] ?? null) ? array_values($pending['usage_extra']) : [];
            $extra[] = $recorded->toMessageUsage(
                is_string($metadata['provider'] ?? null) ? $metadata['provider'] : null,
                is_string($metadata['model'] ?? null) ? $metadata['model'] : null,
                strtoupper($media['type']),
            );
            $pending['usage_extra'] = $extra;
        }
        if ([] !== $pending) {
            $this->stamp->apply($answer, $pending);
        }

        $chat->updateTimestamp();
        $this->em->flush();

        return $answer;
    }

    /**
     * Index 0 media already rides the legacy file columns; a second row for
     * it would show the player twice. Same rule as the typed stream.
     *
     * @param list<mixed> $taskFiles
     *
     * @return list<File>
     */
    private function registerMultitaskFiles(int $userId, array $taskFiles): array
    {
        $files = [];
        foreach ($taskFiles as $index => $taskFile) {
            if (!is_array($taskFile) || !is_string($taskFile['local_path'] ?? null)) {
                continue;
            }
            $type = is_string($taskFile['type'] ?? null) ? $taskFile['type'] : '';
            $file = $this->registrar->register(
                $userId,
                $taskFile['local_path'],
                $type,
                fileText: is_string($taskFile['source_text'] ?? null) ? $taskFile['source_text'] : null,
            );
            if ($file instanceof File && !(0 === $index && in_array($type, self::MEDIA_TYPES, true))) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * A job made for the question is moved to the answer. One that already
     * finished gets the sync it skipped while it pointed at the question.
     *
     * @param list<string> $jobKeys
     */
    private function bindJobs(array $jobKeys, int $answerId): void
    {
        foreach (array_unique($jobKeys) as $key) {
            $rebound = $this->mediaJobs->rebindMessage($key, $answerId);
            if (null !== $rebound && $rebound->isTerminal()) {
                $this->mediaJobSync->syncTerminalState($rebound);
            }
        }
    }

    /**
     * The row shape of GET /api/v1/messages/{id}, for an incognito call that
     * stores nothing and still shows the result in the open chat.
     *
     * @param array<string, mixed>                                                    $metadata
     * @param array{path: string, type: string}|null                                  $media
     * @param array{job_id: string, type: string, state: string, error?: string}|null $job
     * @param list<File>                                                              $documents
     *
     * @return array<string, mixed>
     */
    private function transientRow(string $content, array $metadata, ?array $media, ?array $job, array $documents): array
    {
        $row = [
            'id' => 0,
            'direction' => 'OUT',
            'text' => '' !== trim($content) ? $content : '__FILE_GENERATION_FAILED__',
            'timestamp' => time(),
            'topic' => is_string($metadata['media_type'] ?? null) ? 'mediamaker' : 'CHAT',
        ];
        if (null !== $media) {
            $row['file'] = $media;
        }
        if (null !== $job) {
            $row['mediaJob'] = $job;
        }
        if ([] !== $documents) {
            $row['files'] = array_map(static fn (File $file): array => [
                'id' => (int) $file->getId(),
                'filename' => $file->getFileName(),
                'fileType' => $file->getFileType(),
                'filePath' => $file->getFilePath(),
                'fileSize' => $file->getFileSize(),
                'fileMime' => $file->getFileMime(),
            ], $documents);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{path: string, type: string}|null
     */
    private function mediaFile(array $metadata): ?array
    {
        $file = $metadata['file'] ?? null;
        if (!is_array($file) || !is_string($file['path'] ?? null) || '' === $file['path']) {
            return null;
        }
        $type = is_string($file['type'] ?? null) ? strtolower($file['type']) : '';

        return in_array($type, self::MEDIA_TYPES, true) ? ['path' => $file['path'], 'type' => $type] : null;
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{job_id: string, type: string, state: string, error?: string}|null
     */
    private function mediaJob(array $metadata): ?array
    {
        $job = $metadata['media_job'] ?? null;
        if (!is_array($job) || !is_string($job['job_id'] ?? null) || '' === $job['job_id']) {
            return null;
        }
        $shaped = [
            'job_id' => $job['job_id'],
            'type' => is_string($job['type'] ?? null) ? $job['type'] : '',
            'state' => is_string($job['state'] ?? null) ? $job['state'] : 'running',
        ];
        if (is_string($job['error'] ?? null) && '' !== $job['error']) {
            $shaped['error'] = $job['error'];
        }

        return $shaped;
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return list<string>
     */
    private function cardJobs(array $metadata): array
    {
        $cards = $metadata['task_plan_render']['cards'] ?? null;
        if (!is_array($cards)) {
            return [];
        }
        $keys = [];
        foreach ($cards as $card) {
            $key = is_array($card) ? ($card['job_id'] ?? null) : null;
            if (is_string($key) && '' !== $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed>                                    $metadata
     * @param array{path: string, type: string}|null                  $media
     * @param array{job_id: string, type: string, state: string}|null $job
     * @param list<File>                                              $documents
     * @param list<string>                                            $cardJobs
     * @param list<mixed>                                             $multitaskFiles
     */
    private function kind(array $metadata, ?array $media, ?array $job, array $documents, bool $documentFailed, array $cardJobs, array $multitaskFiles): string
    {
        if ([] !== $cardJobs || [] !== $multitaskFiles) {
            return 'results';
        }
        if (null !== $job && in_array($job['type'], self::MEDIA_TYPES, true)) {
            return $job['type'];
        }
        if (null !== $media) {
            return $media['type'];
        }
        if ([] !== $documents || $documentFailed) {
            return 'document';
        }
        $type = is_string($metadata['media_type'] ?? null) ? $metadata['media_type'] : '';

        return in_array($type, self::MEDIA_TYPES, true) ? $type : 'results';
    }

    /**
     * @param array{job_id: string, type: string, state: string}|null $job
     * @param list<string>                                            $cardJobs
     */
    private function state(?array $job, array $cardJobs, bool $documentFailed): string
    {
        if ($documentFailed) {
            return 'failed';
        }
        if (null !== $job) {
            if (in_array($job['state'], self::FAILED_JOB_STATES, true)) {
                return 'failed';
            }
            if (in_array($job['state'], self::ACTIVE_JOB_STATES, true)) {
                return 'running';
            }
        }

        return [] !== $cardJobs ? 'running' : 'completed';
    }

    private function language(mixed $classified, string $fallback): string
    {
        if (is_string($classified) && 2 === strlen($classified)) {
            return $classified;
        }

        return 2 === strlen($fallback) ? $fallback : 'en';
    }

    /** Answer text without storage markers, which must never be spoken. */
    private function prose(string $content): string
    {
        $lines = array_filter(
            array_map('trim', explode("\n", $content)),
            static fn (string $line): bool => '' !== $line && 1 !== preg_match(self::MARKER_LINE, $line),
        );

        return trim(implode("\n", $lines));
    }

    /**
     * English on purpose: the voice model says it in the language the person
     * is speaking, like every other Synaplan answer.
     */
    private function sentence(string $type, string $state, string $prose): string
    {
        $noun = match ($type) {
            'image' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            'document' => 'document',
            default => 'result',
        };

        if ('failed' === $state) {
            return '' !== $prose
                ? $prose
                : sprintf('The %s could not be created. Nothing was added to the chat.', $noun);
        }

        $sentence = match (true) {
            'running' === $state && 'video' === $type => 'I am creating the video now. It will appear in the chat when it is ready, which can take a few minutes.',
            'running' === $state && 'results' === $type => 'I am working on it now. The results will appear in the chat when they are ready.',
            'running' === $state => sprintf('I am creating the %s now. It will appear in the chat in a moment.', $noun),
            'image' === $type => 'The image is ready. You can see it in the chat now.',
            'video' === $type => 'The video is ready. You can watch it in the chat now.',
            'audio' === $type => 'The audio is ready. You can play it in the chat now.',
            'document' === $type => 'The document is ready. You can open it in the chat now.',
            default => 'It is done. You can see the result in the chat now.',
        };

        return '' !== $prose ? $prose."\n".$sentence : $sentence;
    }
}
