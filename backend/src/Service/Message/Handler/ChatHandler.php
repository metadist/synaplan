<?php

declare(strict_types=1);

namespace App\Service\Message\Handler;

use App\Entity\Message;
use App\Service\Document\ChatDocumentOutput;
use App\Service\Exception\VisionModelRequiredException;
use App\Service\Memory\PendingMemoryExtraction;
use App\Service\Message\Chat\ChatMessageAssembler;
use App\Service\Message\Chat\ChatModelSelector;
use App\Service\Message\Chat\ChatReplyGenerator;
use App\Service\Message\Chat\ChatRequestOptions;
use App\Service\Message\Chat\ChatSystemPromptAssembler;
use App\Service\Message\Chat\ChatTurnContextLoader;
use App\Service\Message\ProgressCallback;
use App\Service\Message\Routing\RoutingDirective;
use App\Service\Message\Routing\RoutingHandoffResolver;
use App\Service\Usage\FacadeMeteredChat;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The conversation chat: answers a message with the model the person chose,
 * informed by the topic prompt, the knowledge base and their memories.
 *
 * Every turn runs through the same stages:
 *
 *  1. {@see ChatTurnContextLoader}     runtime profile, topic prompt, knowledge base, memories
 *  2. {@see ChatModelSelector}         the answering model; the image model when it cannot see
 *  3. {@see ChatSystemPromptAssembler} the system prompt, section by section
 *  4. {@see ChatMessageAssembler}      thread and current turn as provider messages
 *  5. {@see ChatRequestOptions}        schema, routing tools, token ceiling, reasoning level
 *  6. {@see ChatReplyGenerator}        the model call, and the checks on what came back
 *  7. {@see PendingMemoryExtraction}   the memory job for the finished turn
 *
 * {@see handle()} serves the buffered channels (email, webhook, MCP,
 * Telegram) and returns the whole answer; {@see handleStream()} streams the
 * web chat and the chat widget. Where the two differ — when a routing
 * hand-off is checked, the voice rules, how a generated file is delivered —
 * the stage that owns the difference spells it out.
 */
