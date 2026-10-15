<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

use App\Entity\Message;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use App\Service\Runtime\RuntimeProfile;
use App\Service\Vision\VisionAttachmentEncoder;
use Psr\Log\LoggerInterface;

/**
 * Which model answers a chat turn.
 *
 * Priority: the model "Again" replays, a widget's configured model, the
 * assistant's pinned model, the topic prompt's model, then the person's
 * default. A retired model is swapped for its successor. When the turn
 * carries images and that model cannot see, the configured image model
 * answers instead.
 */
final readonly class ChatModelSelector
{
    public function __construct(
        private ModelConfigService $modelConfigService,
        private ModelRepository $modelRepository,
        private VisionAttachmentEncoder $vision,
        private LoggerInterface $logger,
    ) {
    }

    public function forBufferedTurn(ChatTurn $turn): ChatModelChoice
    {
        $message = $turn->message;
        $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
        $modelId = $this->degradeToUsableModel($this->configuredModelId($turn, $effectiveUserId), $effectiveUserId, $message);
        $provider = null;
        $modelName = null;

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);

            $this->logger->info('ChatHandler: Resolved model', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName,
            ]);
        }

        $hasImages = $this->vision->hasAttachedImages($message);
        $includeImages = false;

        if ($hasImages) {
            $currentModel = $modelId ? $this->modelRepository->find($modelId) : null;
            $supportsVision = $currentModel && $currentModel->hasFeature('vision');

            if ($supportsVision) {
                $includeImages = true;
                $this->logger->info('ChatHandler: Current model supports vision, including images (non-streaming)');
            } else {
                $this->logger->info('ChatHandler: Message has images but model does not support vision, resolving configured vision model (non-streaming)');

                // Prefer the account's configured image model (PIC2TEXT); only
                // fall back to the global catalog pick when none is usable.
                $visionModel = $this->vision->fallbackModel($effectiveUserId);

                if ($visionModel) {
                    $modelId = $visionModel->getId();
                    $provider = strtolower($visionModel->getService());
                    $modelName = $visionModel->getProviderId();
                    $includeImages = true;

                    $this->logger->info('ChatHandler: Switched to vision-capable model (non-streaming)', [
                        'model_id' => $modelId,
                        'provider' => $provider,
                        'model' => $modelName,
                        'model_name' => $visionModel->getName(),
                    ]);
                } else {
                    $this->logger->warning('ChatHandler: No vision-capable model found, images will not be analyzed (non-streaming)');
                }
            }
        }

        return new ChatModelChoice(
            modelId: $modelId,
            provider: $provider,
            modelName: $modelName,
            effectiveUserId: $effectiveUserId,
            hasImages: $hasImages,
            includeImages: $includeImages,
            includeGeneratedImages: $this->vision->shouldIncludeGeneratedImages($modelId, $effectiveUserId),
            model: $modelId ? $this->modelRepository->find($modelId) : null,
        );
    }

    public function forStreamedTurn(ChatTurn $turn): ChatModelChoice
    {
        $message = $turn->message;
        $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
        $modelId = $this->degradeToUsableModel($this->configuredModelId($turn, $effectiveUserId), $effectiveUserId, $message);

        $hasImages = $this->vision->hasAttachedImages($message);
        $includeImages = false;

        if ($hasImages) {
            $currentModel = $modelId ? $this->modelRepository->find($modelId) : null;
            $supportsVision = $currentModel && $currentModel->hasFeature('vision');

            if ($supportsVision) {
                $includeImages = true;
                $this->logger->info('ChatHandler: Current model supports vision, including images (streaming)');
            } else {
                $this->logger->info('ChatHandler: Message has images but model does not support vision, resolving configured vision model');

                // Prefer the account's configured image model (PIC2TEXT); only
                // fall back to the global catalog pick when none is usable.
                $visionModel = $this->vision->fallbackModel($effectiveUserId);

                if ($visionModel) {
                    $modelId = $visionModel->getId();
                    $includeImages = true;

                    $this->logger->info('ChatHandler: Switched to vision-capable model for streaming', [
                        'model_id' => $modelId,
                        'provider' => strtolower($visionModel->getService()),
                        'model' => $visionModel->getProviderId(),
                        'model_name' => $visionModel->getName(),
                    ]);
                } else {
                    $this->logger->warning('ChatHandler: No vision-capable model found, images will not be analyzed');
                }
            }
        }

        $includeGeneratedImages = $this->vision->shouldIncludeGeneratedImages($modelId, $effectiveUserId);

        $provider = null;
        $modelName = null;
        $model = null;
        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
            $model = $this->modelRepository->find($modelId);

            $this->logger->info('ChatHandler: Resolved model for streaming', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName,
                'features' => $model?->getFeatures() ?? [],
            ]);
        }

        return new ChatModelChoice(
            modelId: $modelId,
            provider: $provider,
            modelName: $modelName,
            effectiveUserId: $effectiveUserId,
            hasImages: $hasImages,
            includeImages: $includeImages,
            includeGeneratedImages: $includeGeneratedImages,
            model: $model,
        );
    }

    /**
     * The model the turn asks for, before the usability check.
     */
    private function configuredModelId(ChatTurn $turn, ?int $effectiveUserId): ?int
    {
        $classification = $turn->classification;
        $profile = $turn->profile;
        $userId = $turn->message->getUserId();

        if (isset($classification['model_id']) && $classification['model_id']) {
            $modelId = (int) $classification['model_id'];
            $this->logger->info('ChatHandler: Using user-selected model (Again)', [
                'model_id' => $modelId,
                'user_id' => $userId,
            ]);

            return $modelId;
        }

        if (isset($classification['override_model_id']) && (int) $classification['override_model_id'] > 0) {
            $modelId = (int) $classification['override_model_id'];
            $this->logger->info('ChatHandler: Using widget config model override', [
                'model_id' => $modelId,
                'user_id' => $userId,
            ]);

            return $modelId;
        }

        if ($profile instanceof RuntimeProfile && isset($profile->modelIds['chat']) && $profile->modelIds['chat']) {
            $modelId = (int) $profile->modelIds['chat'];
            $this->logger->info('ChatHandler: Using runtime profile chat model', [
                'model_id' => $modelId,
                'user_id' => $userId,
            ]);

            return $modelId;
        }

        if (isset($turn->promptMetadata()['aiModel']) && (int) $turn->promptMetadata()['aiModel'] > 0) {
            $modelId = (int) $turn->promptMetadata()['aiModel'];
            $this->logger->info('ChatHandler: Using prompt metadata model', [
                'model_id' => $modelId,
                'topic' => $turn->topic(),
                'user_id' => $userId,
            ]);

            return $modelId;
        }

        $modelId = $this->modelConfigService->getDefaultModel('CHAT', $effectiveUserId);
        $this->logger->info('ChatHandler: Using DB default model', [
            'model_id' => $modelId,
            'user_id' => $userId,
            'effective_user_id' => $effectiveUserId,
        ]);

        return $modelId;
    }

    /**
     * Swap a model that can no longer serve for its recorded successor, or the
     * account default when the retirement recorded none.
     *
     * The branches above the account default all read a model id stored
     * elsewhere and earlier — a widget's `aiModelId`, a prompt's `aiModel`,
     * the model an older message was answered with when "Again" replays it.
     * None of them is rewritten when a model is retired, so the stored copy
     * stays on the dead id. {@see ModelConfigService::resolveUsableModelId()}
     * follows BSUCCESSORID first; the capability default is the fallback for
     * `successor: null`.
     */
    private function degradeToUsableModel(?int $modelId, ?int $effectiveUserId, Message $message): ?int
    {
        $usableModelId = $this->modelConfigService->resolveUsableModelId($modelId, 'CHAT', $effectiveUserId);

        if ($usableModelId !== $modelId) {
            $this->logger->warning('ChatHandler: Configured model is no longer usable, falling back', [
                'configured_model_id' => $modelId,
                'model_id' => $usableModelId,
                'user_id' => $message->getUserId(),
                'effective_user_id' => $effectiveUserId,
            ]);
        }

        return $usableModelId;
    }
}
