<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Repository\MessageRepository;
use App\Service\Multitask\TaskPlanStore;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the persisted OUT message in sync with a terminal {@see MediaJob}.
 *
 * Two responsibilities:
 *   1. Update the `media_job` meta blob so a reload of the chat shows the same
 *      state Redis already knows (without this, a failed/completed job would
 *      reload as a perpetually "running" banner because the initial detach
 *      meta is the only record on the DB).
 *   2. On COMPLETED, register the generated file as a {@see \App\Entity\File}
 *      and attach it to the message — this is the single channel history
 *      endpoints serialize (`getFiles()`), so without it a reload shows an
 *      empty bubble even though the file is on disk and the job is `done`.
 *
 * Multitask node jobs (the job carries a `nodeId`) are synced differently
 * (#1239): the OUT message of a DAG turn holds the ASSEMBLED reply (poem +
 * connector text), so the single-task text mutation (clear on done / error on
 * failed) would destroy it, and the `media_job` banner meta belongs only to
 * single-task turns. Instead the matching card inside the persisted
 * `task_plan` meta is patched (state/url/error) and the BMESSAGE_TASKS row is
 * healed via the job key — the reload then shows the finished card exactly
 * like a card that completed inside the live turn.
 */
final readonly class MediaJobMessageSync
{
    public function __construct(
        private MessageRepository $messageRepository,
        private MediaJobService $mediaJobService,
        private GeneratedFileRegistrar $fileRegistrar,
        private MediaJobRealtimeNotifier $realtimeNotifier,
        private MediaJobUsageRecorder $usageRecorder,
        private TaskPlanStore $taskPlanStore,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function syncTerminalState(MediaJob $job): void
    {
        if (!$job->isTerminal()) {
            return;
        }

        $messageId = $job->getMessageId();
        if (null === $messageId) {
            return;
        }

        $message = $this->messageRepository->find($messageId);
        if (null === $message) {
            return;
        }

        $status = $this->mediaJobService->toStatusArray($job);

        // Direction guard (#1218): the generated media, its status meta and text
        // belong on the OUT (assistant) bubble the user sees. A job is created
        // bound to the INCOMING message id (the OUT row does not exist yet) and
        // only rebound to OUT once StreamController has persisted it. If the
        // worker finishes a fast image render BEFORE that rebind, this sync would
        // hit the IN row — clearing the user's prompt (data loss) and pinning the
        // image to their own message. Never touch an IN message here, and DO NOT
        // publish the realtime update either: it carries the IN message_id + file,
        // so the client would patch the user bubble and append the media part
        // there — re-introducing the "image on the user's message" bug through
        // realtime even though the DB row is untouched. The client's job poll
        // (keyed by job_id) resolves the OUT banner as the fallback. Billing is
        // still recorded (the render happened; idempotent).
        if ('IN' === $message->getDirection()) {
            $this->logger->info('MediaJobMessageSync: skipped terminal mutation + realtime push on IN message (awaiting rebind)', [
                'job_key' => $job->getJobKey(),
                'message_id' => $messageId,
                'state' => $status['state'],
            ]);
            $this->usageRecorder->record($job);

            return;
        }

        $nodeId = $job->getNodeId();
        if (null !== $nodeId && '' !== $nodeId) {
            // Multitask node job: the task card is the surface — patch the
            // persisted card state and NEVER touch the assembled reply text
            // or the single-task `media_job` banner meta (see class docblock).
            $this->syncTaskPlanCard($job, $message, $status['state']);
            if (MediaJob::STATUS_COMPLETED === $job->getStatus()) {
                $this->attachGeneratedFile($job, $message);
            }
        } else {
            $mediaJobMeta = [
                'job_id' => $job->getJobKey(),
                'type' => $job->getType(),
                'state' => $status['state'],
            ];
            if (null !== $job->getError() && '' !== $job->getError()) {
                $mediaJobMeta['error'] = $job->getError();
            }

            $message->setMeta(
                'media_job',
                (string) json_encode($mediaJobMeta, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
            );

            if (MediaJob::STATUS_FAILED === $job->getStatus() || MediaJob::STATUS_TIMED_OUT === $job->getStatus()) {
                $errorText = $job->getError();
                if (null !== $errorText && '' !== trim($errorText)) {
                    $message->setText($errorText);
                }
            } elseif (MediaJob::STATUS_COMPLETED === $job->getStatus()) {
                $message->setText('');
                $this->attachGeneratedFile($job, $message);
            }
        }

        // Bill the user for a successful render (no-op for non-completed states;
        // idempotent). Detached jobs never ran the inline billing path, so this
        // is where async media usage is recorded (Sprint E, #1146).
        $recorded = $this->usageRecorder->record($job);

        // Usage taximeter: persist the billed render in the message's
        // ai_usage_extra meta so the "models used" session list includes the
        // media model after the async completion — live (via the client's
        // post-completion reconcile) and after a reload. When billing already
        // happened on an earlier sync (e.g. while the job was still bound to
        // the IN message), the recorder returns null and the charged cost is
        // read from the stash it left on the job options.
        // `$recorded` is null when billing was skipped (non-completed state) or
        // already recorded on an earlier sync; the `??` uses isset() semantics,
        // so reading ->chargedCost off a null left operand safely falls through
        // to the stashed cost without a warning (no nullsafe needed here).
        $chargedCost = $recorded->chargedCost
            ?? (is_string($job->getOptions()['_usage_charged_cost'] ?? null) ? $job->getOptions()['_usage_charged_cost'] : null);
        if (MediaJob::STATUS_COMPLETED === $job->getStatus() && null !== $chargedCost) {
            $this->appendUsageExtraMeta($message, $job, $chargedCost);
        }

        $this->em->flush();

        $this->logger->info('MediaJobMessageSync: updated message meta for terminal job', [
            'job_key' => $job->getJobKey(),
            'message_id' => $messageId,
            'state' => $status['state'],
        ]);

        // Push the terminal state to the owner so the banner resolves instantly
        // (best-effort; the persisted state above + the client poll are the
        // fallback if realtime is disabled/unreachable).
        $this->realtimeNotifier->publishUpdate($job);
    }

    /**
     * Append the completed render to the message's `ai_usage_extra` meta (the
     * taximeter's per-turn auxiliary usage list). Idempotent per job key so a
     * retried sync never duplicates the entry.
     */
    private function appendUsageExtraMeta(\App\Entity\Message $message, MediaJob $job, string $chargedCost): void
    {
        $raw = $message->getMeta('ai_usage_extra');
        $entries = null !== $raw && '' !== $raw ? json_decode($raw, true) : [];
        if (!is_array($entries)) {
            $entries = [];
        }

        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['jobId'] ?? null) === $job->getJobKey()) {
                return;
            }
        }

        $provider = strtolower(trim($job->getProvider()));
        $model = trim((string) $job->getModel());
        if ('' !== $provider && '' !== $model) {
            $modelKey = $provider.':'.$model;
        } else {
            $modelKey = '' !== $model ? $model : ('' !== $provider ? $provider : 'unknown');
        }

        $entries[] = [
            'promptTokens' => 0,
            'completionTokens' => 0,
            'totalTokens' => 0,
            'cost' => $chargedCost,
            'modelKey' => $modelKey,
            'kind' => strtoupper($job->getType()),
            'jobId' => $job->getJobKey(),
        ];

        $message->setMeta('ai_usage_extra', (string) json_encode($entries));
    }

    /**
     * Patch the terminal outcome of an async media node into the OUT message's
     * persisted `task_plan` render meta and the BMESSAGE_TASKS row (#1239).
     *
     * The DAG turn persisted the card as 'running' (the job outlives the
     * request); without this heal a reload keeps showing the skeleton/spinner
     * even though the render finished. The card is matched by nodeId — the
     * plan validator guarantees node ids are unique within a plan.
     */
    private function syncTaskPlanCard(MediaJob $job, \App\Entity\Message $message, string $state): void
    {
        $jobResult = $job->getResult();
        $file = is_array($jobResult['file'] ?? null) ? $jobResult['file'] : null;
        $fileUrl = null !== $file && is_string($file['url'] ?? null) && '' !== $file['url']
            ? $file['url']
            : null;
        $fileType = null !== $file && is_string($file['type'] ?? null) && '' !== $file['type']
            ? $file['type']
            : $job->getType();
        $this->taskPlanStore->updateStatusByJobKey($job->getJobKey(), $state, [
            'url' => $fileUrl,
            'type' => $fileType,
            'error' => null !== $job->getError() && '' !== $job->getError() ? $job->getError() : null,
        ]);

        $raw = $message->getMeta('task_plan');
        if (null === $raw || '' === $raw) {
            return;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['cards'] ?? null)) {
            return;
        }

        $patched = false;
        foreach ($decoded['cards'] as &$card) {
            if (!is_array($card) || ($card['nodeId'] ?? null) !== $job->getNodeId()) {
                continue;
            }
            $card['state'] = $state;
            if (null !== $job->getError() && '' !== $job->getError()) {
                $card['error'] = $job->getError();
            }
            if (null !== $fileUrl) {
                // Stored as the public upload URL — the frontend mapper's
                // buildUploadUrl() passes that form through unchanged.
                $card['url'] = $fileUrl;
                $card['type'] = $fileType;
            }
            $patched = true;
            break;
        }
        unset($card);

        if (!$patched) {
            return;
        }

        $message->setMeta(
            'task_plan',
            (string) json_encode($decoded, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
        );

        $this->logger->info('MediaJobMessageSync: healed task card for terminal node job', [
            'job_key' => $job->getJobKey(),
            'message_id' => $message->getId(),
            'node_id' => $job->getNodeId(),
            'state' => $state,
        ]);
    }

    /**
     * Register the file the worker wrote during finalize as a `File` entity and
     * link it to the message. Without this, a reload would still show an empty
     * bubble: the live poll picks up `result.file.url` and adds the part on the
     * client, but that part is lost on refresh because nothing connects it to
     * the message row in the DB.
     */
    private function attachGeneratedFile(MediaJob $job, \App\Entity\Message $message): void
    {
        $result = $job->getResult();
        $url = is_array($result['file'] ?? null) ? (string) ($result['file']['url'] ?? '') : '';
        if ('' === $url) {
            return;
        }

        // The worker stores the URL as `/api/v1/files/uploads/<relative_path>`;
        // strip the route prefix to get the path inside the upload directory.
        $prefix = '/api/v1/files/uploads/';
        if (!str_starts_with($url, $prefix)) {
            return;
        }
        $relativePath = substr($url, strlen($prefix));

        // Idempotency: if the message already has a generated file at this
        // path, we're done — avoids creating a duplicate File row on a retried
        // sync (e.g. the reaper firing on a job the worker already completed).
        foreach ($message->getFiles() as $existing) {
            if ($existing->getFilePath() === $relativePath) {
                return;
            }
        }

        // #1251: async TTS stores a GENERIC BFILETYPE ('audio') and, without the
        // spoken script, a later "what was said?" follow-up / knowledge-base add
        // falls through to Whisper/Tika and records the MP3 duration instead of
        // the text. The synchronous MediaGenerationHandler path already persists
        // the prompt as BFILETEXT; mirror that here for the detached job path so
        // both channels behave the same. Only audio carries usable source text.
        $fileText = null;
        if (MediaJob::TYPE_AUDIO === $job->getType()) {
            $prompt = $job->getPrompt();
            if (null !== $prompt && '' !== trim($prompt)) {
                $fileText = $prompt;
            }
        }

        $file = $this->fileRegistrar->register(
            $job->getUserId(),
            $relativePath,
            $job->getType(),
            $message->getId(),
            fileText: $fileText,
        );
        if (null === $file) {
            $this->logger->warning('MediaJobMessageSync: failed to register generated file', [
                'job_key' => $job->getJobKey(),
                'message_id' => $message->getId(),
                'path' => $relativePath,
            ]);

            return;
        }

        $message->addFile($file);

        // Mirror the synchronous media path (StreamController) by also setting
        // the legacy file columns. The history formatter exposes generated
        // media through the `file` field (BFILE/BFILEPATH/BFILETYPE), and the
        // frontend mapper builds the video/image/audio part from that field —
        // NOT from the `files[]` relation (which it only renders for audio).
        // Setting these makes a page reload show the video through the exact
        // same proven path as a normally-generated clip.
        $message->setFile(1);
        $message->setFilePath($relativePath);
        $message->setFileType($job->getType());
    }
}
