<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Message;
use App\Message\ProcessStepCommand;
use App\Repository\MessageRepository;
use App\Service\Message\InferenceRouter;
use App\UseCase\PlannedStep;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Handles deferred steps (2+) of a compound multi-step request.
 *
 * Executes the step via InferenceRouter, persists the result as message
 * metadata on the existing OUT-message, and notifies the WebSocket server
 * so the frontend can display the result immediately.
 */
#[AsMessageHandler]
final readonly class ProcessStepCommandHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageRepository $messageRepository,
        private InferenceRouter $router,
        private HttpClientInterface $httpClient,
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

        $originalMessage = $this->em->getRepository(Message::class)->find($command->getOriginalMsgId());
        if (!$originalMessage) {
            $this->logger->error('ProcessStepCommandHandler: Original message not found', [
                'original_msg_id' => $command->getOriginalMsgId(),
            ]);

            return;
        }

        $outgoingMessage = $this->findOutgoingMessage($originalMessage);
        if (!$outgoingMessage) {
            $this->logger->error('ProcessStepCommandHandler: OUT message not found', [
                'original_msg_id' => $command->getOriginalMsgId(),
                'tracking_id' => $originalMessage->getTrackingId(),
            ]);

            return;
        }

        $classification = $this->buildStepClassification($step, $originalMessage, $command->getPreviousOutput());

        $conversationHistory = $this->messageRepository->findChatHistory(
            $command->getUserId(),
            $command->getConversationId(),
            10,
            8000,
        );

        try {
            $response = $this->router->route(
                $originalMessage,
                $conversationHistory,
                $classification,
                null,
                [],
            );

            $this->persistStepResult($outgoingMessage, $command->getStepIndex(), $step, $response);
            $this->notifyWebSocket($command, $step, $response);

            $this->logger->info('ProcessStepCommandHandler: Step completed successfully', [
                'step_index' => $command->getStepIndex(),
                'capability' => $step->capability,
                'has_file' => isset($response['metadata']['file']),
                'message_id' => $outgoingMessage->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('ProcessStepCommandHandler: Step execution failed', [
                'step_index' => $command->getStepIndex(),
                'capability' => $step->capability,
                'error' => $e->getMessage(),
            ]);

            $this->persistStepError($outgoingMessage, $command->getStepIndex(), $step, $e->getMessage());
            $this->notifyWebSocketError($command, $step, $e->getMessage());

            throw $e;
        }
    }

    private function findOutgoingMessage(Message $originalMessage): ?Message
    {
        return $this->em->getRepository(Message::class)->findOneBy([
            'trackingId' => $originalMessage->getTrackingId(),
            'direction' => 'OUT',
        ], ['id' => 'DESC']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStepClassification(PlannedStep $step, Message $originalMessage, string $previousOutput): array
    {
        $classification = [
            'topic' => $step->toTopic(),
            'language' => $originalMessage->getLanguage() ?: 'en',
            'intent' => $this->capabilityToIntent($step->capability),
            'source' => 'compound_step',
            'web_search' => $step->webSearch,
        ];

        if (null !== $step->mediaType) {
            $classification['media_type'] = $step->mediaType;
        }

        if ('' !== $previousOutput) {
            $classification['previous_step_output'] = $previousOutput;
        }

        return $classification;
    }

    private function capabilityToIntent(string $capability): string
    {
        return match ($capability) {
            'IMAGE_GENERATION', 'VIDEO_GENERATION', 'AUDIO_GENERATION' => 'image_generation',
            'FILE_GENERATION' => 'document_generation',
            'FILE_ANALYSIS' => 'file_analysis',
            default => 'chat',
        };
    }

    private function persistStepResult(Message $outMessage, int $stepIndex, PlannedStep $step, array $response): void
    {
        $result = [
            'status' => 'complete',
            'step_index' => $stepIndex,
            'capability' => $step->capability,
            'completed_at' => time(),
        ];

        if (isset($response['metadata']['file'])) {
            $result['file'] = $response['metadata']['file'];
        }

        if (isset($response['content']) && '' !== $response['content']) {
            $result['content'] = $response['content'];
        }

        $metaKey = 'deferred_step_'.$stepIndex;
        $outMessage->setMeta($metaKey, json_encode($result, JSON_THROW_ON_ERROR));
        $this->em->flush();
    }

    private function persistStepError(Message $outMessage, int $stepIndex, PlannedStep $step, string $error): void
    {
        $result = [
            'status' => 'error',
            'step_index' => $stepIndex,
            'capability' => $step->capability,
            'completed_at' => time(),
            'error' => $error,
        ];

        $metaKey = 'deferred_step_'.$stepIndex;
        $outMessage->setMeta($metaKey, json_encode($result, JSON_THROW_ON_ERROR));
        $this->em->flush();
    }

    private function notifyWebSocket(ProcessStepCommand $command, PlannedStep $step, array $response): void
    {
        $payload = [
            'type' => 'step_complete',
            'user_id' => $command->getUserId(),
            'conversation_id' => $command->getConversationId(),
            'message_id' => $command->getOriginalMsgId(),
            'step_index' => $command->getStepIndex(),
            'capability' => $step->capability,
            'result' => [
                'status' => 'complete',
                'file' => $response['metadata']['file'] ?? null,
                'content' => isset($response['content']) ? mb_substr($response['content'], 0, 500) : null,
            ],
        ];

        $this->sendWsNotification($payload);
    }

    private function notifyWebSocketError(ProcessStepCommand $command, PlannedStep $step, string $error): void
    {
        $payload = [
            'type' => 'step_error',
            'user_id' => $command->getUserId(),
            'conversation_id' => $command->getConversationId(),
            'message_id' => $command->getOriginalMsgId(),
            'step_index' => $command->getStepIndex(),
            'capability' => $step->capability,
            'result' => [
                'status' => 'error',
                'error' => $error,
            ],
        ];

        $this->sendWsNotification($payload);
    }

    private function sendWsNotification(array $payload): void
    {
        $wsUrl = $_ENV['WS_NOTIFY_URL'] ?? 'http://ws-server:3002/internal/notify';

        try {
            $this->httpClient->request('POST', $wsUrl, [
                'json' => $payload,
                'timeout' => 3,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('ProcessStepCommandHandler: WebSocket notification failed (non-fatal)', [
                'error' => $e->getMessage(),
                'ws_url' => $wsUrl,
            ]);
        }
    }
}
