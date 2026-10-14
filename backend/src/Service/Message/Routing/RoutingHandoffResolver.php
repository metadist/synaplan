<?php

declare(strict_types=1);

namespace App\Service\Message\Routing;

use App\AI\ToolCalling\ToolCallingTranslator;
use App\AI\ToolCalling\ToolCallParser;
use App\Entity\Model;
use Psr\Log\LoggerInterface;

/**
 * The native tool-calling hand-off (Phase 9): when the classifier left
 * the routing decision to the answering call, which hand-off tools that
 * call declares, and which directive a returned tool call means.
 */
final readonly class RoutingHandoffResolver
{
    public function __construct(
        private ToolCallingTranslator $toolCallingTranslator,
        private ToolCallParser $toolCallParser,
        private RoutingToolset $routingToolset,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether the classifier handed this turn's routing decision to the
     * answering call (Phase 9, {@see NativeToolRoutingConfig}).
     *
     * @param array<string, mixed> $classification
     */
    public static function isDeferred(array $classification): bool
    {
        return !empty($classification['defer_routing_to_chat']);
    }

    /**
     * The request options that declare the hand-off tools on the answering
     * call, or null when this turn's model cannot do native tool calling and
     * the deferral therefore has to go back to the sorter.
     *
     * The classifier pre-checks the ACCOUNT default chat provider before it
     * defers, but the model actually used here can differ (widget override,
     * prompt binding, "Again" replay), so this second check is the
     * authoritative one.
     *
     * @return array<string, mixed>|null
     */
    public function tools(?string $provider, ?string $modelName, bool $streaming): ?array
    {
        if (null === $provider || '' === $provider) {
            return null;
        }

        $declaration = $this->toolCallingTranslator->translate(
            $provider,
            $modelName,
            $streaming,
            $this->routingToolset->build(),
        );

        if ([] === $declaration) {
            $this->logger->info('ChatHandler: Routing deferral not honourable by this model — falling back to the AI sorter', [
                'provider' => $provider,
                'model' => $modelName,
                'streaming' => $streaming,
            ]);

            return null;
        }

        return $declaration;
    }

    /**
     * Turn the model's tool calls into a hand-off directive, or null when it
     * called nothing the register knows — which is the ordinary, common case
     * and means "this really was a chat turn".
     *
     * @param mixed $toolCalls the `tool_calls` entry of the provider response
     */
    public function directiveFor(mixed $toolCalls): ?RoutingDirective
    {
        foreach ($this->toolCallParser->fromWireToolCalls($toolCalls) as $call) {
            $topic = $this->routingToolset->topicForToolCall($call);
            if (null === $topic) {
                // A hallucinated tool name is not a reason to fail the turn:
                // the answer text is still there, so treat it as no hand-off.
                $this->logger->warning('ChatHandler: Model called an unknown routing tool — ignoring', [
                    'tool_name' => $call->name,
                ]);

                continue;
            }

            $fields = $this->routingToolset->classificationFieldsFor($topic, $call);

            $this->logger->info('ChatHandler: Native routing hand-off', [
                'topic' => $topic,
                'fields' => $fields,
            ]);

            // First recognised call wins. Parallel tool calls would mean the
            // model wants two different backends for one turn; that is the
            // multi-step case, which belongs to the DAG planner, not here.
            return RoutingDirective::handoff($topic, $fields);
        }

        return null;
    }
}
