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
            ['role' => 'system', 'content' => $this->systemPrompt($node, $language, $context)],
            ['role' => 'user', 'content' => $text],
        ];

        // Stream tokens into the node's card (task_chunk) while accumulating the
        // full text so dependent nodes + assembly still receive the complete output.
        $full = '';
        try {
            $response = $this->aiFacade->chatStream(
                $messages,
                static function ($chunk) use (&$full, $context): void {
                    $piece = is_array($chunk) ? (string) ($chunk['content'] ?? '') : (string) $chunk;
                    if ('' === $piece) {
                        return;
                    }
                    $full .= $piece;
                    $context->streamChunk($piece);
                },
                $context->userId,
                array_filter([
                    'provider' => $provider,
                    'model' => $modelName,
                    'temperature' => 0.3,
                ], static fn ($v) => null !== $v),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('ChatRunner: model call failed', [
                'capability' => $node->capability->value,
                'error' => $e->getMessage(),
            ]);

            return NodeResult::failed($node->capability->value.' failed: '.$e->getMessage());
        }

        if ('' === trim($full)) {
            return NodeResult::failed($node->capability->value.' produced empty output');
        }

        return NodeResult::ok($full, [], [
            'provider' => $response['provider'] ?? $provider,
            'model' => $response['model'] ?? $modelName,
            'model_id' => $modelId,
        ]);
    }

    private function systemPrompt(TaskNode $node, string $language, NodeContext $context): string
    {
        $base = match ($node->capability) {
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

        return $base.$this->pipelineDirective($context);
    }

    /**
     * Hard guardrail appended to EVERY chat-node prompt.
     *
     * A ChatRunner node only ever executes as one step of a multi-node task
     * plan (single-node chats take the legacy ChatHandler path). The node
     * frequently receives the user's FULL request — e.g. "write a love poem
     * AND read it to me as an MP3" — while a sibling node (text2sound,
     * image_generation, document_generation, …) actually produces and
     * delivers the file. Without this directive the chat model "helpfully"
     * apologises for the part it cannot do ("Es tut mir leid, ich kann keine
     * Audiodateien erstellen …"), even though the MP3 is generated right next
     * to it. The directive tells the model to own ONLY its slice and never
     * disclaim capabilities handled elsewhere. It is deterministic — it does
     * not rely on the planner having stripped the sibling clauses.
     */
    private function pipelineDirective(NodeContext $context): string
    {
        $directive = "\n\nYou are ONE automated step in a larger multi-step pipeline that together fulfils the user's request."
            ."\nProduce ONLY your own part: the requested text content."
            .' Other automated steps create and deliver any files, audio, images, videos, documents or messages.'
            .' NEVER claim you cannot create or provide audio, images, files, videos or documents.'
            .' NEVER apologise, add disclaimers, or comment on your own capabilities or limitations.'
            .' NEVER mention these other steps or that the request was split.'
            .' Output only the requested content — no preamble, no closing remarks, no meta-commentary.';

        $siblings = $context->planCapabilities;
        $hints = [];
        if (in_array(Capability::Text2Sound->value, $siblings, true)) {
            $hints[] = 'A later step will read your text aloud as audio, so write natural, speakable prose.';
        }
        if (in_array(Capability::ImageGeneration->value, $siblings, true)
            || in_array(Capability::VideoGeneration->value, $siblings, true)) {
            $hints[] = 'A separate step generates the requested image/video; do not describe it as if you produced it.';
        }
        if (in_array(Capability::DocumentGeneration->value, $siblings, true)) {
            $hints[] = 'A separate step builds the requested document file from your text.';
        }

        if ([] !== $hints) {
            $directive .= "\n".implode(' ', $hints);
        }

        return $directive;
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
