<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessStepCommand;
use App\UseCase\PlannedStep;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles deferred steps (2+) of a compound multi-step request.
 *
 * Each step is processed sequentially by the queue worker. The handler:
 * 1. Reconstructs the PlannedStep from the serialized data
 * 2. Resolves the appropriate handler for the capability
 * 3. Executes the step with the previous step's output as context
 * 4. Persists the result as a new message in the conversation
 */
#[AsMessageHandler]
final readonly class ProcessStepCommandHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessStepCommand $command): void
    {
        $stepData = $command->getStepData();
        $step = PlannedStep::fromRouterResponse($stepData);

        $this->logger->info('ProcessStepCommandHandler: Processing deferred step', [
            'conversation_id' => $command->getConversationId(),
            'original_msg_id' => $command->getOriginalMsgId(),
            'user_id' => $command->getUserId(),
            'step_index' => $command->getStepIndex(),
            'capability' => $step->capability,
            'topic' => $step->toTopic(),
        ]);

        // TODO: Full handler integration will be added when the handler
        // infrastructure supports non-streaming execution. For now, log
        // the step execution intent. The actual processing requires:
        // - Creating a synthetic message for the step
        // - Running the appropriate handler (ChatHandler, MediaGenerationHandler, etc.)
        // - Writing the result back into the conversation
        //
        // This will be connected once the streaming/non-streaming handler
        // abstraction is in place.

        $this->logger->info('ProcessStepCommandHandler: Step dispatched (handler integration pending)', [
            'step_id' => $step->id,
            'capability' => $step->capability,
            'media_type' => $step->mediaType,
            'web_search' => $step->webSearch,
            'has_previous_output' => '' !== $command->getPreviousOutput(),
        ]);
    }
}
