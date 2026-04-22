<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Repository\ConfigRepository;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\VectorSearch\QdrantClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Synapse Router — embedding-based message classification with AI fallback.
 *
 * Tier 1: Embed the user message, search synapse_topics in Qdrant for the
 *         closest topic. If confidence is high enough, route immediately (~50ms).
 * Tier 2: Fall back to MessageSorter (full LLM call) when confidence is low.
 *
 * Also handles language detection, web-search intent, and media-type
 * classification via lightweight heuristics instead of LLM calls.
 */
final readonly class SynapseRouter
{
    private const DEFAULT_CONFIDENCE_THRESHOLD = 0.78;
    private const STICKY_THRESHOLD = 0.65;
    private const VECTOR_DIMENSION = 1024;
    private const SEARCH_LIMIT = 5;
    private const MIN_SCORE = 0.3;

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

    private const WEB_SEARCH_KEYWORDS = [
        'aktuell', 'current', 'heute', 'today', 'news', 'nachrichten',
        'wetter', 'weather', 'preis', 'price', 'kosten', 'cost',
        'neueste', 'latest', 'kürzlich', 'recently', 'jetzt', 'now',
        'live', 'echtzeit', 'realtime', 'real-time',
        'öffnungszeiten', 'opening hours', 'restaurant', 'geschäft', 'store',
        'aktienk', 'stock price', 'börse', 'exchange',
    ];

    /** Year patterns that indicate need for current information */
    private const YEAR_PATTERN = '/\b(202[4-9]|203\d)\b/';

    /**
     * Common language-specific words for n-gram-free detection.
     * Ordered by frequency of occurrence in typical messages.
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
        private QdrantClientInterface $qdrantClient,
        private AiFacade $aiFacade,
        private MessageSorter $messageSorter,
        private PromptService $promptService,
        private PromptRepository $promptRepository,
        private ModelConfigService $modelConfigService,
        private ConfigRepository $configRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Route a message using embedding similarity (Tier 1) with AI fallback (Tier 2).
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

        // Step 1: Check rule-based routing (same as MessageSorter, takes priority)
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
                    'web_search' => $promptMetadata['tool_internet'] ?? false,
                    'raw_response' => 'Synapse: Rule-based routing',
                    'prompt_metadata' => $promptMetadata,
                    'source' => 'synapse_rule',
                    'model_id' => null,
                    'provider' => null,
                    'model_name' => null,
                ];
            }
        }

        // Step 2: Conversation-sticky — reuse topic if still relevant
        $lastTopic = $this->getLastTopicFromHistory($conversationHistory);

        // Step 3: Embed message and search Qdrant
        try {
            $embeddingOptions = $this->getEmbeddingOptions();
            $result = $this->aiFacade->embed($messageText, $userId, $embeddingOptions);
            /** @var float[] $queryVector */
            $queryVector = $result['embedding'];

            if (empty($queryVector)) {
                return $this->fallbackToAi($messageData, $conversationHistory, $userId, 'empty_embedding');
            }

            $queryVector = $this->normalizeVector($queryVector);

            $searchResults = $this->qdrantClient->searchSynapseTopics(
                $queryVector,
                $userId ?? 0,
                self::SEARCH_LIMIT,
                self::MIN_SCORE,
            );

            if (empty($searchResults)) {
                return $this->fallbackToAi($messageData, $conversationHistory, $userId, 'no_search_results');
            }

            $topResult = $searchResults[0];
            $topScore = $topResult['score'];
            $topTopic = $topResult['payload']['topic'] ?? 'general';

            $confidenceThreshold = $this->getConfidenceThreshold();

            $this->logger->info('SynapseRouter: Qdrant search result', [
                'top_topic' => $topTopic,
                'top_score' => round($topScore, 4),
                'results_count' => count($searchResults),
                'threshold' => $confidenceThreshold,
            ]);

            // Conversation-sticky: keep the topic if it's still among the candidates
            if ($lastTopic && $topTopic !== $lastTopic) {
                foreach ($searchResults as $candidate) {
                    $candidateTopic = $candidate['payload']['topic'] ?? '';
                    if ($candidateTopic === $lastTopic && $candidate['score'] >= self::STICKY_THRESHOLD) {
                        $this->logger->info('SynapseRouter: Conversation-sticky applied', [
                            'kept_topic' => $lastTopic,
                            'would_have_been' => $topTopic,
                            'sticky_score' => round($candidate['score'], 4),
                        ]);

                        $topTopic = $lastTopic;
                        $topScore = $candidate['score'];

                        break;
                    }
                }
            }

            // Confidence check
            if ($topScore < $confidenceThreshold) {
                return $this->fallbackToAi(
                    $messageData,
                    $conversationHistory,
                    $userId,
                    'low_confidence',
                    $topScore,
                    $topTopic,
                );
            }

            // Tier 1 success: classify with heuristics
            $language = $this->detectLanguage($messageText, $messageData['BLANG'] ?? null);
            $webSearch = $this->detectWebSearchIntent($messageText);
            $mediaType = ('mediamaker' === $topTopic) ? $this->detectMediaType($messageText) : null;

            $promptMetadata = $this->loadPromptMetadata($topTopic, $userId ?? 0);

            if (!$webSearch && ($promptMetadata['tool_internet'] ?? false)) {
                $webSearch = true;
            }

            $latencyMs = $this->elapsed($startTime);

            $this->logger->info('SynapseRouter: Tier 1 classification', [
                'topic' => $topTopic,
                'score' => round($topScore, 4),
                'language' => $language,
                'web_search' => $webSearch,
                'media_type' => $mediaType,
                'source' => 'synapse_embedding',
                'latency_ms' => $latencyMs,
            ]);

            return [
                'topic' => $topTopic,
                'language' => $language,
                'web_search' => $webSearch,
                'media_type' => $mediaType,
                'raw_response' => sprintf('Synapse: %.4f confidence', $topScore),
                'prompt_metadata' => $promptMetadata,
                'source' => $lastTopic === $topTopic ? 'synapse_sticky' : 'synapse_embedding',
                'synapse_score' => $topScore,
                'synapse_latency_ms' => $latencyMs,
                'model_id' => null,
                'provider' => null,
                'model_name' => null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('SynapseRouter: Embedding/search failed, falling back to AI', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackToAi($messageData, $conversationHistory, $userId, 'exception');
        }
    }

    /**
     * Fall back to the traditional AI-based MessageSorter.
     */
    private function fallbackToAi(
        array $messageData,
        array $conversationHistory,
        ?int $userId,
        string $reason,
        ?float $bestScore = null,
        ?string $bestTopic = null,
    ): array {
        $this->logger->info('SynapseRouter: Falling back to AI sort', [
            'reason' => $reason,
            'best_score' => $bestScore ? round($bestScore, 4) : null,
            'best_topic' => $bestTopic,
        ]);

        $result = $this->messageSorter->classify($messageData, $conversationHistory, $userId);
        $result['source'] = 'synapse_ai_fallback';
        $result['synapse_fallback_reason'] = $reason;

        if (null !== $bestScore) {
            $result['synapse_best_score'] = $bestScore;
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
            $count = 0;
            foreach ($markers as $marker) {
                if (isset($wordSet[$marker])) {
                    ++$count;
                }
            }

            if ($count > $bestCount) {
                $bestCount = $count;
                $bestLang = $lang;
            }
        }

        return $bestCount >= 2 ? $bestLang : 'en';
    }

    private function detectWebSearchIntent(string $text): bool
    {
        $lower = mb_strtolower($text);

        foreach (self::WEB_SEARCH_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return 1 === preg_match(self::YEAR_PATTERN, $text);
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

    /**
     * @return array{provider?: string, model?: string}
     */
    private function getEmbeddingOptions(): array
    {
        $modelId = $this->modelConfigService->getDefaultModel('VECTORIZE', null);
        if (!$modelId) {
            return [];
        }

        $options = [];
        $provider = $this->modelConfigService->getProviderForModel($modelId);
        $model = $this->modelConfigService->getModelName($modelId);

        if ($provider) {
            $options['provider'] = $provider;
        }
        if ($model) {
            $options['model'] = $model;
        }

        return $options;
    }

    /**
     * @param float[] $vector
     *
     * @return float[]
     */
    private function normalizeVector(array $vector): array
    {
        $len = count($vector);
        if (self::VECTOR_DIMENSION === $len) {
            return $vector;
        }

        if ($len > self::VECTOR_DIMENSION) {
            return array_slice($vector, 0, self::VECTOR_DIMENSION);
        }

        return array_pad($vector, self::VECTOR_DIMENSION, 0.0);
    }

    private function getConfidenceThreshold(): float
    {
        $value = $this->configRepository->getValue(0, 'QDRANT_SEARCH', 'SYNAPSE_CONFIDENCE_THRESHOLD');

        if (null !== $value && is_numeric($value)) {
            return (float) $value;
        }

        return self::DEFAULT_CONFIDENCE_THRESHOLD;
    }

    private function elapsed(float $start): float
    {
        return round((microtime(true) - $start) * 1000, 2);
    }
}
