<?php

namespace App\Service\Message\Classification;

use App\Entity\Message;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\Routing\RoutingDecision;
use App\Service\Message\Routing\RoutingLayer;
use App\Service\Message\SlashCommandParser;
use App\Service\Multitask\Plan\Capability;
use Psr\Log\LoggerInterface;

/**
 * The first step of the cascade, on every channel (#2280): a bare command
 * that needs an argument answers with its usage hint, a tool command (/pic,
 * /vid, /tts, …) routes to its tool, and /search or /docs with an argument
 * strip the command and become overlays on whatever classifies the rest —
 * forced web search, or a knowledge-base query.
 */
final readonly class SlashCommandRoute
{
    public function __construct(
        private TopicIntentRule $topicIntents,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(Message $message): SlashOutcome
    {
        $text = $message->getText();
        if (empty($text) || !str_starts_with(ltrim($text), '/')) {
            return new SlashOutcome();
        }

        $slashResult = $this->resolveSlashCommand($message, $text);

        return new SlashOutcome($slashResult['return'], $slashResult['force_web_search'], $slashResult['slash_docs']);
    }

    /**
     * /docs <query> without a pin: a knowledge-base query, no AI sorter.
     *
     * @return array<string, mixed>
     */
    public function docsRoute(Message $message): array
    {
        $language = $message->getLanguage() ?: 'en';
        if ('NN' === $language) {
            $language = 'en';
        }
        $docsDecision = RoutingDecision::deterministic(RoutingLayer::ToolCommand, 'general');

        return array_merge([
            'topic' => 'general',
            'language' => $language,
            'intent' => Capability::RagQuery->value,
            'source' => $docsDecision->toClassificationSource(),
            'skip_sorting' => true,
            'slash_docs' => true,
        ], $docsDecision->toClassificationFields());
    }

    /**
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    public function withOverlays(array $classification, SlashOutcome $slash): array
    {
        return $this->applySlashOverlaysToClassification($classification, $slash->forceWebSearch, $slash->docs);
    }

    /**
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    public static function withForceWebSearch(array $classification, bool $force): array
    {
        if ($force) {
            $classification['force_web_search'] = true;
        }

        return $classification;
    }

    /**
     * Resolve a leading slash command via {@see SlashCommandParser}.
     *
     * Bare argument-required commands and media tool topics return immediately.
     * `/search` and `/docs` with an argument strip the prefix and continue so a
     * pinned assistant profile can still be applied on top.
     *
     * @return array{return: array<string, mixed>|null, force_web_search: bool, slash_docs: bool}
     */
    private function resolveSlashCommand(Message $message, string $text): array
    {
        $parsed = (new SlashCommandParser())->parse($text);
        if (null === $parsed) {
            return ['return' => null, 'force_web_search' => false, 'slash_docs' => false];
        }

        $language = $message->getLanguage() ?: 'en';
        if ('NN' === $language) {
            $language = 'en';
        }

        if ($parsed->requiresArgument() && $parsed->isBare()) {
            $this->logger->info('MessageClassifier: Bare slash command — usage hint', [
                'message_id' => $message->getId(),
                'command' => $parsed->name,
            ]);

            $decision = RoutingDecision::deterministic(RoutingLayer::ToolCommand, 'general');

            return [
                'return' => array_merge([
                    'topic' => 'general',
                    'language' => $language,
                    'intent' => Capability::Chat->value,
                    'source' => $decision->toClassificationSource(),
                    'skip_sorting' => true,
                    'slash_hint' => true,
                    'slash_command' => $parsed->name,
                ], $decision->toClassificationFields()),
                'force_web_search' => false,
                'slash_docs' => false,
            ];
        }

        if ('search' === $parsed->name) {
            $message->setText($parsed->argument);
            $this->logger->info('MessageClassifier: /search stripped — force web search', [
                'message_id' => $message->getId(),
                'query_length' => strlen($parsed->argument),
            ]);

            return ['return' => null, 'force_web_search' => true, 'slash_docs' => false];
        }

        if ('docs' === $parsed->name) {
            $message->setText($parsed->argument);
            $this->logger->info('MessageClassifier: /docs stripped — rag_query', [
                'message_id' => $message->getId(),
                'query_length' => strlen($parsed->argument),
            ]);

            return ['return' => null, 'force_web_search' => false, 'slash_docs' => true];
        }

        $toolTopic = $parsed->toolTopic();
        if (null !== $toolTopic) {
            $this->logger->info('MessageClassifier: Tool command detected', [
                'message_id' => $message->getId(),
                'tool' => $toolTopic,
            ]);

            $decision = RoutingDecision::deterministic(RoutingLayer::ToolCommand, $toolTopic);

            return [
                'return' => array_merge([
                    'topic' => $toolTopic,
                    'language' => $language,
                    'intent' => $this->topicIntents->intentFor($toolTopic),
                    'source' => $decision->toClassificationSource(),
                    'skip_sorting' => true,
                ], $decision->toClassificationFields()),
                'force_web_search' => false,
                'slash_docs' => false,
            ];
        }

        return ['return' => null, 'force_web_search' => false, 'slash_docs' => false];
    }

    /**
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private function applySlashOverlaysToClassification(
        array $classification,
        bool $forceWebSearch,
        bool $slashDocs,
    ): array {
        if ($forceWebSearch) {
            $classification['force_web_search'] = true;
        }
        if ($slashDocs) {
            $classification['intent'] = Capability::RagQuery->value;
            $classification['slash_docs'] = true;
        }

        return $classification;
    }
}
