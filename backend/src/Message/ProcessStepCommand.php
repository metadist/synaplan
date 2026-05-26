<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async command for processing a single step in a multi-step (compound) request.
 *
 * Queued in: async_ai_high
 * Handled by: ProcessStepCommandHandler
 *
 * Steps 2+ of a compound request are dispatched to the queue while step 1
 * is processed synchronously and streamed to the user.
 */
class ProcessStepCommand
{
    /**
     * @param int    $conversationId Parent conversation/chat ID
     * @param int    $originalMsgId  The original user message that triggered the compound request
     * @param int    $userId         Owner user ID
     * @param int    $stepIndex      0-based step index within the plan
     * @param array  $stepData       Serialized PlannedStep data (capability, webSearch, mediaType, metadata)
     * @param string $previousOutput Output from the previous step to use as context
     */
    public function __construct(
        private int $conversationId,
        private int $originalMsgId,
        private int $userId,
        private int $stepIndex,
        private array $stepData,
        private string $previousOutput = '',
    ) {
    }

    public function getConversationId(): int
    {
        return $this->conversationId;
    }

    public function getOriginalMsgId(): int
    {
        return $this->originalMsgId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getStepIndex(): int
    {
        return $this->stepIndex;
    }

    public function getStepData(): array
    {
        return $this->stepData;
    }

    public function getPreviousOutput(): string
    {
        return $this->previousOutput;
    }
}
