<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Entity\Message;
use App\Entity\SavedTaskRun;
use App\Repository\MessageRepository;
use App\Service\Multitask\TaskPlanStore;

/**
 * Rebuilds a {@see NodeContext} from persisted BMESSAGE_TASKS rows so a
 * resume is a fresh job, not an in-memory suspension.
 */
final readonly class NodeContextRehydrator
{
    public function __construct(
        private TaskPlanStore $planStore,
        private MessageRepository $messages,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function fromRun(SavedTaskRun $run, array $options = []): NodeContext
    {
        $messageId = $run->getMessageId();
        if (null === $messageId) {
            throw new \RuntimeException('This run has no saved step results to resume from.');
        }
        $message = $this->messages->find($messageId);
        if (!$message instanceof Message) {
            throw new \RuntimeException('This run has no saved step results to resume from.');
        }

        $context = new NodeContext(
            $message,
            [],
            $message->getUserId(),
            [],
            $options,
        );

        foreach ($this->planStore->loadRows($messageId) as $row) {
            $nodeId = $row['nodeId'];
            $status = $row['status'];
            $text = $row['text'];
            $error = $row['error'];
            if ('done' === $status) {
                $context->setResult($nodeId, NodeResult::ok($text));
                continue;
            }
            if ('failed' === $status) {
                $context->setResult($nodeId, NodeResult::failed($error ?? 'This step failed'));
                continue;
            }
            if ('skipped' === $status) {
                $context->setResult($nodeId, NodeResult::skipped($error ?? 'Skipped'));
                continue;
            }
            if ('waiting_approval' === $status) {
                $context->setResult($nodeId, NodeResult::waitingApproval(0));
            }
        }

        return $context;
    }
}
