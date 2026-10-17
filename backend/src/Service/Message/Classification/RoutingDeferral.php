<?php

namespace App\Service\Message\Classification;

use App\AI\ToolCalling\ToolCallingCapability;
use App\Entity\Message;
use App\Service\Message\Routing\NativeToolRoutingConfig;
use App\Service\Message\Routing\RoutingDecision;
use App\Service\Message\Routing\RoutingLayer;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * Phase 9 of the cascade (NATIVE_TOOL_ROUTING, off by default): hand the
 * routing decision to the answering call itself.
 *
 * Every layer before this one must decide BEFORE any model runs — an
 * override, a slash command, an attachment rule, a cheap embedding match.
 * What is left over is exactly the population that costs a full AI-sorter
 * round-trip, and for the most common member of that population (an
 * ordinary chat turn) the sorter answers a question the chat model was about
 * to answer anyway.
 *
 * So instead of classifying, emit a deferred classification: route to
 * `chat`, and let ChatHandler attach the RoutingToolset hand-off tools to
 * the answering call. No tool call means it really was a chat turn — one LLM
 * call for the whole turn instead of two. A hand-off tool call means
 * ChatHandler discards its own answer and InferenceRouter re-routes to the
 * media/office/summary handler.
 *
 * ChatHandler owns the fallback: if the resolved chat provider cannot do
 * native tool calling ({@see ToolCallingCapability}), it asks the sorter
 * after all, so an unsupported provider behaves exactly as without the
 * deferral.
 */
final readonly class RoutingDeferral
{
    public function __construct(
        private NativeToolRoutingConfig $nativeToolRoutingConfig,
        private ModelConfigService $modelConfigService,
        private ToolCallingCapability $toolCallingCapability,
        private SelfAwareQuestion $selfAware,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $allowed the caller allows it, the turn does not need the sorter, and the embedding router did not decline for language
     *
     * @return array<string, mixed>|null the deferred classification, or null to ask the sorter
     */
    public function defer(Message $message, string $text, ?int $overrideModelId, bool $allowed, bool $forceWebSearch): ?array
    {
        $userId = $message->getUserId();
        $messageId = $message->getId();

        if ($allowed
            && null === $overrideModelId
            && !empty($text)
            && $this->nativeToolRoutingConfig->isEnabled($userId)
            && !$this->selfAware->matches($text, $userId)
            && $this->accountChatModelCanRouteNatively($userId)
        ) {
            $deferredLanguage = ConfidentLanguage::of($message, $text) ?? 'en';

            $this->logger->info('MessageClassifier: Deferring routing to the answering call (native tool calling)', [
                'message_id' => $messageId,
                'language' => $deferredLanguage,
            ]);

            $deferredDecision = RoutingDecision::deterministic(RoutingLayer::NativeToolCalling, 'general');

            return SlashCommandRoute::withForceWebSearch(array_merge([
                'topic' => 'general',
                // Unlike the fast-path and the embedding router, an ambiguous
                // language does NOT force an escalation here: those layers
                // commit to a final topic, whereas this one only defers, and
                // the answering model replies in the user's language whatever
                // this field says.
                'language' => $deferredLanguage,
                // `web_search` is null for the same reason as on every other
                // sorter-skipping layer: no sorter, no BWEBSEARCH vote. An
                // explicit per-message request still searches via
                // WebSearchTopicPolicy.
                'web_search' => null,
                'source' => $deferredDecision->toClassificationSource(),
                'skip_sorting' => true,
                'intent' => 'chat',
                'model_id' => null,
                'provider' => null,
                'model_name' => null,
                // The flag ChatHandler keys on to attach the hand-off tools.
                // Absent (not false) on every other path, so no existing
                // caller changes behaviour.
                'defer_routing_to_chat' => true,
            ], $deferredDecision->toClassificationFields()), $forceWebSearch);
        }

        return null;
    }

    /**
     * Cheap pre-gate for the Phase 9 deferral: can the account's default chat
     * provider do native tool calling at all?
     *
     * NOT the authoritative check — the model that ends up answering can be a
     * different one (widget override, prompt binding, "Again" replay), which
     * is why {@see \App\Service\Message\Handler\ChatHandler} re-checks and can still send the turn
     * back. This only avoids deferring on installs where the answer is a
     * foregone "no", where every message would otherwise pay for a pointless
     * re-route.
     */
    private function accountChatModelCanRouteNatively(?int $userId): bool
    {
        $provider = $this->modelConfigService->getDefaultProvider($userId, 'chat');

        return '' !== $provider && $this->toolCallingCapability->supports($provider, null, false);
    }
}
