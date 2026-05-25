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
    /**
     * Code-side fallback for the Tier-1 confidence threshold, used only
     * when no `QDRANT_SEARCH.SYNAPSE_CONFIDENCE_THRESHOLD` row is present
     * in BCONFIG. Kept in lockstep with `SystemConfigService`'s UI default
     * (`0.78`) so the runtime behaviour does not change depending on
     * whether an admin has touched the setting (raised by Copilot review
     * on PR #853).
     */
    private const DEFAULT_CONFIDENCE_THRESHOLD = 0.78;
    private const STICKY_THRESHOLD = 0.32;
    /**
     * Fallback target dimension used only when the bound SYNAPSE_VECTORIZE
     * model has no `meta.dimensions` in the catalog — matches the
     * historical default the synapse_topics collection was created with.
     * The real dimension is resolved per-request from the model row, see
     * `getCurrentModelInfo()` (PR #853 review).
     */
    private const DEFAULT_VECTOR_DIMENSION = 1024;
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

    /**
     * Generic creation verbs ("create", "erstelle", "render", …) that
     * the media-intent guard pairs with a modality-specific noun.
     *
     * Kept narrow on purpose so messages with desire-only verbs ("ich
     * möchte eine Kette gründen", "I want a small business") cannot pass.
     * Anchored with `\b` at match time so "drawer" / "renderer" don't
     * count as the verb. See {@see passesMediaIntentGuard()}.
     */
    private const MEDIA_INTENT_VERBS = [
        // English
        'create', 'creating', 'generate', 'generating', 'make', 'making',
        'render', 'rendering', 'produce', 'producing',
        'draw', 'drawing', 'paint', 'painting', 'sketch', 'sketching',
        'illustrate', 'illustrating',
        'animate', 'animating',
        'edit', 'editing', 'retouch', 'retouching',
        'compose', 'composing', 'composite', 'compositing',
        'compile', 'compiling',
        'narrate', 'narrating', 'speak', 'speaking', 'voiceover', 'voice-over',
        'convert',
        // German
        'erstelle', 'erstell', 'erstellt', 'erstellen',
        'generiere', 'generier', 'generiert', 'generieren',
        'mache', 'mach', 'macht', 'machen',
        'male', 'mal', 'malt', 'malen',
        'zeichne', 'zeichn', 'zeichnet', 'zeichnen',
        'animiere', 'animier', 'animiert', 'animieren',
        'sprich', 'sprecht', 'sprechen',
        'vertone', 'vertont', 'vertonen',
    ];

    /**
     * Modality-specific media nouns. Paired with a creation verb (or a
     * self-contained cue) the guard counts as passed. Singular tokens
     * only — substring matching handles plurals/cases ("videos", "Bilder").
     *
     * @var array<string, list<string>>
     */
    private const MEDIA_INTENT_NOUNS = [
        'image-generation' => [
            'image', 'picture', 'photo', 'foto', 'bild', 'illustration', 'sketch',
            'drawing', 'painting', 'render', 'logo', 'icon', 'wallpaper', 'avatar',
            'thumbnail', 'cover', 'poster', 'artwork',
        ],
        'video-generation' => [
            'video', 'clip', 'film', 'animation', 'animatic', 'cinemagraph',
            'kurzfilm', 'reel',
        ],
        'audio-generation' => [
            'audio', 'voice', 'voiceover', 'narration', 'speech',
            'tts', 'mp3', 'wav', 'podcast', 'sprachausgabe', 'vertonung',
        ],
    ];

    /**
     * Self-contained cues that imply both verb AND noun on their own.
     * Mostly TTS-style "read this aloud", "lies vor", "vorlesen" — these
     * don't repeat the noun explicitly but unambiguously request audio
     * output. Same for "text to speech" / "text-to-speech" wording.
     *
     * Matched with substring search after lowercasing.
     *
     * @var array<string, list<string>>
     */
    private const MEDIA_INTENT_SELF_CONTAINED = [
        'image-generation' => [
            'replace the background', 'hintergrund ersetzen', 'bild bearbeiten',
            'edit this image', 'edit the image',
        ],
        'video-generation' => [
            // Intentionally empty: video creation requests in the wild
            // virtually always name "video"/"clip"/"film"/etc., so the
            // verb+noun rule is sufficient.
        ],
        'audio-generation' => [
            'read aloud', 'reading aloud', 'read it aloud', 'read this aloud',
            'text to speech', 'text-to-speech', 'convert to speech',
            'lies vor', 'lies mir vor', 'lies das vor', 'lies dies vor',
            'vorlesen', 'vorzulesen',
            'sprich folgenden text', 'sprich folgendes',
            'vertone diesen text', 'vertone folgenden text', 'vertonen',
        ],
    ];

    /**
     * Keywords that strongly indicate the user needs live/current data.
     *
     * Philosophy: "rather search than not". For timeless explanations the
     * keyword list misses on purpose; for anything time-sensitive, factual,
     * locational or news-/event-driven we want to opt in. Keep stems short
     * (e.g. `kost`, `wahl`, `flug`) so they catch German conjugations and
     * compounds without listing every form.
     *
     * Intentionally excludes deictic time markers like "jetzt" / "now" which
     * are extremely common in follow-up requests ("jetzt das in blau", "jetzt
     * ein video davon", "now make it 4K") and almost never imply a web search.
     *
     * The German cost stem is `kost` (not `kosten`): the bare infinitive
     * `kosten` does not appear in the most common phrasing "Was kostet …?"
     * and `str_contains('kostet', 'kosten') === false`. The shorter stem
     * also catches `kostet`/`gekostet`/`kostete` while leaving compounds
     * like `unkosten` matched too. See issue #974.
     */
    private const WEB_SEARCH_KEYWORDS = [
        // Time / recency
        'aktuell', 'current', 'heute', 'today', 'gestern', 'yesterday',
        'morgen früh', 'tomorrow', 'diese woche', 'this week', 'dieser monat', 'this month',
        'kürzlich', 'recently', 'neueste', 'latest', 'neuesten', 'newest',
        'live', 'echtzeit', 'realtime', 'real-time',

        // News / current affairs
        'news', 'nachrichten', 'schlagzeile', 'headline', 'meldung',
        'breaking', 'eilmeldung',

        // Weather / climate
        'wetter', 'weather', 'temperatur', 'temperature', 'regen ', 'rainfall',
        'sturm', 'storm', 'klima',

        // Prices / costs / shopping (kost stem catches kostet/gekostet/kostete)
        'preis', 'price', 'kost', 'cost', 'wie teuer', 'how much', 'how expensive',
        'angebot', 'rabatt', 'discount', 'sale',

        // Real estate / housing (the original failing-prompt domain)
        'eigentumswohnung', 'wohnung kauf', 'wohnung mieten', 'wohnung in ',
        'immobilie', 'immobilien', 'haus kauf', 'haus mieten',
        'mietpreis', 'kaufpreis', 'mietspiegel', 'quadratmeterpreis',
        'apartment for ', 'property in ', 'real estate',

        // Travel / transit
        'flug', 'flüge', 'flight ', 'hotel', 'reise', 'urlaub',
        'visa ', 'einreise', 'fähre', 'bahn ', 'zug nach', 'train to',
        'ticket', 'preisvergleich',

        // Finance / markets / crypto
        'aktie', 'aktien', 'aktienk', 'stock price', 'börse', 'exchange',
        'kurs ', 'wechselkurs', 'exchange rate',
        'zins', 'inflation', 'rendite', 'dividende',
        'bitcoin', 'ethereum', 'krypto', 'crypto',

        // Politics / government / elections
        'politik', 'politics', 'wahl', 'election', 'bundeskanzler', 'kanzler',
        'präsident', 'president', 'regierung', 'government', 'minister',
        'partei', 'koalition', 'parlament', 'parliament', 'gesetz',
        'sanktion', 'sanctions', 'eu-', 'nato',

        // Sports / scores / leagues.
        //
        // No bare `wm `/`em ` here: as 2-letter substring matches they would
        // fire on extremely common English/German words ending in `em` or
        // `wm` followed by a space — `system `, `problem `, `theorem `,
        // `them `, `poem `. Use the unambiguous full stems `europameister`
        // (also matches `europameisterschaft`) and `weltmeister` (also
        // matches `weltmeisterschaft`) for the tournament context.
        'spielstand', 'ergebnis', 'tabelle', 'liga', 'bundesliga', 'champions league',
        'europameister', 'weltmeister', 'turnier', 'olympia', 'olympic',
        'transfer', 'kader',

        // Conflict / crisis / safety.
        //
        // No bare `war ` here: it would substring-match the extremely
        // common German past-tense form of `sein` (e.g. `Wer war Einstein?`,
        // `Was war das?`, `Das war nett.`). `krieg` covers the German
        // domain and `conflict`/`crisis` cover the English side; English
        // war-news queries almost always pair with `today`/`current`/year
        // patterns which already trigger.
        'krieg', 'konflikt', 'conflict', 'krise', 'crisis',
        'pandemie', 'pandemic', 'unfall', 'accident', 'katastrophe',

        // Local / locations / places-of-business
        'öffnungszeiten', 'opening hours', 'restaurant', 'geschäft', 'store',
        'adresse', 'telefonnummer', 'kontakt ', 'route nach', 'route to',
        'in der nähe', 'nearby', 'in meiner nähe',

        // Reviews / comparisons / recommendations.
        //
        // No bare `best `/`beste ` here: they collide with the very common
        // English sign-off `best regards`/`best wishes`, the German polite
        // form `beste Grüße`, and casual comparatives like `die beste
        // Lösung`. `top 10` covers explicit listicle queries; recommendation
        // questions about live products usually also trigger via `vergleich`,
        // a domain keyword, or the year pattern.
        'bewertung', 'review', 'vergleich', 'comparison', 'test ', 'testbericht',
        'empfehlung', 'recommendation', 'top 10',

        // Technology releases / availability
        'release', 'released', 'erscheint', 'erscheinungsdatum',
        'verfügbar in', 'available in',
    ];

    /** Year patterns that indicate need for current information */
    private const YEAR_PATTERN = '/\b(202[4-9]|203\d)\b/';

    /**
     * Topics where automatic web-search heuristics are nonsensical because
     * the handler purely generates assets (image/video/audio/document) and
     * does not consume web context. Web search for these topics is only
     * activated when a prompt explicitly opts in via `tool_internet`.
     *
     * Includes both canonical legacy topics and the granular Synapse-v2
     * topics, so this stays correct even if `TopicAliasResolver` is bypassed.
     */
    private const NON_WEB_SEARCH_TOPICS = [
        'mediamaker',
        'image-generation',
        'video-generation',
        'audio-generation',
        'text2pic',
        'text2vid',
        'text2sound',
        'officemaker',
        'text2doc',
    ];

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
        private TopicAliasResolver $topicAliasResolver,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Public entry point used by routing test/dry-run endpoints.
     *
     * Returns the Top-K candidate topics with raw Qdrant scores, **before**
     * any confidence threshold or sticky logic is applied. Useful for the
     * admin "Test Routing" box and CLI debugging.
     *
     * @return array{
     *     query: string,
     *     model: array{provider: ?string, model: ?string, model_id: ?int},
     *     candidates: list<array{topic: string, score: float, payload: array, stale: bool, alias_target: ?string}>,
     *     latency_ms: float,
     *     error: ?string,
     * }
     */
    public function dryRun(string $messageText, ?int $userId = null, int $limit = 5): array
    {
        $startTime = microtime(true);
        $modelInfo = $this->getCurrentModelInfo();

        $base = [
            'query' => $messageText,
            'model' => $modelInfo,
            'candidates' => [],
            'latency_ms' => 0.0,
            'error' => null,
        ];

        if ('' === trim($messageText)) {
            return ['error' => 'empty_message'] + $base;
        }

        try {
            $embeddingOptions = $this->getEmbeddingOptions();
            $result = $this->aiFacade->embed($messageText, $userId, $embeddingOptions);
            /** @var float[] $vector */
            $vector = $result['embedding'];

            if (empty($vector)) {
                return ['error' => 'empty_embedding', 'latency_ms' => $this->elapsed($startTime)] + $base;
            }

            $vector = $this->normalizeVector($vector, $modelInfo['vector_dim']);

            $hits = $this->qdrantClient->searchSynapseTopics(
                $vector,
                $userId ?? 0,
                $limit,
                self::MIN_SCORE,
            );

            $candidates = [];
            $currentModelId = $modelInfo['model_id'];
            foreach ($hits as $hit) {
                $payload = $hit['payload'] ?? [];
                $topic = (string) ($payload['topic'] ?? '');
                $alias = $this->topicAliasResolver->resolve($topic);

                $candidates[] = [
                    'topic' => $topic,
                    'score' => (float) $hit['score'],
                    'payload' => $payload,
                    'stale' => $this->isStaleEntry($payload, $currentModelId),
                    'alias_target' => null !== $alias['alias_source'] ? $alias['topic'] : null,
                ];
            }

            return [
                'query' => $messageText,
                'model' => $modelInfo,
                'candidates' => $candidates,
                'latency_ms' => $this->elapsed($startTime),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('SynapseRouter::dryRun failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => 'exception: '.$e->getMessage(),
                'latency_ms' => $this->elapsed($startTime),
            ] + $base;
        }
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

                // Use the canonical `sorting_*` keys so MessageClassifier
                // can read them the same way it reads MessageSorter output.
                // Rule-based routing matched without any AI/embedding call,
                // so leave the model identifiers null — see issue #603.
                return [
                    'topic' => $ruleBasedTopic,
                    'language' => $messageData['BLANG'] ?? 'en',
                    'web_search' => $promptMetadata['tool_internet'] ?? false,
                    'raw_response' => 'Synapse: Rule-based routing',
                    'prompt_metadata' => $promptMetadata,
                    'source' => 'synapse_rule',
                    'sorting_model_id' => null,
                    'sorting_provider' => null,
                    'sorting_model_name' => null,
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

            $currentModelInfo = $this->getCurrentModelInfo();
            $queryVector = $this->normalizeVector($queryVector, $currentModelInfo['vector_dim']);

            $vectorSum = array_sum($queryVector);
            if (abs($vectorSum) < 0.001) {
                $this->logger->warning('SynapseRouter: Zero/degenerate vector detected', [
                    'vector_sum' => $vectorSum,
                    'first_5' => array_slice($queryVector, 0, 5),
                    'text_length' => strlen($messageText),
                ]);
            }

            $searchResults = $this->qdrantClient->searchSynapseTopics(
                $queryVector,
                $userId ?? 0,
                self::SEARCH_LIMIT,
                self::MIN_SCORE,
            );

            if (empty($searchResults)) {
                return $this->fallbackToAi($messageData, $conversationHistory, $userId, 'no_search_results');
            }

            $currentModelId = $currentModelInfo['model_id'];
            $freshResults = [];
            $staleHits = 0;

            foreach ($searchResults as $hit) {
                if ($this->isStaleEntry($hit['payload'] ?? [], $currentModelId)) {
                    ++$staleHits;
                    continue;
                }
                $freshResults[] = $hit;
            }

            if ($staleHits > 0) {
                $this->logger->info('SynapseRouter: Filtered stale-index hits', [
                    'stale_hits' => $staleHits,
                    'fresh_hits' => count($freshResults),
                    'current_model_id' => $currentModelId,
                ]);
            }

            if (empty($freshResults)) {
                return $this->fallbackToAi(
                    $messageData,
                    $conversationHistory,
                    $userId,
                    'stale_index',
                );
            }

            $searchResults = $freshResults;
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

            // Media-intent guard (#878): even at high confidence, do NOT
            // route to the dedicated media-generation topics unless the
            // user's text actually pairs a creation verb with a media
            // noun. This is what stops messages like "ich beschäftige
            // mich mit Protein Shakes…" from being interpreted as a
            // video request just because the embedding model happens to
            // place them near the video-generation centroid. Slash
            // commands never reach this code path — MessageClassifier
            // routes them directly.
            if (!$this->passesMediaIntentGuard($topTopic, $messageText)) {
                return $this->fallbackToAi(
                    $messageData,
                    $conversationHistory,
                    $userId,
                    'media_intent_guard',
                    $topScore,
                    $topTopic,
                );
            }

            // Resolve granular Synapse-v2 topic to canonical legacy topic
            // (e.g. coding -> general, image-generation -> mediamaker).
            // The granular topic stays in the synapse payload for analytics;
            // downstream handlers only ever see the canonical topic.
            $alias = $this->topicAliasResolver->resolve($topTopic);
            $canonicalTopic = $alias['topic'];
            $impliedMedia = $alias['media'];
            $aliasSource = $alias['alias_source'];

            // Tier 1 success: classify with heuristics
            $language = $this->detectLanguage($messageText, $messageData['BLANG'] ?? null);
            $mediaType = $impliedMedia ?? ('mediamaker' === $canonicalTopic ? $this->detectMediaType($messageText) : null);

            $promptMetadata = $this->loadPromptMetadata($canonicalTopic, $userId ?? 0);

            // Web-search activation:
            //   - For pure asset/document generation topics, the heuristic is
            //     meaningless (the handler does not consume web context). We
            //     only honor the explicit `tool_internet` opt-in there.
            //   - For all other topics we run the keyword/year heuristic and
            //     additionally honor the prompt's `tool_internet` flag.
            $skipHeuristic = $this->isNonWebSearchTopic($canonicalTopic) || $this->isNonWebSearchTopic($aliasSource ?? '');
            $webSearch = $skipHeuristic ? false : $this->detectWebSearchIntent($messageText);

            if (!$webSearch && ($promptMetadata['tool_internet'] ?? false)) {
                $webSearch = true;
            }

            $latencyMs = $this->elapsed($startTime);

            $this->logger->info('SynapseRouter: Tier 1 classification', [
                'topic' => $canonicalTopic,
                'granular_topic' => $aliasSource,
                'score' => round($topScore, 4),
                'language' => $language,
                'web_search' => $webSearch,
                'web_search_heuristic_skipped' => $skipHeuristic,
                'media_type' => $mediaType,
                'source' => 'synapse_embedding',
                'latency_ms' => $latencyMs,
            ]);

            // The embedding model IS the model that produced the routing
            // decision, so surface it under the `sorting_*` keys (drop-in
            // compatible with MessageSorter::classify()). Without this, the
            // outgoing message had no `ai_sorting_*` meta, so the Sorting
            // Model badge only ever appeared after a page refresh once the
            // backend was patched to also surface this from a different
            // code path — see issue #603.
            return [
                'topic' => $canonicalTopic,
                'granular_topic' => $aliasSource,
                'language' => $language,
                'web_search' => $webSearch,
                'media_type' => $mediaType,
                'raw_response' => sprintf('Synapse: %.4f confidence', $topScore),
                'prompt_metadata' => $promptMetadata,
                'source' => $lastTopic === $canonicalTopic ? 'synapse_sticky' : 'synapse_embedding',
                'synapse_score' => $topScore,
                'synapse_latency_ms' => $latencyMs,
                'sorting_model_id' => $currentModelInfo['model_id'],
                'sorting_provider' => $currentModelInfo['provider'],
                'sorting_model_name' => $currentModelInfo['model'],
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
     *
     * Also resolves any granular topic the AI may emit (`coding`, `image-generation`, ...)
     * down to the canonical legacy topic so downstream handlers see a stable name.
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

    /**
     * Snapshot of the embedding model currently bound to Synapse Routing.
     *
     * `vector_dim` is read from the catalog (`BJSON.meta.dimensions`)
     * so the router and indexer agree on the target dimension when the
     * bound model is not 1024-wide, instead of slice/zero-padding the
     * query vector down to 1024 and then searching a collection that
     * was created with the model's native size (PR #853 review).
     *
     * @return array{provider: ?string, model: ?string, model_id: ?int, vector_dim: int}
     */
    private function getCurrentModelInfo(): array
    {
        $modelId = $this->resolveSynapseModelId();
        if (!$modelId) {
            return [
                'provider' => null,
                'model' => null,
                'model_id' => null,
                'vector_dim' => self::DEFAULT_VECTOR_DIMENSION,
            ];
        }

        return [
            'provider' => $this->modelConfigService->getProviderForModel($modelId),
            'model' => $this->modelConfigService->getModelName($modelId),
            'model_id' => $modelId,
            'vector_dim' => $this->modelConfigService->getVectorDimForModel($modelId)
                ?? self::DEFAULT_VECTOR_DIMENSION,
        ];
    }

    /**
     * Resolve the BMODELS row id for the embedding model used by
     * Synapse Routing.
     *
     * Reads the global SYNAPSE_VECTORIZE binding so indexer and search
     * side stay on the same vector space. Falls back to the VECTORIZE
     * default for fresh installs that have not seeded the dedicated
     * binding yet.
     */
    private function resolveSynapseModelId(): ?int
    {
        $synapseId = $this->modelConfigService->getDefaultModel(SynapseIndexer::SYNAPSE_CAPABILITY, null);
        if ($synapseId) {
            return $synapseId;
        }

        $this->logger->warning('SynapseRouter: SYNAPSE_VECTORIZE binding missing, falling back to VECTORIZE default');

        return $this->modelConfigService->getDefaultModel('VECTORIZE', null);
    }

    /**
     * A Synapse hit is "stale" when its payload was indexed under a different
     * embedding model than the one currently configured. Without this check,
     * cross-model cosine scores are meaningless and would produce silent
     * mis-routing whenever an admin swaps the VECTORIZE default model.
     *
     * Backwards compatible: payloads without `embedding_model_id` (legacy
     * v1 indexing) are treated as fresh — they will be re-indexed lazily
     * the next time the topic content changes or the operator runs
     * `synapse:index --force`.
     */
    private function isStaleEntry(array $payload, ?int $currentModelId): bool
    {
        $indexedModelId = $payload['embedding_model_id'] ?? null;
        if (null === $indexedModelId) {
            return false;
        }

        if (null === $currentModelId) {
            return false;
        }

        return (int) $indexedModelId !== (int) $currentModelId;
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
            // array_intersect_key returns the matched markers; counting
            // them sidesteps a PHPStan narrowing on PHP 8.4 that infers
            // a manual `++$count` inside `if (isset(…))` as a 0|1 bool
            // instead of the unbounded int it actually is. Net behaviour
            // is identical — both forms count distinct marker hits.
            $count = count(array_intersect_key($wordSet, array_flip($markers)));

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

    /**
     * True when the topic is a pure asset/document generation topic where
     * the web-search heuristic must not run automatically (only explicit
     * `tool_internet` opt-in is honored).
     */
    private function isNonWebSearchTopic(string $topic): bool
    {
        return '' !== $topic && in_array($topic, self::NON_WEB_SEARCH_TOPICS, true);
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
     * Verify that a Tier-1 hit on a media-generation topic is justified
     * by an explicit creation cue in the user's text (issue #878).
     *
     * Non-media topics are passed through unchanged. For media topics we
     * accept the routing iff EITHER:
     *   - the message contains a self-contained modality cue (e.g.
     *     "lies vor" → audio is the only sensible interpretation), OR
     *   - the message contains BOTH a generic creation verb AND a
     *     media noun for the matching modality.
     *
     * Verb-and-noun do NOT have to be adjacent: "erstelle ein aktuelles
     * Video von der Börse 2026" passes because "erstelle" + "video" are
     * both present, even though they're separated by "ein aktuelles".
     */
    private function passesMediaIntentGuard(string $topic, string $messageText): bool
    {
        if (!isset(self::MEDIA_INTENT_NOUNS[$topic])) {
            return true;
        }

        $lower = mb_strtolower($messageText);

        foreach (self::MEDIA_INTENT_SELF_CONTAINED[$topic] as $cue) {
            if (str_contains($lower, $cue)) {
                return true;
            }
        }

        if (!$this->containsCreationVerb($lower)) {
            return false;
        }

        foreach (self::MEDIA_INTENT_NOUNS[$topic] as $noun) {
            if (str_contains($lower, $noun)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the lowercased message contains any of {@see MEDIA_INTENT_VERBS}
     * as a whole-word match. The word-boundary check stops "drawer" /
     * "rendering of opinions" / "machart" from leaking through.
     */
    private function containsCreationVerb(string $lowerText): bool
    {
        foreach (self::MEDIA_INTENT_VERBS as $verb) {
            $pattern = '/\b'.preg_quote($verb, '/').'\b/u';
            if (1 === preg_match($pattern, $lowerText)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPromptMetadata(string $topic, int $userId): array
    {
        $promptData = $this->promptService->getPromptWithMetadata($topic, $userId);

        return $promptData['metadata'] ?? [];
    }

    private const QWEN3_QUERY_INSTRUCTION = 'Given a user message, retrieve the most relevant topic category for routing';

    /**
     * @return array{provider?: string, model?: string, instruction?: string}
     */
    private function getEmbeddingOptions(): array
    {
        $modelId = $this->resolveSynapseModelId();
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

            if (str_contains(strtolower($model), 'qwen')) {
                $options['instruction'] = self::QWEN3_QUERY_INSTRUCTION;
            }
        }

        return $options;
    }

    /**
     * Coerce a query embedding to the target collection dimension.
     *
     * Happy path: the bound model returns its native dim (read from
     * catalog metadata), the collection was created with the same dim,
     * and this is a no-op. Slice/zero-pad is a last-resort safety net
     * for catalog/provider mismatches; it is logged as a warning
     * because cosine similarity across coerced dims is semantically
     * weaker than across native ones (PR #853 review).
     *
     * @param float[] $vector
     *
     * @return float[]
     */
    private function normalizeVector(array $vector, int $targetDim): array
    {
        $len = count($vector);
        if ($targetDim === $len) {
            return $vector;
        }

        $this->logger->warning('SynapseRouter: query embedding dimension mismatch — coercing (catalog metadata likely wrong)', [
            'expected' => $targetDim,
            'actual' => $len,
        ]);

        if ($len > $targetDim) {
            return array_slice($vector, 0, $targetDim);
        }

        return array_pad($vector, $targetDim, 0.0);
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
