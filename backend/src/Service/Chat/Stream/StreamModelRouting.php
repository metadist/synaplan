<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\Prompt\PromptService;
use Psr\Log\LoggerInterface;

/**
 * Which chat model a streamed turn expects, and whether it can stream.
 *
 * The decision is made before classification, so it predicts the pick of
 * {@see \App\Service\Message\Chat\ChatModelSelector}: the requested model
 * ("Again" or override), the `aiModel` of a fixed task prompt, then the
 * person's default. Models without streaming (e.g. o1) take the buffered
 * path.
 */
final readonly class StreamModelRouting
{
    public function __construct(
        private ModelConfigService $modelConfigService,
        private PromptService $promptService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The model the person intended: the requested one, else the default
     * chat model. An error reply shows it instead of "error".
     *
     * @return array{id: ?int, name: ?string, provider: ?string}
     */
    public function intendedChat(?int $modelId, User $user): array
    {
        $id = $modelId
            ?? $this->modelConfigService->getDefaultModel('chat', $user->getId())
            ?? $this->modelConfigService->getDefaultModel('chat', 0);

        if (!$id) {
            return ['id' => null, 'name' => null, 'provider' => null];
        }

        return [
            'id' => $id,
            'name' => $this->modelConfigService->getModelName($id),
            'provider' => $this->modelConfigService->getProviderForModel($id),
        ];
    }

    /**
     * The model the turn will run on, as far as it can be known up front.
     *
     * @param array<string, mixed>                              $processingOptions
     * @param array{id: ?int, name: ?string, provider: ?string} $intendedChat
     */
    public function expectedModelId(StreamTurnRequest $turn, array $processingOptions, array $intendedChat): ?int
    {
        $explicitModelId = $turn->options->modelId ? (int) $turn->options->modelId : null;
        if (null !== $explicitModelId && $explicitModelId > 0) {
            return $explicitModelId;
        }

        $fixedTaskPromptTopic = $processingOptions['fixed_task_prompt'] ?? null;
        if (is_string($fixedTaskPromptTopic) && '' !== $fixedTaskPromptTopic) {
            $promptModelId = $this->promptModelId($fixedTaskPromptTopic, (int) $turn->caller->user->getId());
            if (null !== $promptModelId) {
                return $promptModelId;
            }
        }

        $defaultChatModelId = $intendedChat['id'] ?? null;

        return is_int($defaultChatModelId) && $defaultChatModelId > 0 ? $defaultChatModelId : null;
    }

    public function supportsStreaming(?int $modelId): bool
    {
        if (!$modelId) {
            return true;
        }

        $supportsStreaming = $this->modelConfigService->supportsStreaming($modelId);
        $this->logger->debug('StreamController: Resolved streaming support', [
            'model_id' => $modelId,
            'supports_streaming' => $supportsStreaming,
        ]);

        return $supportsStreaming;
    }

    /**
     * Best effort: an unresolvable prompt falls through to the default.
     */
    private function promptModelId(string $topic, int $userId): ?int
    {
        try {
            $promptData = $this->promptService->getPromptWithMetadata($topic, $userId);
            $promptModelId = (int) ($promptData['metadata']['aiModel'] ?? 0);

            return $promptModelId > 0 ? $promptModelId : null;
        } catch (\Throwable $e) {
            $this->logger->debug('StreamController: Could not pre-resolve prompt model for streaming routing', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
