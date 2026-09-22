<?php

namespace App\Service\Message;

use App\Entity\Message;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Exception\UnmappedIntentException;
use App\Service\Message\Routing\RoutingDecision;
use App\Service\Message\Routing\RoutingDirective;
use App\Service\Message\Routing\RoutingLayer;
use App\Service\Multitask\Plan\Capability;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Router for Message Processing based on Intent/BTAG.
 *
 * Dispatched to the handlers registered under `app.message.handler`:
 * - chat (ChatHandler — plain chat and officemaker)
 * - image_generation (MediaGenerationHandler — images, video, audio)
 * - file_analysis (FileAnalysisHandler)
 *
 * Every other {@see Capability} is executed by a TaskRunner. Those intents
 * throw {@see UnmappedIntentException} instead of falling through to chat.
 */
final class InferenceRouter
{
    private array $handlers = [];

    public function __construct(
        #[AutowireIterator('app.message.handler')]
        iterable $handlers,
        private LoggerInterface $logger,
        private SystemCapabilityRegistry $capabilityRegistry,
        private MessageClassifier $classifier,
    ) {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->getName()] = $handler;
        }
    }

    /**
     * Routed Message zu richtigem Handler.
     *
     * Mirrors {@see routeStream()}'s `$options` parameter so the non-streaming
     * email/generic-webhook path can forward channel / disable-memories /
     * reasoning flags down to the handler. Existing callers that pass four
     * args keep working unchanged because `$options` defaults to `[]`.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     */
    public function route(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $intent = $classification['intent'] ?? 'chat';

        $this->notify($progressCallback, 'processing', "Routing to handler: {$intent}");

        // Find the handler for this intent
        $handler = $this->getHandler($intent);

        try {
            $result = $handler->handle($message, $thread, $classification, $progressCallback, $options);

            $directive = RoutingDirective::fromHandlerResult($result);
            if (null !== $directive) {
                $rerouted = $this->applyDirective($directive, $message, $thread, $classification, $progressCallback);

                return $this->getHandler($rerouted['intent'] ?? 'chat')
                    ->handle($message, $thread, $rerouted, $progressCallback, $options);
            }

            $this->notify($progressCallback, 'processing', "Handler complete: {$intent}");

            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Handler failed: {$e->getMessage()}");

            if ($this->shouldFallbackToChat($intent)) {
                $this->notify($progressCallback, 'processing', 'Falling back to chat handler');

                return $this->handlers['chat']->handle($message, $thread, $classification, $progressCallback, $options);
            }

            throw $e;
        }
    }

    /**
     * Routed Message zu richtigem Handler mit Streaming-Support.
     */
    public function routeStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $intent = $classification['intent'] ?? 'chat';

        $this->logger->info('InferenceRouter: Routing to handler', [
            'intent' => $intent,
            'topic' => $classification['topic'] ?? 'unknown',
            'classification' => $classification,
            'available_handlers' => array_keys($this->handlers),
        ]);

        $this->notify($progressCallback, 'processing', "Routing to handler: {$intent}");

        // Find the handler for this intent
        $handler = $this->getHandler($intent);

        $this->logger->info('InferenceRouter: Handler resolved', [
            'handler_name' => $handler->getName(),
            'handler_class' => get_class($handler),
            'intent' => $intent,
        ]);

        try {
            $result = $handler->handleStream($message, $thread, $classification, $streamCallback, $progressCallback, $options);

            $directive = RoutingDirective::fromHandlerResult($result);
            if (null !== $directive) {
                $rerouted = $this->applyDirective($directive, $message, $thread, $classification, $progressCallback);

                return $this->getHandler($rerouted['intent'] ?? 'chat')
                    ->handleStream($message, $thread, $rerouted, $streamCallback, $progressCallback, $options);
            }

            $this->notify($progressCallback, 'processing', "Handler complete: {$intent}");

            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Handler streaming failed: {$e->getMessage()}");

            if ($this->shouldFallbackToChat($intent)) {
                $this->notify($progressCallback, 'processing', 'Falling back to chat handler');

                return $this->handlers['chat']->handleStream($message, $thread, $classification, $streamCallback, $progressCallback, $options);
            }

            throw $e;
        }
    }

    /**
     * Turn a handler's {@see RoutingDirective} into the classification for the
     * second, final dispatch of this turn.
     *
     * Only the Phase 9 native tool-calling path produces a directive, and only
     * on a turn whose classification carries `defer_routing_to_chat`. The
     * result never does, which is what bounds this to exactly one re-route: a
     * handler that is not asked to decide the routing cannot ask for a
     * re-route.
     *
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private function applyDirective(
        RoutingDirective $directive,
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback,
    ): array {
        unset($classification['defer_routing_to_chat']);

        if (RoutingDirective::TYPE_RECLASSIFY === $directive->type) {
            $this->logger->info('InferenceRouter: Routing deferral could not be honoured — reclassifying via the AI sorter');

            $this->notify($progressCallback, 'processing', 'Classifying request...');

            // Transient keys the pipeline attached earlier (search results,
            // fetched URL content, prompt metadata, widget model override) are
            // NOT part of a classification and would be lost by taking the
            // fresh result wholesale — hence merge, fresh result winning.
            return array_merge(
                $classification,
                $this->classifier->classify($message, $thread, null, allowRoutingDeferral: false),
            );
        }

        $topic = (string) $directive->topic;
        $capability = $this->capabilityRegistry->byTopic($topic);
        // Only a registered topic can be handed off (RoutingToolset builds the
        // tools from the same register and rejects anything else), so an
        // unknown one here would be a bug, not user input — degrade to chat.
        $intent = null !== $capability ? $capability->intent : 'chat';
        $decision = RoutingDecision::deterministic(RoutingLayer::NativeToolHandoff, $topic);

        $this->logger->info('InferenceRouter: Re-routing after native tool hand-off', [
            'topic' => $topic,
            'intent' => $intent,
            'fields' => $directive->fields,
        ]);

        $this->notify($progressCallback, 'processing', "Routing to handler: {$topic}");

        return array_merge(
            $classification,
            [
                'topic' => $topic,
                'intent' => $intent,
                'source' => $decision->toClassificationSource(),
                'skip_sorting' => true,
            ],
            // media_type / input_mode / resolution when the model supplied
            // them — already validated against the register.
            $directive->fields,
            $decision->toClassificationFields(),
        );
    }

    /**
     * Chat is the generic fallback when a dedicated handler is missing or
     * throws. File analysis must not take that path: a provider/disk failure
     * would otherwise become a second successful chat turn (issue #1914).
     */
    private function shouldFallbackToChat(string $intent): bool
    {
        return 'chat' !== $intent && 'file_analysis' !== $intent;
    }

    private function getHandler(string $intent): object
    {
        // Exhaustive over Capability, with no default arm and no chat fallback.
        // An unknown string, a planner-only capability, or a handler that is
        // not registered fails here — before the route() catch that still
        // falls back to chat when a dispatched handler throws (#1915).
        $capability = Capability::tryFrom($intent);
        if (null === $capability) {
            throw new UnmappedIntentException($intent);
        }

        $handlerName = $capability->messageHandlerName();
        if (null === $handlerName || !isset($this->handlers[$handlerName])) {
            throw new UnmappedIntentException($intent);
        }

        return $this->handlers[$handlerName];
    }

    private function notify(?callable $callback, string $status, string $message): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'timestamp' => time(),
            ]);
        }
    }
}
