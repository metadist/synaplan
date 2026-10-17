<?php

namespace App\Service\Message;

use App\AI\ToolCalling\ToolCallingCapability;
use App\Entity\Agent;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\Classification\AgainOverride;
use App\Service\Message\Classification\AgentPinRoute;
use App\Service\Message\Classification\AttachmentIntent;
use App\Service\Message\Classification\ConfidentLanguage;
use App\Service\Message\Classification\FastPathClassifier;
use App\Service\Message\Classification\SelfAwareQuestion;
use App\Service\Message\Classification\SlashCommandRoute;
use App\Service\Message\Classification\SorterInput;
use App\Service\Message\Routing\EmbeddingRouterConfig;
use App\Service\Message\Routing\EmbeddingRouterService;
use App\Service\Message\Routing\NativeToolRoutingConfig;
use App\Service\Message\Routing\RoutingDecision;
use App\Service\Message\Routing\RoutingLayer;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * Decides where a message goes, as a cascade in the order it runs:
 *
 * 1. slash commands — a bare /pic, /vid or /search answers with its usage
 *    hint, even for a pinned assistant (#2280);
 * 2. an agent pin — the assistant's topic, with only the sorter's web vote;
 * 3. /docs <query> without a pin — knowledge search;
 * 4. the fast path (CLASSIFIER.FAST_PATH_ENABLED, off by default);
 * 5. Again — a prompt or a model the user picked (BMESSAGEMETA);
 * 6. document, audio and video attachments — file analysis (#595, #722);
 * 7. the embedding router (Phase 8, off by default);
 * 8. the deferral to the answering call (Phase 9, off by default);
 * 9. the AI sorter (DEFAULTMODEL.SORT), then the overlays on its verdict:
 *    media type, the describe reroute, code execution, spoken output, forced
 *    web search, duration, resolution, input mode.
 *
 * A step that decides returns before the sorter, which costs a model call
 * per message.
 */
final readonly class MessageClassifier
{
    public function __construct(
        private MessageSorter $messageSorter,
        private SlashCommandRoute $slashCommands,
        private ModelConfigService $modelConfigService,
        private FastPathClassifier $fastPath,
        private AttachmentIntent $attachments,
        private LoggerInterface $logger,
        private TopicIntentRule $topicIntents,
        private EmbeddingRouterService $embeddingRouter,
        private EmbeddingRouterConfig $embeddingRouterConfig,
        private NativeToolRoutingConfig $nativeToolRoutingConfig,
        private ToolCallingCapability $toolCallingCapability,
        private AgentPinRoute $agentPinRoute,
        private AgainOverride $againOverride,
        private SorterInput $sorterInput,
        private SelfAwareQuestion $selfAware,
    ) {
    }

    /**
     * Classify message and determine routing.
     *
     * @param Message $message              Message entity
     * @param array   $conversationHistory  Previous messages
     * @param bool    $allowRoutingDeferral whether the Phase 9 native tool-calling layer may
     *                                      defer the decision to the answering call. Set to
     *                                      false by {@see InferenceRouter} when a deferral came
     *                                      back unhonoured, so the second pass takes the AI
     *                                      sorter instead of deferring again
     * @param array   $options              Optional pin (`agentId`) and other classifier hints
     *
     * @return array ['topic' => string, 'language' => string, 'source' => string, 'skip_sorting' => bool]
     */
    public function classify(Message $message, array $conversationHistory = [], ?int $overrideModelId = null, bool $allowRoutingDeferral = true, array $options = []): array
    {
        $userId = $message->getUserId();
        $messageId = $message->getId();
        // Slash commands first (shared parser, every channel — issue #2280).
        // Must run BEFORE the agent-pin fast-return: WhatsApp/MCP often supply
        // agentId, and a bare /pic|/search|/docs must still return a usage hint
        // instead of reaching the chat model. /search and /docs with an argument
        // keep any pinned runtime profile and only overlay force_web_search /
        // rag_query.
        $slash = $this->slashCommands->resolve($message);
        if (null !== $slash->answer) {
            return $slash->answer;
        }
        $forceWebSearchFromSlash = $slash->forceWebSearch;
        $text = $message->getText();

        $pinned = $this->agentPinRoute->pin(
            $message,
            $options,
            $conversationHistory,
            $forceWebSearchFromSlash || !empty($options['web_search_requested']),
        );
        if (null !== $pinned) {
            return $this->slashCommands->withOverlays($pinned, $slash);
        }

        if ($slash->docs) {
            return $this->slashCommands->docsRoute($message);
        }

        $this->logger->info('MessageClassifier: Starting classification', [
            'message_id' => $messageId,
            'user_id' => $userId,
            'has_text' => !empty($text),
            'override_model_id' => $overrideModelId,
        ]);

        // Phase 1c: the fast path skips the AI sorter for obviously plain chat.
        $fastPath = $this->fastPath->classify($message, $text, $conversationHistory, $overrideModelId, $forceWebSearchFromSlash);
        if (null !== $fastPath) {
            return $fastPath;
        }

        // 1. Again: a prompt or a model the user picked
        $again = $this->againOverride->route($message);
        if (null !== $again) {
            return $again;
        }

        // 2. Document / audio / video attachments → FileAnalysisHandler (ANALYZE model), before AI sorting (#595, #722)
        $attachmentRoute = $this->attachments->route($message, $text);
        if (null !== $attachmentRoute) {
            return $attachmentRoute;
        }

        // Phase 8: embedding-router cascade layer. Sits after every
        // deterministic layer above (overrides, tool commands, attachments)
        // and before the AI sorter below. Embeds the message with the same
        // bge-m3 model used for RAG and matches it via cosine similarity
        // against pre-computed example-utterance anchors for the four
        // SYSTEM topics (general, mediamaker, officemaker, docsummary)
        // stored in Qdrant — see EmbeddingRouterService and the
        // `app:routing:sync-anchors` command that (re)populates the anchors
        // from SystemCapabilityRegistry::exampleUtterances. User-defined
        // topics have no curated anchors, so a confident match is always one
        // of the four system topics; anything else escalates to the AI
        // sorter below exactly like today.
        //
        // Like the fast-path, a confident match short-circuits the sorter
        // round-trip entirely — unlike the fast-path this is not restricted
        // to `general`. Extraction of the sorter's topic-specific votes
        // (BMEDIA, BRESOLUTION, BINPUTMODE) is intentionally NOT attempted
        // here: MediaPromptExtractor already re-derives BMEDIA from the
        // message text via its own dedicated AI call whenever the
        // classification omits it, so a mediamaker match degrades
        // gracefully instead of needing those votes up front.
        // Set when Phase 8 found a confident topic but refused to commit
        // because the language was ambiguous. That refusal is a request for
        // the AI sorter specifically (it resolves BLANG), so Phase 9 — which
        // would answer with `language: 'en'` — must not intercept it.
        $embeddingDeclinedForLanguage = false;

        // Produce-a-file turns must reach the planner deterministically
        // (#2047, #2049): they skip every sorter-skipping short-circuit
        // below, and the multi_step force after the sorter sends them to
        // the planner, whose rule 3a arbitrates code_run.
        $produceFileWork = $this->attachments->requestsFileProduction($message, $text);

        // "Make an audio where you explain X" needs the text written first and
        // spoken second. Only the AI sorter plus the planner deliver both, so
        // these turns skip the embedding match and the chat deferral too.
        $spokenOutput = 1 === preg_match(self::SPOKEN_OUTPUT_PATTERN, $text);

        if (null === $overrideModelId
            && !empty($text)
            && !$produceFileWork
            && !$spokenOutput
            && $this->embeddingRouterConfig->isEnabled($userId)
            && !$this->selfAware->matches($text, $userId)
        ) {
            $embeddingMatch = $this->embeddingRouter->findClosestAnchor($text, $userId);

            if (null !== $embeddingMatch && $embeddingMatch->score >= $this->embeddingRouterConfig->getConfidenceThreshold()) {
                $confidentLanguage = ConfidentLanguage::of($message, $text);

                if (null !== $confidentLanguage) {
                    $this->logger->info('MessageClassifier: Embedding-router match (skipped AI sorter)', [
                        'message_id' => $messageId,
                        'topic' => $embeddingMatch->topic,
                        'confidence' => $embeddingMatch->score,
                        'language' => $confidentLanguage,
                    ]);

                    $embeddingDecision = new RoutingDecision(
                        RoutingLayer::EmbeddingRouter,
                        $embeddingMatch->topic,
                        confidence: $embeddingMatch->score,
                        discardedAlternatives: array_map(
                            static fn (array $alternative): string => sprintf('%s (%.3f)', $alternative['topic'], $alternative['score']),
                            $embeddingMatch->discardedAlternatives
                        ),
                    );

                    // `web_search` is intentionally null here for the same
                    // reason as the fast-path: no AI-sorter round-trip means
                    // no BWEBSEARCH vote, and under the "trust the model"
                    // policy a missing vote means no search.
                    return SlashCommandRoute::withForceWebSearch(array_merge([
                        'topic' => $embeddingMatch->topic,
                        'language' => $confidentLanguage,
                        'web_search' => null,
                        'source' => $embeddingDecision->toClassificationSource(),
                        'skip_sorting' => true,
                        'intent' => $this->topicIntents->intentFor($embeddingMatch->topic),
                        'model_id' => null,
                        'provider' => null,
                        'model_name' => null,
                    ], $embeddingDecision->toClassificationFields()), $forceWebSearchFromSlash);
                }

                $embeddingDeclinedForLanguage = true;

                $this->logger->info('MessageClassifier: Embedding-router match declined (language ambiguous) — deferring to AI sorter', [
                    'message_id' => $messageId,
                    'topic' => $embeddingMatch->topic,
                    'confidence' => $embeddingMatch->score,
                ]);
            }
        }

        // Phase 9: hand the routing decision to the answering call itself.
        //
        // Everything above this point is a layer that must decide BEFORE any
        // model runs — an override, a slash command, an attachment rule, a
        // cheap embedding match. What is left over is exactly the population
        // that costs a full AI-sorter round-trip today, and for the most
        // common member of that population (an ordinary chat turn) the sorter
        // answers a question the chat model was about to answer anyway.
        //
        // So instead of classifying here, emit a deferred classification:
        // route to `chat`, and let ChatHandler attach the RoutingToolset
        // hand-off tools to the answering call. No tool call means it really
        // was a chat turn — one LLM call for the whole turn instead of two.
        // A hand-off tool call means ChatHandler discards its own answer and
        // InferenceRouter re-routes to the media/office/summary handler.
        //
        // ChatHandler owns the fallback: if the resolved chat provider cannot
        // do native tool calling ({@see \App\AI\ToolCalling\ToolCallingCapability}),
        // it asks the sorter after all, so an unsupported provider behaves
        // exactly as it does today.
        if ($allowRoutingDeferral
            && null === $overrideModelId
            && !empty($text)
            && !$produceFileWork
            && !$spokenOutput
            && !$embeddingDeclinedForLanguage
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
            ], $deferredDecision->toClassificationFields()), $forceWebSearchFromSlash);
        }

        // 4. Classify with the LLM AI sorter (DEFAULTMODEL.SORT).
        $messageData = $this->sorterInput->for($message);
        $result = $this->messageSorter->classify($messageData, $conversationHistory, $userId);

        $source = $result['source'] ?? 'ai_sorting';

        // The AI sorter returns a canonical topic (general, mediamaker,
        // officemaker, docsummary, …) that downstream code (TopicIntentRule,
        // handler resolution, BFILEPATH keys) understands directly.
        $canonicalTopic = (string) ($result['topic'] ?? 'general');

        // The sorter already built its own RoutingDecision (rule-based match,
        // genuine classification, or a fallback — see
        // MessageSorter::buildRoutingDecision()) and flattened it into
        // $result via RoutingDecision::toClassificationFields(). A mocked
        // sorter (tests) or a source outside the AI-sorting layer may omit
        // these keys entirely, so default to "full confidence, no fallback"
        // rather than treating an absent key as a low-confidence result.
        $aiSortingDecision = new RoutingDecision(
            RoutingLayer::AiSorting,
            $canonicalTopic,
            confidence: (float) ($result['routing_confidence'] ?? 1.0),
            discardedAlternatives: $result['routing_discarded_alternatives'] ?? [],
            fallbackReason: $result['routing_fallback_reason'] ?? null,
        );

        if ($aiSortingDecision->isFallback()) {
            $this->logger->warning('MessageClassifier: ⚠️ AI-sorting result is a fallback, not a confident decision', [
                'message_id' => $messageId,
                'topic' => $canonicalTopic,
                'confidence' => $aiSortingDecision->confidence,
                'fallback_reason' => $aiSortingDecision->fallbackReason,
                'discarded_alternatives' => $aiSortingDecision->discardedAlternatives,
            ]);
        }

        $this->logger->info('MessageClassifier: Classification complete', [
            'message_id' => $messageId,
            'topic' => $canonicalTopic,
            'language' => $result['language'],
            'web_search' => $result['web_search'] ?? false,
            'read_pages' => $result['read_pages'] ?? null,
            'multi_step' => $result['multi_step'] ?? null,
            'media_type' => $result['media_type'] ?? null,
            'duration' => $result['duration'] ?? null,
            'resolution' => $result['resolution'] ?? null,
            'source' => $source,
            'confidence' => $aiSortingDecision->confidence,
            'raw_ai_response' => $result['raw_response'] ?? 'N/A',
        ]);

        $classification = $this->agentPinRoute->attachRoutableAgent(array_merge([
            'topic' => $canonicalTopic,
            'language' => $result['language'],
            'web_search' => $result['web_search'] ?? false,
            // Sorter's BREADPAGES vote: 0 = snippets only, 2 or 3 = dump
            // that many result pages into the answer prompt. Null = no vote
            // (fast-path / older prompt). ReadPagesPolicy fills the gap.
            'read_pages' => $result['read_pages'] ?? null,
            // Sorter's BMULTI vote: true = needs several steps, false = one
            // step, null = no vote (older prompt row / model dropped the
            // field). TaskPlanExecutor uses it to skip the planner round-trip
            // on single-step turns; null keeps the pre-vote behaviour.
            'multi_step' => $result['multi_step'] ?? null,
            'source' => $source,
            'skip_sorting' => false,
            'intent' => $this->topicIntents->intentFor($canonicalTopic),
            'model_id' => $result['sorting_model_id'] ?? null,
            'provider' => $result['sorting_provider'] ?? null,
            'model_name' => $result['sorting_model_name'] ?? null,
            // Usage taximeter: tokens/charged cost of the sorting call (null
            // when the fast path skipped the AI sorter or recording failed).
            'sorting_usage' => $result['sorting_usage'] ?? null,
        ], $aiSortingDecision->toClassificationFields()), $message);

        if ($overrideModelId) {
            $classification['override_model_id'] = $overrideModelId;
        }

        // Pass through media_type if the sorter set BMEDIA (for the mediamaker
        // topic) so MediaGenerationHandler knows which provider to invoke.
        $mediaType = $result['media_type'] ?? null;
        if (null !== $mediaType) {
            $classification['media_type'] = $mediaType;
        }

        // #1237: "describe the attached image as audio". The sorter sometimes
        // keeps BTOPIC=mediamaker with BMEDIA=audio because the user names an
        // audio OUTPUT format ("beschreibe in einem audio was hier zu sehen
        // ist") — but with an image attachment this is a describe/analyze
        // intent: MediaGenerationHandler would pass the attachment as a
        // pic2pic reference and start an IMAGE generation that can only fail.
        // Reroute deterministically to the describe path (topic `general`,
        // vision chat); under multitask routing the planner then chains
        // `file_analysis → text2sound` so the user still gets their audio.
        if ('mediamaker' === $classification['topic']
            && 'audio' === ($classification['media_type'] ?? null)
            && $this->attachments->hasImageAttachment($message)) {
            $this->logger->info('MessageClassifier: rerouting mediamaker/audio with image attachment to describe path (#1237)', [
                'message_id' => $messageId,
            ]);

            $classification['topic'] = 'general';
            $classification['intent'] = 'chat';
            unset($classification['media_type']);
            // This reroute only produces the requested audio because the
            // planner chains `file_analysis → text2sound`. Force the planner on
            // even if the sorter voted "single step" for the describe half of
            // the request.
            $classification['multi_step'] = true;
        }

        // File work has no legacy-router equivalent: skipping the planner
        // degrades execution demands into a chat answer claiming no
        // interpreter exists. The sorter prompt asks for BMULTI=1 here, but
        // votes wobble — force the planner on deterministically when files
        // are attached and the text explicitly demands execution.
        if ($this->attachments->requestsCodeExecution($message, $text)) {
            $this->logger->info('MessageClassifier: forcing planner for file-work execution demand', [
                'message_id' => $messageId,
            ]);

            $classification['multi_step'] = true;
        }

        // A sorter that files an audio request as plain chat leaves the chat
        // model to answer "I cannot create audio". The planner writes the
        // text and speaks it, so hand it the turn whenever the sorter did
        // not already pick the speech generator itself.
        if ($spokenOutput
            && true !== ($classification['multi_step'] ?? null)
            && !('mediamaker' === $classification['topic'] && 'audio' === ($classification['media_type'] ?? null))) {
            $this->logger->info('MessageClassifier: forcing planner for a spoken-output request', [
                'message_id' => $messageId,
                'sorter_topic' => $classification['topic'],
            ]);

            $classification['multi_step'] = true;
        }

        if ($forceWebSearchFromSlash) {
            $classification['force_web_search'] = true;
        }

        // Pass through duration if detected (for video generation)
        $duration = $result['duration'] ?? null;
        if (null !== $duration) {
            $classification['duration'] = $duration;
        }

        // Pass through resolution if detected (for video generation).
        // Already validated by MessageSorter against the supported enum, so the
        // handler can forward it to the provider as-is.
        $resolution = $result['resolution'] ?? null;
        if (is_string($resolution) && '' !== $resolution) {
            $classification['resolution'] = $resolution;
        }

        // Pass through the sorter's BINPUTMODE vote. `reference_images` is how
        // MediaGenerationHandler learns that an image request edits an existing
        // picture instead of drawing a new one — without this the vote was
        // parsed, logged and then dropped here, so every attachment-less
        // follow-up ("make the car blue") fell back to a fresh generation.
        $inputMode = $result['input_mode'] ?? null;
        if (is_string($inputMode) && '' !== $inputMode) {
            $classification['input_mode'] = $inputMode;
        }

        return $classification;
    }

    /**
     * The person asks to HEAR the result: a produce verb paired with an audio
     * object, or a read-aloud phrase. Talking ABOUT audio ("what is an MP3
     * codec?") has no produce verb and does not match.
     */
    private const SPOKEN_OUTPUT_PATTERN = '/(*UCP)(?:'
        .'\b(?:erstell\w*|erzeug\w*|mach\w*|generier\w*|schick\w*|sende|nimm\w*|create|make|generate|send|record|produce|crea|genera|haz|crée|génère|fais|envoie)\b[^.!?]{0,40}\b(?:audio|audiodatei|mp3|sprachnachricht|sprachmemo|hörbuch|podcast|voice\s+(?:message|note|memo)|nota\s+de\s+voz|message\s+vocal)\b'
        .'|\b(?:vorlesen|vorlies\w*|vorgelesen|vertone\w*|vertonen|vertont)\b'
        .'|\b(?:lies|liest)\b[^.!?]{0,30}\bvor\b'
        .'|\bread\b[^.!?]{0,30}\b(?:aloud|out\s+loud)\b'
        .'|\b(?:als|as|into|en|comme)\s+(?:an?\s+|eine[nmr]?\s+)?(?:audio|mp3|sprachnachricht|voice\s+message|speech)\b'
        .'|\btext[\s-]to[\s-]speech\b|\ben\s+voz\s+alta\b|\bà\s+voix\s+haute\b'
        .'|\bsesli\s+oku\w*|\b(?:ses\s+dosyası|mp3)\b[^.!?]{0,40}\b(?:oluştur\w*|yap\w*)\b'
        .')/iu';

    /**
     * Cheap pre-gate for the Phase 9 deferral: can the account's default chat
     * provider do native tool calling at all?
     *
     * NOT the authoritative check — the model that ends up answering can be a
     * different one (widget override, prompt binding, "Again" replay), which
     * is why {@see Handler\ChatHandler} re-checks and can still send the turn
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
