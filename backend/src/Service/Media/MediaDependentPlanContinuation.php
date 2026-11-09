<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeContextRehydrator;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\Multitask\TaskPlanStore;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * When a render another step needs has finished, run the rest of the plan
 * on the worker. The web request that started the render already returned.
 */
final readonly class MediaDependentPlanContinuation
{
    public function __construct(
        private MessageRepository $messages,
        private MediaJobService $jobs,
        private NodeContextRehydrator $rehydrator,
        private DagExecutor $dag,
        private TaskPlanStore $plans,
        private GeneratedFileRegistrar $files,
        private EntityManagerInterface $em,
        private LockFactory $locks,
        private MediaJobRebinder $rebinder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * False only when another worker holds this plan's lock. The caller
     * retries; dropping the command would leave a finished render's
     * dependents pending.
     */
    public function continue(int $messageId, string $nodeId, string $jobKey): bool
    {
        $job = $this->jobs->findByKey($jobKey);
        if (null === $job || !$job->isTerminal()) {
            return true;
        }
        $visible = $this->messages->find($messageId);
        if (!$visible instanceof Message) {
            return true;
        }
        $planMessageId = $this->planMessageId($job, $visible);
        $lock = $this->locks->createLock('media-plan:'.$planMessageId, 120);
        if (!$lock->acquire()) {
            return false;
        }
        try {
            $this->continueLocked($visible, $planMessageId, $nodeId, $job);
        } finally {
            $lock->release();
        }

        return true;
    }

    private function continueLocked(Message $visible, int $planMessageId, string $nodeId, MediaJob $job): void
    {
        $planMessage = $this->messages->find($planMessageId) ?? $visible;
        $definition = $this->definition($visible, $planMessage);
        if (null === $definition) {
            return;
        }
        $plan = TaskPlan::fromArray($definition);
        if (!self::stepNeedsThisRender($plan, $nodeId)) {
            return;
        }

        $options = [];
        $trackId = $job->getTrackId();
        if (null !== $trackId && '' !== $trackId) {
            $options['track_id'] = $trackId;
        }
        $context = $this->rehydrator->fromMessage($planMessage, $options);
        $context->setResult($nodeId, $this->settled($job));
        $assembled = $this->dag->execute($plan, $context);
        $this->plans->persistWithStatuses(
            $planMessageId,
            $plan,
            null,
            $assembled['node_statuses'],
            'pending',
            $assembled['node_job_keys'],
            $this->cardResults($plan, $context),
        );
        $content = trim($assembled['content']);
        if ('' !== $content && 'OUT' === $visible->getDirection()) {
            $visible->setText($content);
        }
        $this->attachFiles($visible, $assembled['files']);
        $this->em->flush();
        $this->rebindNewJobs($visible, $assembled['node_job_keys']);
        $this->logger->info('Media plan continued after the render finished', [
            'message_id' => $visible->getId(),
            'plan_message_id' => $planMessageId,
            'node_id' => $nodeId,
            'job_key' => $job->getJobKey(),
        ]);
    }

    /**
     * Jobs the resumed plan just started are bound to the plan message.
     * Point them at the reply the person sees, and keep planMessageId so
     * the next finished render can find the same rows. A render that
     * already finished while bound to the prompt is synced here.
     *
     * @param array<string, string> $jobKeys
     */
    private function rebindNewJobs(Message $visible, array $jobKeys): void
    {
        $visibleId = $visible->getId();
        if (null === $visibleId || 'OUT' !== $visible->getDirection()) {
            return;
        }
        foreach ($jobKeys as $jobKey) {
            if ('' !== $jobKey) {
                $this->rebinder->rebind($jobKey, $visibleId);
            }
        }
    }

    /**
     * True when a step other than the final assembly reads this node's output.
     */
    public static function stepNeedsThisRender(TaskPlan $plan, string $nodeId): bool
    {
        foreach ($plan->nodes as $node) {
            if (Capability::ComposeReply === $node->capability) {
                continue;
            }
            if (in_array($nodeId, $node->dependsOn, true)) {
                return true;
            }
        }

        return false;
    }

    private function planMessageId(MediaJob $job, Message $visible): int
    {
        $stored = $job->getOptions()['planMessageId'] ?? null;
        if (is_int($stored) && $stored > 0) {
            return $stored;
        }
        if (is_string($stored) && ctype_digit($stored)) {
            return (int) $stored;
        }
        $id = $visible->getId();

        return null !== $id ? $id : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function definition(Message $visible, Message $planMessage): ?array
    {
        foreach ([$visible, $planMessage] as $message) {
            $raw = $message->getMeta(TaskPlanExecutor::PLAN_DEFINITION_META);
            if (!is_string($raw) || '' === $raw) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{text?: ?string, url?: ?string, type?: ?string, error?: ?string}>
     */
    private function cardResults(TaskPlan $plan, NodeContext $context): array
    {
        $results = [];
        foreach ($plan->nodes as $node) {
            $result = $context->getResult($node->id);
            if (null === $result) {
                continue;
            }
            $file = $result->firstFile();
            $results[$node->id] = [
                'text' => $result->text,
                'url' => is_array($file) && is_string($file['path'] ?? null) ? $file['path'] : null,
                'type' => is_array($file) && is_string($file['type'] ?? null) ? $file['type'] : null,
                'error' => $result->error,
            ];
        }

        return $results;
    }

    /**
     * @param list<array<string, mixed>> $files
     */
    private function attachFiles(Message $message, array $files): void
    {
        $messageId = $message->getId();
        foreach ($files as $file) {
            $path = $file['path'] ?? null;
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $type = is_string($file['type'] ?? null) ? $file['type'] : 'file';
            $registered = $this->files->register((int) $message->getUserId(), $path, $type, $messageId);
            if (null !== $registered) {
                $message->addFile($registered);
            }
        }
    }

    private function settled(MediaJob $job): NodeResult
    {
        if (MediaJob::STATUS_COMPLETED !== $job->getStatus()) {
            $error = $job->getError();

            return NodeResult::failed(null !== $error && '' !== $error ? $error : 'The render did not finish');
        }
        $result = $job->getResult();
        $file = is_array($result['file'] ?? null) ? $result['file'] : [];
        $url = is_string($file['url'] ?? null) ? $file['url'] : '';
        if ('' === $url) {
            return NodeResult::failed('The render finished without a file');
        }

        return NodeResult::ok(null, [[
            'path' => $url,
            'type' => is_string($file['type'] ?? null) ? $file['type'] : $job->getType(),
            'local_path' => null,
        ]]);
    }
}
