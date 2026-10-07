<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Entity\Message;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\Execution\NodeContextRehydrator;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\Multitask\TaskPlanStore;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Continues the same run after the person answers an ask-the-user step.
 * Earlier steps are not run again: the executor resumes from the paused node.
 */
final readonly class ChatAskUserService
{
    public function __construct(
        private DagExecutor $dagExecutor,
        private NodeContextRehydrator $rehydrator,
        private TaskPlanStore $plans,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{answer: string, continued: bool}
     */
    public function submit(Message $message, string $nodeId, ?string $answer, bool $skip): array
    {
        $definition = $message->getMeta(TaskPlanExecutor::PLAN_DEFINITION_META);
        if (!is_string($definition) || '' === $definition) {
            throw new \RuntimeException('This run can no longer be continued. Send a new message.');
        }
        $decoded = json_decode($definition, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('This run can no longer be continued. Send a new message.');
        }
        $plan = TaskPlan::fromArray($decoded);
        $node = null;
        foreach ($plan->nodes as $candidate) {
            if ($candidate->id === $nodeId && Capability::AskUser === $candidate->capability) {
                $node = $candidate;
                break;
            }
        }
        if (null === $node) {
            throw new \InvalidArgumentException('That question is not part of this run.');
        }

        $question = $node->params;
        $expires = $question['expires_at'] ?? null;
        if (is_int($expires) && $expires > 0 && time() > $expires) {
            throw new \RuntimeException('This question expired. Send a new message and the run will start again.');
        }

        $text = $skip
            ? trim((string) ($question['recommended'] ?? ''))
            : trim((string) $answer);
        if ('' === $text) {
            throw new \InvalidArgumentException($skip ? 'There is no recommended answer to skip to.' : 'Choose an option or type an answer.');
        }

        $context = $this->rehydrator->fromMessage($message);
        $assembled = $this->dagExecutor->resume($plan, $context, $nodeId, ['answer' => $text]);
        $content = trim($assembled['content']);
        if ('' !== $content) {
            $message->setText($content);
        }
        $messageId = $message->getId();
        if (null !== $messageId) {
            $this->plans->persistWithStatuses(
                $messageId,
                $plan,
                null,
                $assembled['node_statuses'],
                'pending',
                [],
                [],
            );
        }
        $this->em->flush();

        return ['answer' => $text, 'continued' => '' !== $content];
    }
}
