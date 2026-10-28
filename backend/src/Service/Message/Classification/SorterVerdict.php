<?php

namespace App\Service\Message\Classification;

use App\Entity\Message;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\Effort\EffortVerdict;
use App\Service\Message\MessageSorter;
use App\Service\Message\Routing\RoutingDecision;
use App\Service\Message\Routing\RoutingLayer;
use Psr\Log\LoggerInterface;

/**
 * The last step of the cascade: the AI sorter (DEFAULTMODEL.SORT) decides
 * the topic, language, web search, read pages and step count; a published
 * assistant it picked gets its profile. Overlays then correct the verdict
 * where a vote wobbles: the media type, the describe reroute for audio about
 * a picture (#1237), the planner for code on files and for a spoken answer,
 * a forced web search, and the media parameters (duration, resolution,
 * input mode).
 */
final readonly class SorterVerdict
{
    public function __construct(
        private MessageSorter $messageSorter,
        private SorterInput $sorterInput,
        private TopicIntentRule $topicIntents,
        private AgentPinRoute $agentPinRoute,
        private AttachmentIntent $attachments,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int, Message> $conversationHistory
     *
     * @return array<string, mixed>
     */
    public function classify(Message $message, string $text, array $conversationHistory, ?int $overrideModelId, bool $forceWebSearch, bool $spokenOutput): array
    {
        $userId = $message->getUserId();
        $messageId = $message->getId();

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

        // The effort rating rides in the same sorter answer. Read from the
        // raw reply: the sorter's own parser keeps the fields it knows and
        // does not pass the decoded object through.
        $effort = EffortVerdict::fromRawResponse(is_string($result['raw_response'] ?? null) ? $result['raw_response'] : null);

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
        ], $effort->toClassificationFields(), $aiSortingDecision->toClassificationFields()), $message);

        return $this->withOverlays($classification, $result, $message, $text, $overrideModelId, $forceWebSearch, $spokenOutput);
    }

    /**
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $result         the sorter's raw answer
     *
     * @return array<string, mixed>
     */
    private function withOverlays(array $classification, array $result, Message $message, string $text, ?int $overrideModelId, bool $forceWebSearch, bool $spokenOutput): array
    {
        $messageId = $message->getId();

        if ($overrideModelId) {
            $classification['override_model_id'] = $overrideModelId;
        }

        // Pass through media_type if the sorter set BMEDIA (for the mediamaker
        // topic) so MediaGenerationHandler knows which provider to invoke.
        $mediaType = $result['media_type'] ?? null;
        if (null !== $mediaType) {
            $classification['media_type'] = $mediaType;
        }

        $classification = $this->withPlannerCorrections($classification, $message, $text, $spokenOutput);

        if ($forceWebSearch) {
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
     * The planner takes the turn where the sorter's vote wobbles: audio about
     * an attached picture is described and then spoken (#1237), code on the
     * attached files runs, and a spoken answer is written and then spoken.
     *
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private function withPlannerCorrections(array $classification, Message $message, string $text, bool $spokenOutput): array
    {
        $messageId = $message->getId();

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

        return $classification;
    }
}
