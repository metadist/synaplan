<?php

namespace App\Service\Message\Classification;

use App\Entity\Message;
use App\Repository\MessageMetaRepository;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\Routing\RoutingDecision;
use App\Service\Message\Routing\RoutingLayer;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * Again: the prompt or the model a user picked for a message (BMESSAGEMETA
 * PROMPTID, MODEL_ID). A picked model sets the topic by its tag through the
 * one topic and intent rule; a picked prompt is the topic, with the picked
 * model when there is one. The sorter's own prompt id is no pick.
 */
final readonly class AgainOverride
{
    public function __construct(
        private MessageMetaRepository $messageMetaRepository,
        private ModelConfigService $modelConfigService,
        private TopicIntentRule $topicIntents,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>|null the classification, or null without a pick
     */
    public function route(Message $message): ?array
    {
        // 1. Check for "Again" function - user-selected AI/prompt
        $promptOverride = $this->checkPromptOverride($message->getId());
        $modelOverride = $this->checkModelOverride($message->getId());

        if ($modelOverride && !$promptOverride) {
            // "Again" with model but no prompt override
            // Detect model type from model tag and set topic accordingly
            $modelTag = $this->modelConfigService->getModelTag($modelOverride) ?? 'chat';
            $routed = $this->topicIntents->forModel($modelTag, $message->getTopic());

            $this->logger->info('MessageClassifier: Using model override with auto-detected topic', [
                'message_id' => $message->getId(),
                'model_id' => $modelOverride,
                'model_tag' => $modelTag,
                'detected_topic' => $routed->topic,
                'intent' => $routed->intent,
            ]);

            $modelOverrideDecision = RoutingDecision::deterministic(RoutingLayer::ModelOverride, $routed->topic);

            return array_merge([
                'topic' => $routed->topic,
                'language' => $message->getLanguage() ?: 'en',
                'intent' => $routed->intent,
                'source' => $modelOverrideDecision->toClassificationSource(),
                'skip_sorting' => true,
                'model_id' => $routed->usesPickedModel ? $modelOverride : null,
            ], $modelOverrideDecision->toClassificationFields());
        }

        if ($promptOverride) {
            $this->logger->info('MessageClassifier: Using prompt override (Again function)', [
                'message_id' => $message->getId(),
                'prompt_id' => $promptOverride,
                'model_id' => $modelOverride,
            ]);

            $promptOverrideDecision = RoutingDecision::deterministic(RoutingLayer::PromptOverride, $promptOverride);

            $result = array_merge([
                'topic' => $promptOverride,
                'language' => $message->getLanguage() ?: 'en',
                'intent' => $this->topicIntents->intentFor($promptOverride),
                'source' => $promptOverrideDecision->toClassificationSource(),
                'skip_sorting' => true,
            ], $promptOverrideDecision->toClassificationFields());

            // Add model_id if user explicitly selected a model (Again)
            if ($modelOverride) {
                $result['model_id'] = $modelOverride;
            }

            return $result;
        }

        return null;
    }

    /**
     * Check for prompt override (Again function)
     * Returns prompt ID if set, null otherwise.
     */
    private function checkPromptOverride(?int $messageId): ?string
    {
        $promptId = $this->againOverride($messageId, 'PROMPTID');

        return 'tools:sort' === $promptId ? null : $promptId;
    }

    /**
     * Check for model override (Again function with specific model)
     * Returns model ID if set, null otherwise.
     */
    private function checkModelOverride(?int $messageId): ?int
    {
        $modelId = $this->againOverride($messageId, 'MODEL_ID');

        return null === $modelId ? null : (int) $modelId;
    }

    /**
     * The non-empty meta an Again request stored on the message. Transient
     * (incognito) messages are never persisted, so they carry no override.
     */
    private function againOverride(?int $messageId, string $metaKey): ?string
    {
        if (null === $messageId) {
            return null;
        }

        $value = $this->messageMetaRepository->findOneBy([
            'messageId' => $messageId,
            'metaKey' => $metaKey,
        ])?->getMetaValue();

        return empty($value) ? null : $value;
    }
}
