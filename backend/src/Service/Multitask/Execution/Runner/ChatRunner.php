<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\AI\Service\AiFacade;
use App\Service\ModelConfigService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Psr\Log\LoggerInterface;

/**
 * Text-capability runner for `chat`, `summarize`, `translate`, `rag_query`.
 *
 * Resolves the model via the existing capability→DEFAULTMODEL chain (SUMMARIZE
 * for summarize, CHAT otherwise) keyed by the effective user id — the migration
 * principle: the planner picks the task, the model resolution stays here. It
 * runs the transform through AiFacade::chat on the upstream text input.
 *
 * NOTE (Sprint 3b): a multi-node `chat` node uses a generic system prompt. Full
 * custom-topic (params.topic_id → PromptMeta) binding for INTERMEDIATE nodes is
 * a later refinement; single-node custom topics already work via the Sprint 2
 * path. RAG retrieval for `rag_query` intermediate nodes is also deferred.
 */
final readonly class ChatRunner implements TaskRunner
{
    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::Chat, Capability::Summarize, Capability::Translate, Capability::RagQuery];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $inputs = $context->resolveInputs($node);
        $text = $this->stringInput($inputs['text'] ?? null) ?? (string) $context->message->getText();

        if ('' === trim($text)) {
            return NodeResult::failed('no input text for '.$node->capability->value);
        }

        $language = is_string($context->classification['language'] ?? null) ? $context->classification['language'] : ($context->message->getLanguage() ?: 'en');
        $capabilityTag = Capability::Summarize === $node->capability ? 'SUMMARIZE' : 'CHAT';
        $modelId = $this->modelConfigService->getDefaultModel($capabilityTag, $context->userId)
            ?? $this->modelConfigService->getDefaultModel('CHAT', $context->userId);
        $provider = $modelId ? $this->modelConfigService->getProviderForModel($modelId) : null;
        $modelName = $modelId ? $this->modelConfigService->getModelName($modelId) : null;

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($node, $language)],
            ['role' => 'user', 'content' => $text],
        ];

        try {
            $response = $this->aiFacade->chat($messages, $context->userId, array_filter([
                'provider' => $provider,
                'model' => $modelName,
                'temperature' => 0.3,
            ], static fn ($v) => null !== $v));
        } catch (\Throwable $e) {
            $this->logger->warning('ChatRunner: model call failed', [
                'capability' => $node->capability->value,
                'error' => $e->getMessage(),
            ]);

            return NodeResult::failed($node->capability->value.' failed: '.$e->getMessage());
        }

        $content = (string) ($response['content'] ?? '');
        if ('' === trim($content)) {
            return NodeResult::failed($node->capability->value.' produced empty output');
        }

        return NodeResult::ok($content, [], [
            'provider' => $response['provider'] ?? $provider,
            'model' => $response['model'] ?? $modelName,
            'model_id' => $modelId,
        ]);
    }

    private function systemPrompt(TaskNode $node, string $language): string
    {
        return match ($node->capability) {
            Capability::Summarize => sprintf(
                'You are a precise summarizer. Summarize the user text concisely%s in language "%s". Return ONLY the summary, no preamble.',
                isset($node->params['max_words']) && is_numeric($node->params['max_words']) ? ' in about '.(int) $node->params['max_words'].' words' : '',
                $language,
            ),
            Capability::Translate => sprintf(
                'Translate the user text into "%s". Return ONLY the translation.',
                is_string($node->params['target'] ?? null) ? $node->params['target'] : $language,
            ),
            default => sprintf('You are a helpful assistant. Answer in language "%s".', $language),
        };
    }

    private function stringInput(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            // A list of strings (e.g. multiple upstream texts) → join.
            $parts = array_filter($value, 'is_string');

            return [] === $parts ? null : implode("\n\n", $parts);
        }

        return null;
    }
}