#[AutoconfigureTag('app.message.handler')]
final readonly class ChatHandler implements MessageHandlerInterface
{
    /** A bare argument-taking slash command, answered with its usage hint. */
    private const SLASH_HINT_METADATA = ['slash_hint' => true, 'provider' => 'none', 'model' => 'none'];

    /** A /docs question the knowledge base could not answer. */
    private const KNOWLEDGE_MISS_METADATA = ['rag_query' => true, 'rag_chunks' => 0, 'provider' => 'none', 'model' => 'none'];

    public function __construct(
        private ChatTurnContextLoader $turns,
        private ChatModelSelector $models,
        private ChatSystemPromptAssembler $systemPrompts,
        private ChatMessageAssembler $messages,
        private ChatRequestOptions $requestOptions,
        private ChatReplyGenerator $replies,
        private ChatDocumentOutput $documents,
        private RoutingHandoffResolver $routing,
        private PendingMemoryExtraction $pendingMemory,
    ) {
    }

    public function getName(): string
    {
        return 'chat';
    }

    /**
     * @param array<int, array{role: string, content: string}|Message> $thread
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options        forwarded by InferenceRouter (channel, disable_memories, …)
     */
    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $hint = $this->turns->slashHint($classification);
        if (null !== $hint) {
            return ['content' => $hint, 'metadata' => self::SLASH_HINT_METADATA];
        }

        ProgressCallback::notify($progressCallback, 'generating', 'Generating response...');

        $turn = $this->turns->forBufferedTurn($message, $thread, $classification, $options, $progressCallback);
        if (null !== $turn->knowledgeMiss) {
            return ['content' => $turn->knowledgeMiss, 'metadata' => self::KNOWLEDGE_MISS_METADATA];
        }

        $model = $this->models->forBufferedTurn($turn);

        // Phase 9: the classifier deferred the routing decision to this call.
        // Checked after the vision swap, which can pick a model that cannot
        // call tools: tools built for the original model would be dropped and
        // the hand-off would silently never happen. Still before any prompt
        // is built, so an unhonourable deferral costs nothing but a re-route.
        $routingDeferred = RoutingHandoffResolver::isDeferred($turn->classification);
        $routingTools = $routingDeferred ? $this->routing->tools($model->provider, $model->modelName, false) : null;
        if ($routingDeferred && null === $routingTools) {
            return RoutingDirective::reclassify()->toHandlerResult();
        }

        $options['include_generated_images'] = $model->includeGeneratedImages;
        $prompt = $this->systemPrompts->forBufferedTurn($turn, $model, $options, $progressCallback);
        if ($model->hasImages && !$model->includeImages) {
            throw new VisionModelRequiredException();
        }

        $options = $prompt->options;
        $messages = $this->messages->forBufferedTurn($turn, $model, $prompt);
        $aiOptions = $this->requestOptions->forBufferedTurn($turn, $model, $options, $routingTools);
        $reply = $this->replies->complete($turn, $model, $messages, $aiOptions, $options, $progressCallback);

        // Only the plain chat call can carry a hand-off: the document tool
        // loop answers the officemaker topic, which is never deferred. Text
        // the model wrote alongside the call is a preamble, not an answer.
        $handoff = $routingDeferred ? $this->routing->directiveFor($reply->toolCalls) : null;
        if (null !== $handoff) {
            return $handoff->toHandlerResult();
        }

        ProgressCallback::notify($progressCallback, 'generating', 'Response generated.');

        [$content, $metadata] = $this->replies->settle($reply, $turn);
        [$content, $metadata] = $this->documents->deliverBufferedReply($content, $metadata, $message, $turn->topic(), $turn->classification, !empty($options['incognito']));

        // The memory job reads the final text — after the JSON unwrap and the
        // file marker — so it reasons about what the person saw (#615).
        $deferredExtraction = $this->pendingMemory->afterTurn($message, is_string($content) ? $content : '', $thread, $turn->personal->memories, $options);

        return [
            'content' => $content,
            'metadata' => array_merge(
                $metadata,
                ['model_id' => $model->modelId],
                $turn->contextMetadata($prompt->docs),
                ['extraction_payload' => $deferredExtraction],
            ),
        ];
    }

    /**
     * @param array<int, array{role: string, content: string}|Message> $thread
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options        forwarded by StreamController (perf timer, resolved prompt, quoted excerpt, …)
     */
    public function handleStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $hint = $this->turns->slashHint($classification);
        if (null !== $hint) {
            $streamCallback($hint);

            return ['metadata' => self::SLASH_HINT_METADATA];
        }

        ProgressCallback::notify($progressCallback, 'analyzing_prompt', 'Analyzing the prompt...');

        $turn = $this->turns->forStreamedTurn($message, $thread, $classification, $options, $progressCallback);
        if (null !== $turn->knowledgeMiss) {
            $streamCallback($turn->knowledgeMiss);

            return ['metadata' => self::KNOWLEDGE_MISS_METADATA];
        }

        $model = $this->models->forStreamedTurn($turn);
        $options['include_generated_images'] = $model->includeGeneratedImages;
        $prompt = $this->systemPrompts->forStreamedTurn($turn, $model, $options, $progressCallback);
        $options = $prompt->options;
        $options['include_images'] = $model->includeImages;
        if ($model->hasImages && !$model->includeImages) {
            throw new VisionModelRequiredException();
        }

        // Phase 9, see handle(). The deferral has to be honoured for the
        // STREAMING capability: a provider that returns tool calls in a plain
        // response but not in its SSE stream would swallow the hand-off.
        $routingDeferred = RoutingHandoffResolver::isDeferred($turn->classification);
        $routingTools = $routingDeferred ? $this->routing->tools($model->provider, $model->modelName, true) : null;
        if ($routingDeferred && null === $routingTools) {
            return RoutingDirective::reclassify()->toHandlerResult();
        }

        [$messages, $options] = $this->messages->forStreamedTurn($turn, $model, $prompt, $options);
        $aiOptions = $this->requestOptions->forStreamedTurn($turn, $model, $options, $routingTools);
        $reply = $this->replies->stream($turn, $model, $messages, $aiOptions, $streamCallback, !empty($options['incognito']), $progressCallback);

        // Checked before the answer is: a hand-off turn legitimately streams
        // no visible token, and calling that an empty response would turn a
        // successful routing decision into a provider error.
        $handoff = $routingDeferred ? $this->routing->directiveFor($reply->toolCalls) : null;
        if (null !== $handoff) {
            return $handoff->toHandlerResult();
        }

        $this->replies->assertStreamedAnswer($reply, $turn, $model);

        // A distinct status: announcing `generating` again after the last
        // token made the client show the spinner below the finished answer.
        ProgressCallback::notify($progressCallback, 'generated', 'Response generated.');

        // The memory job runs on the worker, so the stream can send `complete`
        // right after the answer. StreamController defers it until the
        // outgoing message is stored and fires the returned payload (#881).
        $deferredExtraction = $this->pendingMemory->afterTurn($message, (string) $reply->content, $thread, $turn->personal->memories, $options);

        return [
            'metadata' => FacadeMeteredChat::stamp([
                'provider' => $reply->metadata['provider'] ?? 'unknown',
                'model' => $reply->metadata['model'] ?? 'unknown',
                'model_id' => $model->modelId,
                'usage' => $reply->metadata['usage'] ?? [],
                'response_id' => $reply->metadata['response_id'] ?? null,
                'generated_file' => $reply->metadata['generated_file'] ?? null,
                'documentChanges' => $reply->metadata['documentChanges'] ?? null,
                'documentVersion' => $reply->metadata['documentVersion'] ?? null,
                'documentFidelityLossy' => $reply->metadata['documentFidelityLossy'] ?? false,
            ], $reply->metadata) + $turn->contextMetadata($prompt->docs) + ['extraction_payload' => $deferredExtraction],
        ];
    }
}
