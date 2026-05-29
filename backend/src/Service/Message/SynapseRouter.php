<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Repository\PromptRepository;
use App\Service\PromptService;
use Psr\Log\LoggerInterface;

/**
 * Synapse Router — tiered message classification pipeline.
 *
 * Tier 0: Rule-based routing (selection rules on prompts, 0 ms)
 * Tier 1: External ML Router (SetFit/ONNX via synaplan-router, ~2-5 ms)
 * Tier 2: AI Fallback (MessageSorter LLM call, ~200 ms)
 *
 * Language detection, web-search intent, and media-type classification
 * are handled via lightweight heuristics instead of LLM calls.
 */
final readonly class SynapseRouter
{
    private const MEDIA_KEYWORDS = [
        'image' => ['bild', 'image', 'foto', 'picture', 'illustration', 'picture', 'photo', 'pic',
            'erstelle ein bild', 'generate an image', 'create a picture', 'make a photo',
            'zeichne', 'draw', 'malen', 'paint', 'design', 'generiere'],
        'video' => ['video', 'film', 'clip', 'animation', 'animate', 'erstelle ein video',
            'create a video', 'make a video', 'generate a video', 'vid'],
        'audio' => ['audio', 'vorlesen', 'speech', 'tts', 'text to speech', 'text-to-speech',
            'lies vor', 'read aloud', 'sprich', 'speak', 'voice', 'sound', 'mp3',
            'vertone', 'vertonen', 'wav'],
    ];

    /**
     * Common language-specific words for n-gram-free detection.
     *
     * @var array<string, list<string>>
     */
    private const LANGUAGE_MARKERS = [
        'de' => ['ich', 'und', 'der', 'die', 'das', 'ist', 'ein', 'eine', 'nicht', 'mit', 'für', 'auf', 'den', 'von', 'wie', 'kann', 'mir', 'mein', 'hab', 'bitte', 'kannst'],
        'en' => ['the', 'and', 'is', 'are', 'was', 'you', 'your', 'with', 'this', 'that', 'for', 'can', 'have', 'not', 'what', 'how', 'please', 'would', 'could'],
        'fr' => ['le', 'la', 'les', 'des', 'est', 'une', 'que', 'pour', 'pas', 'dans', 'sur', 'avec', 'qui', 'mais', 'vous', 'nous', 'mon', 'mes', 'cette', 'sont', "c'est", "j'ai"],
        'es' => ['el', 'la', 'los', 'las', 'una', 'que', 'por', 'para', 'con', 'del', 'como', 'pero', 'más', 'este', 'esta', 'tiene', 'puede', 'hacer', 'muy'],
        'it' => ['il', 'lo', 'la', 'gli', 'che', 'per', 'con', 'una', 'sono', 'non', 'del', 'della', 'questo', 'questa', 'come', 'più', 'anche', 'fare', 'può'],
        'nl' => ['het', 'een', 'van', 'dat', 'met', 'voor', 'niet', 'zijn', 'maar', 'ook', 'nog', 'wel', 'dit', 'deze', 'kan', 'naar', 'moet', 'goed'],
        'pt' => ['que', 'não', 'para', 'uma', 'com', 'por', 'mais', 'como', 'mas', 'dos', 'das', 'tem', 'foi', 'pode', 'este', 'esta', 'muito', 'também'],
        'ru' => ['и', 'в', 'не', 'на', 'что', 'это', 'как', 'для', 'мне', 'все', 'мой', 'они', 'вы', 'он', 'она', 'но', 'было', 'быть', 'ты'],
        'tr' => ['bir', 'bu', 've', 'için', 'ile', 'ben', 'var', 'çok', 'daha', 'olan', 'gibi', 'nasıl', 'olarak', 'benim', 'ne', 'ama'],
        'sv' => ['och', 'att', 'det', 'som', 'för', 'med', 'inte', 'den', 'har', 'var', 'jag', 'kan', 'till', 'från', 'detta'],
    ];

    public function __construct(
        private MessageSorter $messageSorter,
        private RouterClient $routerClient,
        private PromptService $promptService,
        private PromptRepository $promptRepository,
        private TopicAliasResolver $topicAliasResolver,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Dry-run the routing pipeline for a sample message (admin testing).
     *
     * Calls the external router and returns the classification result
     * without mutating any state. Falls back to reporting the AI sorter
     * would be used if the router is unavailable.
     *
     * @return array{
     *     query: string,
     *     router_available: bool,
     *     classification: ?array,
     *     fallback_reason: ?string,
     *     latency_ms: float,
     *     error: ?string,
     * }
     */
    public function dryRun(string $messageText, ?int $userId = null, int $limit = 5): array
    {
        $startTime = microtime(true);

        $base = [
            'query' => $messageText,
            'router_available' => false,
            'classification' => null,
            'fallback_reason' => null,
            'latency_ms' => 0.0,
            'error' => null,
        ];

        if ('' === trim($messageText)) {
            return ['error' => 'empty_message'] + $base;
        }

        $result = $this->routerClient->classify($messageText);

        if (null === $result) {
            return [
                'query' => $messageText,
                'router_available' => false,
                'classification' => null,
                'fallback_reason' => 'router_unavailable_or_disabled',
                'latency_ms' => $this->elapsed($startTime),
                'error' => null,
            ];
        }

        $alias = $this->topicAliasResolver->resolve($result['use_case']);

        return [
            'query' => $messageText,
            'router_available' => true,
            'classification' => [
                'use_case' => $result['use_case'],
                'canonical_topic' => $alias['topic'],
                'confidence' => $result['confidence'],
                'is_compound' => $result['is_compound'],
                'steps' => $result['steps'],
                'model_version' => $result['model_version'],
                'router_latency_ms' => $result['latency_ms'],
                'alias_target' => null !== $alias['alias_source'] ? $alias['topic'] : null,
                'implied_media' => $alias['media'],
            ],
            'fallback_reason' => null,
            'latency_ms' => $this->elapsed($startTime),
            'error' => null,
        ];
    }

    /**
     * Route a message using the tiered classification pipeline.
     *
     * Returns the same structure as MessageSorter::classify() for drop-in compatibility.
     *
     * @param array    $messageData         Message data (BTEXT, BFILETEXT, etc.)
     * @param array    $conversationHistory Previous messages in thread
     * @param int|null $userId              User ID for model config
     *
     * @return array Classification result compatible with MessageSorter output
     */
    public function route(array $messageData, array $conversationHistory = [], ?int $userId = null): array
    {
        $startTime = microtime(true);
        $messageText = $messageData['BTEXT'] ?? '';

        if ('' === $messageText) {
            return $this->fallbackToAi($messageData, $conversationHistory, $userId, 'empty_message');
        }

        // Tier 0: Rule-based routing (selection rules on prompts)
        if ($userId) {
            $ruleBasedTopic = $this->checkRuleBasedRouting($messageText, $conversationHistory, $userId);
            if ($ruleBasedTopic) {
                $promptMetadata = $this->loadPromptMetadata($ruleBasedTopic, $userId);

                $this->logger->info('SynapseRouter: Rule-based match', [
                    'topic' => $ruleBasedTopic,
                    'latency_ms' => $this->elapsed($startTime),
                ]);

                return [
                    'topic' => $ruleBasedTopic,
                    'language' => $messageData['BLANG'] ?? 'en',
                    'web_search' => WebSearchTopicPolicy::shouldSearch(
                        $ruleBasedTopic,
                        $promptMetadata['tool_internet'] ?? null,
                    ),
                    'raw_response' => 'Synapse: Rule-based routing',
                    'prompt_metadata' => $promptMetadata,
                    'source' => 'synapse_rule',
                    'sorting_model_id' => null,
                    'sorting_provider' => null,
                    'sorting_model_name' => null,
                ];
            }
        }

        // Tier 1: External ML Router (SetFit/ONNX)
        $routerResult = $this->tryExternalRouter($messageText, $conversationHistory, $userId);
        if (null !== $routerResult) {
            return $routerResult;
        }

        // Tier 2: AI Fallback (MessageSorter LLM call)
        return $this->fallbackToAi($messageData, $conversationHistory, $userId, 'router_miss');
    }

    /**
     * Try the external synaplan-router (SetFit/ONNX) for classification.
     *
     * Returns a full classification result array when the external router
     * responds with sufficient confidence. Returns null when the router is
     * unavailable, disabled, or below the confidence threshold — the caller
     * should then fall through to the AI fallback.
     */
    private function tryExternalRouter(string $messageText, array $conversationHistory, ?int $userId): ?array
    {
        $lastTopic = $this->getLastTopicFromHistory($conversationHistory);
        $result = $this->routerClient->classify($messageText, null, $lastTopic);

        if (null === $result) {
            return null;
        }

        $confidence = $result['confidence'];
        $threshold = $this->routerClient->getConfidenceThreshold();

        if ($confidence < $threshold) {
            $this->logger->info('SynapseRouter: External router below threshold', [
                'use_case' => $result['use_case'],
                'confidence' => round($confidence, 4),
                'threshold' => $threshold,
            ]);

            return null;
        }

        $useCase = $result['use_case'];
        $alias = $this->topicAliasResolver->resolve($useCase);
        $canonicalTopic = $alias['topic'];
        $impliedMedia = $alias['media'];

        $language = $this->detectLanguage($messageText, null);
        $mediaType = $impliedMedia ?? ('mediamaker' === $canonicalTopic ? $this->detectMediaType($messageText) : null);
        $promptMetadata = $this->loadPromptMetadata($canonicalTopic, $userId ?? 0);

        $promptToolInternet = $promptMetadata['tool_internet'] ?? null;

        // For compound requests the policy applies to step 1 (executed
        // synchronously). The router's step plan is the authoritative
        // signal here — fall back to topic-based policy for single-step.
        if ($result['is_compound'] && !empty($result['steps'])) {
            $firstStep = $result['steps'][0];
            $webSearch = (bool) ($firstStep['web_search'] ?? false);
        } else {
            $webSearch = WebSearchTopicPolicy::shouldSearch($canonicalTopic, $promptToolInternet)
                && !WebSearchTopicPolicy::isNonWebSearchTopic($useCase);
        }

        $this->logger->info('SynapseRouter: External router classification', [
            'use_case' => $useCase,
            'canonical_topic' => $canonicalTopic,
            'confidence' => round($confidence, 4),
            'is_compound' => $result['is_compound'],
            'steps_count' => count($result['steps']),
            'model_version' => $result['model_version'],
            'router_latency_ms' => $result['latency_ms'],
        ]);

        $classification = [
            'topic' => $canonicalTopic,
            'granular_topic' => $useCase,
            'language' => $language,
            'web_search' => $webSearch,
            'media_type' => $mediaType,
            'raw_response' => sprintf('Router: %s (%.4f)', $useCase, $confidence),
            'prompt_metadata' => $promptMetadata,
            'source' => 'synapse_external_router',
            'synapse_score' => $confidence,
            'synapse_latency_ms' => $result['latency_ms'],
            'sorting_model_id' => null,
            'sorting_provider' => 'synaplan-router',
            'sorting_model_name' => $result['model_version'],
            'classification_source' => 'setfit',
            'classification_confidence' => $confidence,
        ];

        if ($result['is_compound'] && !empty($result['steps'])) {
            $classification['router_steps'] = $result['steps'];
            $classification['is_compound'] = true;
        }

        return $classification;
    }

    /**
     * Fall back to the traditional AI-based MessageSorter.
     *
     * Also resolves any granular topic the AI may emit (`coding`, `image-generation`, ...)
     * down to the canonical legacy topic so downstream handlers see a stable name.
     */
    private function fallbackToAi(
        array $messageData,
        array $conversationHistory,
        ?int $userId,
        string $reason,
    ): array {
        $this->logger->info('SynapseRouter: Falling back to AI sort', [
            'reason' => $reason,
        ]);

        $result = $this->messageSorter->classify($messageData, $conversationHistory, $userId);
        $result['source'] = 'synapse_ai_fallback';
        $result['synapse_fallback_reason'] = $reason;

        $rawTopic = (string) ($result['topic'] ?? '');
        if ('' !== $rawTopic) {
            $alias = $this->topicAliasResolver->resolve($rawTopic);
            if (null !== $alias['alias_source']) {
                $result['granular_topic'] = $alias['alias_source'];
                $result['topic'] = $alias['topic'];
                if (null !== $alias['media'] && empty($result['media_type'])) {
                    $result['media_type'] = $alias['media'];
                }
            }
        }

        return $result;
    }

    private function checkRuleBasedRouting(string $messageText, array $conversationHistory, int $userId): ?string
    {
        $prompts = $this->promptRepository->findPromptsWithSelectionRules($userId, '');

        foreach ($prompts as $prompt) {
            if ($this->promptService->matchesSelectionRules($prompt->getSelectionRules(), $messageText, $conversationHistory)) {
                return $prompt->getTopic();
            }
        }

        return null;
    }

    private function getLastTopicFromHistory(array $conversationHistory): ?string
    {
        if (empty($conversationHistory)) {
            return null;
        }

        $lastMsg = end($conversationHistory);
        $topic = $lastMsg->getTopic();

        if (empty($topic) || 'unknown' === $topic) {
            return null;
        }

        return $topic;
    }

    /**
     * Detect language using word-frequency heuristics.
     * Falls back to the existing BLANG value or 'en'.
     */
    private function detectLanguage(string $text, ?string $existingLang): string
    {
        if (null !== $existingLang && '' !== $existingLang && 'NN' !== $existingLang) {
            return $existingLang;
        }

        $words = preg_split('/\s+/', mb_strtolower($text));
        if (false === $words || count($words) < 3) {
            return 'en';
        }

        $wordSet = array_flip($words);
        $bestLang = 'en';
        $bestCount = 0;

        foreach (self::LANGUAGE_MARKERS as $lang => $markers) {
            $count = count(array_intersect_key($wordSet, array_flip($markers)));

            if ($count > $bestCount) {
                $bestCount = $count;
                $bestLang = $lang;
            }
        }

        return $bestCount >= 2 ? $bestLang : 'en';
    }

    private function detectMediaType(string $text): string
    {
        $lower = mb_strtolower($text);

        foreach (self::MEDIA_KEYWORDS as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $type;
                }
            }
        }

        return 'image';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPromptMetadata(string $topic, int $userId): array
    {
        $promptData = $this->promptService->getPromptWithMetadata($topic, $userId);

        return $promptData['metadata'] ?? [];
    }

    private function elapsed(float $start): float
    {
        return round((microtime(true) - $start) * 1000, 2);
    }
}
