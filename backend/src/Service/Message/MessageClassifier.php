<?php

namespace App\Service\Message;

use App\Entity\Message;
use App\Service\Message\Classification\AgainOverride;
use App\Service\Message\Classification\AgentPinRoute;
use App\Service\Message\Classification\AttachmentIntent;
use App\Service\Message\Classification\EmbeddingRoute;
use App\Service\Message\Classification\FastPathClassifier;
use App\Service\Message\Classification\RoutingDeferral;
use App\Service\Message\Classification\SlashCommandRoute;
use App\Service\Message\Classification\SorterVerdict;
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

    public function __construct(
        private SlashCommandRoute $slashCommands,
        private AgentPinRoute $agentPinRoute,
        private FastPathClassifier $fastPath,
        private AgainOverride $againOverride,
        private AttachmentIntent $attachments,
        private EmbeddingRoute $embedding,
        private RoutingDeferral $deferral,
        private SorterVerdict $sorter,
        private LoggerInterface $logger,
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
        // Slash commands run before the agent pin: WhatsApp and MCP often
        // supply an agentId, and a bare /pic, /search or /docs must still
        // answer with its usage hint instead of reaching the chat model
        // (#2280). /search and /docs with an argument keep a pinned profile
        // and only overlay force_web_search / rag_query.
        $slash = $this->slashCommands->resolve($message);
        if (null !== $slash->answer) {
            return $slash->answer;
        }
        $text = $message->getText();

        $pinned = $this->agentPinRoute->pin($message, $options, $conversationHistory, $slash->forceWebSearch || !empty($options['web_search_requested']));
        if (null !== $pinned) {
            return $this->slashCommands->withOverlays($pinned, $slash);
        }
        if ($slash->docs) {
            return $this->slashCommands->docsRoute($message);
        }

        $this->logger->info('MessageClassifier: Starting classification', [
            'message_id' => $message->getId(),
            'user_id' => $message->getUserId(),
            'has_text' => !empty($text),
            'override_model_id' => $overrideModelId,
        ]);

        $decided = $this->fastPath->classify($message, $text, $conversationHistory, $overrideModelId, $slash->forceWebSearch)
            ?? $this->againOverride->route($message)
            ?? $this->attachments->route($message, $text);
        if (null !== $decided) {
            return $decided;
        }

        // Produce-a-file turns (#2047, #2049) and spoken answers need the
        // sorter and the planner, so neither the embedding router nor the
        // deferral may take them.
        $produceFileWork = $this->attachments->requestsFileProduction($message, $text);
        $spokenOutput = 1 === preg_match(self::SPOKEN_OUTPUT_PATTERN, $text);

        $embedding = $this->embedding->match($message, $text, $overrideModelId, $produceFileWork || $spokenOutput, $slash->forceWebSearch);
        if (null !== $embedding->classification) {
            return $embedding->classification;
        }

        $mayDefer = $allowRoutingDeferral && !$produceFileWork && !$spokenOutput && !$embedding->declinedForLanguage;

        return $this->deferral->defer($message, $text, $overrideModelId, $mayDefer, $slash->forceWebSearch)
            ?? $this->sorter->classify($message, $text, $conversationHistory, $overrideModelId, $slash->forceWebSearch, $spokenOutput);
    }
}
