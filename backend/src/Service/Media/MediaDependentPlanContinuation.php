<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Repository\MessageRepository;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\Execution\NodeContextRehydrator;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\Multitask\TaskPlanStore;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

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
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function continue(int $messageId, string $nodeId, string $jobKey): void
    {
        $message = $this->messages->find($messageId);
        if (null === $message || 'OUT' !== $message->getDirection()) {
            return;
        }
        $definition = $message->getMeta(TaskPlanExecutor::PLAN_DEFINITION_META);
        if (!is_string($definition) || '' === $definition) {
            return;
        }
        $decoded = json_decode($definition, true);
        if (!is_array($decoded)) {
            return;
        }
        $plan = TaskPlan::fromArray($decoded);
        if (!self::stepNeedsThisRender($plan, $nodeId)) {
            return;
        }

        $job = $this->jobs->findByKey($jobKey);
        if (null === $job || !$job->isTerminal()) {
            return;
        }

        $context = $this->rehydrator->fromMessage($message);
        $context->setResult($nodeId, $this->settled($job));
        $assembled = $this->dag->execute($plan, $context);
        $this->plans->persistWithStatuses(
            $messageId,
            $plan,
            null,
            $assembled['node_statuses'],
            'pending',
            $assembled['node_job_keys'],
        );
        $content = trim($assembled['content']);
        if ('' !== $content) {
            $message->setText($content);
        }
        $this->em->flush();
        $this->logger->info('Media plan continued after the render finished', [
            'message_id' => $messageId,
            'node_id' => $nodeId,
            'job_key' => $jobKey,
        ]);
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
