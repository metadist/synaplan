<?php

namespace App\Service\Message;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\Repository\MessageMetaRepository;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Message Classifier.
 *
 * High-level classifier that handles:
 * 1. Check for "Again" function (user-selected AI/prompt via BMESSAGEMETA)
 * 2. Check for tool commands (e.g., /pic, /vid, /search)
 * 3. Document or audio attachment → analyzefile / file_analysis (skip sorting, #595)
 * 4. Use MessageSorter for AI-based classification
 *
 * Workflow from legacy:
 * - If BMESSAGEMETA has PROMPTID set → use that directly (skip sorting)
 * - If message starts with "/" → tool command
 * - If attached document/audio → analyzefile
 * - Otherwise → use AI sorting
 */
final readonly class MessageClassifier
{
    private const TOOL_COMMANDS = [
        '/pic' => 'tools:pic',
        '/vid' => 'tools:vid',
        '/tts' => 'tools:tts',
        '/search' => 'tools:search',
        '/lang' => 'tools:lang',
        '/web' => 'tools:web',
        '/list' => 'tools:list',
        '/docs' => 'tools:filesort',
    ];

    /**
     * Threshold of stopword hits at which we consider the fast-path
     * language heuristic to have a confident, prior-overriding signal.
     *
     * Each stopword match scores 2, so 4 means "at least two distinctive
     * markers in the message".
     */
    private const LANGUAGE_HEURISTIC_CONFIDENT_THRESHOLD = 4;

    /**
     * Per-language stopword tables consulted by the fast-path heuristic.
     *
     * Order: most-distinctive markers first. Each table is intentionally
     * narrow — we want hits to be high-precision so the score is a usable
     * signal, not low-precision token overlap.
     *
     * The German list is deliberately longer than the others: issue #980
     * showed that short German chat messages ("das habe ich dir gegeben",
     * "ja genau", "zeig mir das") routinely fall through the old, smaller
     * list. The additional entries are German-distinctive (no false-match
     * with EN/FR/ES/IT stopwords) and cover the most common short-reply
     * shapes seen in production chat logs.
     *
     * @var array<string, list<string>>
     */
    private const LANGUAGE_STOPWORDS = [
        'de' => [
            'ich ', ' der ', ' die ', ' und ', ' nicht ', ' ist ', 'für ', 'können', 'möchte', 'über', 'wäre',
            // Issue #980: extend the German list so short messages have a
            // chance of crossing the confident threshold without relying
            // solely on the conversation prior.
            ' das ', ' dem ', ' den ', ' habe ', ' hab ', ' mit ', ' auf ',
            ' eine ', ' einen ', ' einem ', ' aber ', ' noch ', ' oder ',
            ' mir ', ' dir ', ' dich ', ' mich ', ' bitte ', ' danke ',
            ' auch ', ' werden ', ' worden ', 'schön', 'müssen',
        ],
        'en' => [
            ' the ', ' and ', ' you ', ' please ', ' what ', ' write ', "don't ", "i'm ", "i'll ",
        ],
        'fr' => [
            ' le ', ' la ', ' les ', ' un ', ' une ', ' est ', ' pour ', ' avec ', 'écrire', 'merci',
        ],
        'es' => [
            ' el ', ' la ', ' los ', ' las ', ' por ', ' para ', 'escribir', 'gracias',
        ],
        'it' => [
            ' il ', ' lo ', ' la ', ' gli ', ' una ', ' per ', 'scrivere', 'grazie',
        ],
    ];

    public function __construct(
        private MessageSorter $messageSorter,
        private SynapseRouter $synapseRouter,
        private TopicAliasResolver $topicAliasResolver,
        private MessageMetaRepository $messageMetaRepository,
        private ModelConfigService $modelConfigService,
        private ConfigRepository $configRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Classify message and determine routing.
     *
     * @param Message $message             Message entity
     * @param array   $conversationHistory Previous messages
     *
     * @return array ['topic' => string, 'language' => string, 'source' => string, 'skip_sorting' => bool]
     */
    public function classify(Message $message, array $conversationHistory = [], ?int $overrideModelId = null): array
    {
        $userId = $message->getUserId();
        $messageId = $message->getId();
        $text = $message->getText();

        $this->logger->info('MessageClassifier: Starting classification', [
            'message_id' => $messageId,
            'user_id' => $userId,
            'has_text' => !empty($text),
            'override_model_id' => $overrideModelId,
        ]);

        // Phase 1c: fast-path. The full AI sorter call costs 200-800 ms TTFT
        // and is unnecessary for plain chat messages. If the message looks
        // unambiguously like a normal chat (short, no tool prefix, no
        // attachment, no media-generation keywords), we classify locally
        // with a regex/heuristic and skip the LLM entirely.
        // Falls through to the full sorter on any signal of ambiguity.
        if (null === $overrideModelId
            && !empty($text)
            && $this->isClassifierFastPathEnabled($userId)
            && $this->canFastPathClassify($message, $text)
        ) {
            // Pass the conversation history so short messages with few
            // distinctive stopwords (e.g. "das habe ich dir gegeben") can
            // inherit the language detected in earlier turns instead of
            // falling back to 'en' and forcing the AI to switch language
            // mid-conversation (issue #980).
            $detectedLanguage = $this->detectLanguageHeuristic($text, $conversationHistory);

            $this->logger->info('MessageClassifier: Fast-path classification (skipped AI sorter)', [
                'message_id' => $messageId,
                'language' => $detectedLanguage,
                'text_length' => strlen($text),
            ]);

            // `web_search` is intentionally null on the fast-path: under
            // the project-wide policy the actual decision is made later
            // in `MessageProcessor::processStream()` via
            // `WebSearchTopicPolicy::shouldSearch()`, which combines the
            // resolved prompt's `tool_internet` flag with the topic
            // exclusion list. The fast-path has no access to prompt
            // metadata and must not pre-empt that decision (see #1000).
            return [
                'topic' => 'general',
                'language' => $detectedLanguage,
                'web_search' => null,
                'source' => 'fast_path_heuristic',
                'skip_sorting' => true,
                'intent' => 'chat',
                'model_id' => null,
                'provider' => null,
                'model_name' => null,
            ];
        }

        // 1. Check for "Again" function - user-selected AI/prompt
        $promptOverride = $this->checkPromptOverride($messageId);
        $modelOverride = $this->checkModelOverride($messageId);

        if ($modelOverride && !$promptOverride) {
            // "Again" with model but no prompt override
            // Detect model type from model tag and set topic accordingly
            $modelTag = $this->getModelTag($modelOverride);
            $topic = $this->mapModelTagToTopic($modelTag, $message->getTopic());
            $intent = $this->mapTopicToIntent($topic);

            $this->logger->info('MessageClassifier: Using model override with auto-detected topic', [
                'message_id' => $messageId,
                'model_id' => $modelOverride,
                'model_tag' => $modelTag,
                'detected_topic' => $topic,
                'intent' => $intent,
            ]);

            return [
                'topic' => $topic,
                'language' => $message->getLanguage() ?: 'en',
                'intent' => $intent,
                'source' => 'model_override_auto',
                'skip_sorting' => true,
                'model_id' => $modelOverride,
            ];
        }

        if ($promptOverride) {
            $this->logger->info('MessageClassifier: Using prompt override (Again function)', [
                'message_id' => $messageId,
                'prompt_id' => $promptOverride,
                'model_id' => $modelOverride,
            ]);

            $result = [
                'topic' => $promptOverride,
                'language' => $message->getLanguage() ?: 'en',
                'intent' => $this->mapTopicToIntent($promptOverride),
                'source' => 'prompt_override',
                'skip_sorting' => true,
            ];

            // Add model_id if user explicitly selected a model (Again)
            if ($modelOverride) {
                $result['model_id'] = $modelOverride;
            }

            return $result;
        }

        // 2. Check for tool commands
        if (!empty($text) && str_starts_with($text, '/')) {
            $toolTopic = $this->detectToolCommand($text);
            if ($toolTopic) {
                $this->logger->info('MessageClassifier: Tool command detected', [
                    'message_id' => $messageId,
                    'tool' => $toolTopic,
                ]);

                return [
                    'topic' => $toolTopic,
                    'language' => $message->getLanguage() ?: 'en',
                    'intent' => $this->mapTopicToIntent($toolTopic),
                    'source' => 'tool_command',
                    'skip_sorting' => true,
                ];
            }
        }

        // 3. Document / audio attachments → FileAnalysisHandler (ANALYZE model), before AI sorting (#595)
        if ($this->messageHasDocumentOrAudioAttachment($message)) {
            $this->logger->info('MessageClassifier: Forcing analyzefile route (document or audio attachment)', [
                'message_id' => $messageId,
            ]);

            return [
                'topic' => 'analyzefile',
                'language' => $message->getLanguage() ?: 'en',
                'intent' => 'file_analysis',
                'source' => 'attachment_document_or_audio',
                'skip_sorting' => true,
            ];
        }

        // 4. Use Synapse Routing (embedding-based with AI fallback)
        $messageData = $this->buildMessageData($message);
        $result = $this->isSynapseEnabled()
            ? $this->synapseRouter->route($messageData, $conversationHistory, $userId)
            : $this->messageSorter->classify($messageData, $conversationHistory, $userId);

        $source = $result['source'] ?? 'ai_sorting';

        // Resolve granular Synapse-v2 topics (image-generation, video-generation,
        // audio-generation, coding, general-chat) to their canonical legacy topics
        // BEFORE mapping to intent. The AI sorter (used when Synapse Routing is
        // OFF — the default) returns the granular topic from PromptCatalog, but
        // downstream code (mapTopicToIntent, handler resolution, BFILEPATH keys)
        // only understands canonical topics. Without this resolution all media-
        // generation requests fall back to `chat`/ChatHandler (#952).
        //
        // SynapseRouter already runs this resolver internally, so calling it
        // again here is idempotent for already-canonical topics — but it
        // guarantees the contract for the AI-sorter path too.
        $rawTopic = (string) ($result['topic'] ?? 'general');
        $alias = $this->topicAliasResolver->resolve($rawTopic);
        $canonicalTopic = $alias['topic'];
        $impliedMedia = $alias['media'];

        if (null !== $alias['alias_source']) {
            $this->logger->info('MessageClassifier: Resolved granular topic to canonical', [
                'message_id' => $messageId,
                'granular_topic' => $alias['alias_source'],
                'canonical_topic' => $canonicalTopic,
                'implied_media' => $impliedMedia,
            ]);
        }

        $this->logger->info('MessageClassifier: Classification complete', [
            'message_id' => $messageId,
            'topic' => $canonicalTopic,
            'granular_topic' => $alias['alias_source'],
            'language' => $result['language'],
            'web_search' => $result['web_search'] ?? false,
            'media_type' => $result['media_type'] ?? $impliedMedia,
            'duration' => $result['duration'] ?? null,
            'resolution' => $result['resolution'] ?? null,
            'source' => $source,
            'synapse_score' => $result['synapse_score'] ?? null,
            'raw_ai_response' => $result['raw_response'] ?? 'N/A',
        ]);

        $classification = [
            'topic' => $canonicalTopic,
            'language' => $result['language'],
            'web_search' => $result['web_search'] ?? false,
            'source' => $source,
            'skip_sorting' => false,
            'intent' => $this->mapTopicToIntent($canonicalTopic),
            'model_id' => $result['sorting_model_id'] ?? null,
            'provider' => $result['sorting_provider'] ?? null,
            'model_name' => $result['sorting_model_name'] ?? null,
        ];

        if (null !== $alias['alias_source']) {
            $classification['granular_topic'] = $alias['alias_source'];
        }

        if ($overrideModelId) {
            $classification['override_model_id'] = $overrideModelId;
        }

        // Pass through media_type if detected (for mediamaker topic). Prefer
        // the sorter's explicit BMEDIA value, fall back to the implied media
        // from the granular topic alias (image-generation → 'image', etc.) so
        // MediaGenerationHandler always knows which provider to invoke.
        $mediaType = $result['media_type'] ?? $impliedMedia;
        if (null !== $mediaType) {
            $classification['media_type'] = $mediaType;
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

        return $classification;
    }

    /**
     * Check for prompt override (Again function)
     * Returns prompt ID if set, null otherwise.
     */
    private function checkPromptOverride(int $messageId): ?string
    {
        $meta = $this->messageMetaRepository->findOneBy([
            'messageId' => $messageId,
            'metaKey' => 'PROMPTID',
        ]);

        if ($meta && !empty($meta->getMetaValue()) && 'tools:sort' !== $meta->getMetaValue()) {
            return $meta->getMetaValue();
        }

        return null;
    }

    /**
     * Check for model override (Again function with specific model)
     * Returns model ID if set, null otherwise.
     */
    private function checkModelOverride(int $messageId): ?int
    {
        $meta = $this->messageMetaRepository->findOneBy([
            'messageId' => $messageId,
            'metaKey' => 'MODEL_ID',
        ]);

        if ($meta && !empty($meta->getMetaValue())) {
            return (int) $meta->getMetaValue();
        }

        return null;
    }

    /**
     * Detect tool command from text.
     */
    private function detectToolCommand(string $text): ?string
    {
        foreach (self::TOOL_COMMANDS as $command => $topic) {
            if (str_starts_with($text, $command)) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * Build message data array for sorter.
     */
    private function buildMessageData(Message $message): array
    {
        $data = [
            'BDATETIME' => $message->getDateTime(),
            'BFILEPATH' => $message->getFilePath(),
            'BTOPIC' => $message->getTopic() ?: '',
            'BLANG' => $message->getLanguage() ?: 'en',
            'BTEXT' => $message->getText(),
            'BFILETEXT' => $message->getFileText() ?: '',
            'BFILE' => $message->getFile(),
            'BWEBSEARCH' => 0,
        ];

        $fileType = $message->getFileType();
        if ('' !== $fileType) {
            $data['BFILETYPE'] = $fileType;
        }

        $attachedFiles = $message->getFiles();
        if ($attachedFiles->count() > 0) {
            $types = [];
            foreach ($attachedFiles as $file) {
                $types[] = $file->getFileType() ?: $file->getFileMime();
            }
            $data['BATTACHED_FILES'] = implode(', ', $types);
            $data['BATTACHED_COUNT'] = $attachedFiles->count();
        }

        return $data;
    }

    /**
     * Map topic to intent for handler routing.
     */
    private function mapTopicToIntent(string $topic): string
    {
        // Map BPROMPTS topics to InferenceRouter intents
        $topicToIntent = [
            // Media generation
            'mediamaker' => 'image_generation', // Handles images, videos, and audio
            'text2pic' => 'image_generation',
            'text2vid' => 'image_generation',
            'text2sound' => 'image_generation',
            'tools:pic' => 'image_generation', // /pic command
            'tools:vid' => 'image_generation', // /vid command
            'tools:tts' => 'image_generation', // /tts command (audio via MediaGenerationHandler)

            // Document/Office generation
            'officemaker' => 'document_generation',

            // Analysis
            'pic2text' => 'file_analysis',
            'analyze' => 'file_analysis',
            'analyzefile' => 'file_analysis',

            // Chat/General
            'general' => 'chat',
            'chat' => 'chat',

            // Add more mappings as needed
        ];

        return $topicToIntent[$topic] ?? 'chat'; // Default to chat
    }

    /**
     * Get model tag (capability) from model ID.
     */
    private function getModelTag(int $modelId): string
    {
        $model = $this->em->getRepository(\App\Entity\Model::class)->find($modelId);
        if ($model) {
            return $model->getTag();
        }

        return 'chat'; // fallback
    }

    /**
     * Map model tag to appropriate topic.
     */
    private function mapModelTagToTopic(string $modelTag, ?string $fallbackTopic): string
    {
        $tagToTopicMap = [
            'text2pic' => 'mediamaker',
            'text2vid' => 'mediamaker',
            'text2sound' => 'mediamaker',
            'pic2text' => 'general', // Vision is handled by ChatHandler
            'analyze' => 'general',
            'chat' => 'general',
            'vectorize' => 'general',
        ];

        return $tagToTopicMap[$modelTag] ?? ($fallbackTopic ?: 'general');
    }

    /**
     * True if the message has at least one attached document or audio file (not images only).
     * Routes to FileAnalysisHandler so ANALYZE default model is used (#595).
     */
    private function messageHasDocumentOrAudioAttachment(Message $message): bool
    {
        $files = $message->getFiles();
        if ($files->count() > 0) {
            foreach ($files as $file) {
                if ($this->attachedFileIsDocumentOrAudio($file)) {
                    return true;
                }
            }

            return false;
        }

        if ($message->getFile() > 0 && '' !== (string) $message->getFilePath()) {
            $ext = strtolower(pathinfo($message->getFilePath(), PATHINFO_EXTENSION));

            return in_array($ext, MessagePreProcessor::DOCUMENT_EXTENSIONS, true)
                || in_array($ext, MessagePreProcessor::AUDIO_EXTENSIONS, true);
        }

        return false;
    }

    private function attachedFileIsDocumentOrAudio(File $file): bool
    {
        $fromType = strtolower($file->getFileType() ?: '');
        $fromName = strtolower(pathinfo($file->getFileName(), PATHINFO_EXTENSION));
        $ext = '' !== $fromType ? $fromType : $fromName;

        if (in_array($ext, MessagePreProcessor::IMAGE_EXTENSIONS, true)) {
            return false;
        }

        return in_array($ext, MessagePreProcessor::DOCUMENT_EXTENSIONS, true)
            || in_array($ext, MessagePreProcessor::AUDIO_EXTENSIONS, true);
    }

    /**
     * Whether the Phase 1c fast-path is enabled (default: true).
     *
     * Read from BCONFIG group `CLASSIFIER`, key `FAST_PATH_ENABLED`. A
     * per-user row (BOWNERID = $userId) takes precedence over the global
     * row (BOWNERID = 0). Operators who notice mis-routing can disable
     * globally; users who need richer classification (e.g. heavy
     * media-generation traffic that shouldn't go through the chat
     * handler) can opt out per-account by inserting their own BCONFIG
     * row.
     */
    private function isClassifierFastPathEnabled(int $userId): bool
    {
        if ($userId > 0) {
            $perUser = $this->configRepository->getValue($userId, 'CLASSIFIER', 'FAST_PATH_ENABLED');
            if (null !== $perUser) {
                return filter_var($perUser, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
            }
        }

        $value = $this->configRepository->getValue(0, 'CLASSIFIER', 'FAST_PATH_ENABLED');

        if (null === $value) {
            return true; // default-on
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * Decide whether a message can skip the AI sorter without risk of misroute.
     *
     * The conservative checks below mean only "obviously chat" messages take
     * the fast path. Anything ambiguous — files, tool prefixes, media verbs,
     * Synapse-enabled accounts — still gets the full classifier.
     */
    private function canFastPathClassify(Message $message, string $text): bool
    {
        // Synapse routing has its own embedding-based classifier and may surface
        // intent we'd lose with the heuristic (e.g. 'mediamaker' for "draw a
        // cat"). Defer to it when enabled.
        if ($this->isSynapseEnabled()) {
            return false;
        }

        // Files of any kind go through the full pipeline (vision/analyze/etc).
        if ($message->getFile() > 0 || $message->getFiles()->count() > 0) {
            return false;
        }

        $trimmed = trim($text);
        if ('' === $trimmed) {
            return false;
        }

        // Tool prefixes are already handled earlier in classify(); be defensive
        // in case the order changes.
        if (str_starts_with($trimmed, '/')) {
            return false;
        }

        // Long messages can hide intent — keep the full sorter for anything
        // over ~280 characters (Twitter limit feels right for a chat one-liner).
        if (mb_strlen($trimmed) > 280) {
            return false;
        }

        // Media / media-generation verbs in EN/DE/ES/FR. If any appear, the
        // sorter may pick a topic other than `general`/`chat` (e.g.
        // mediamaker → image_generation), so don't shortcut.
        // The list is intentionally narrow to avoid false negatives in normal
        // chat ("describe the picture I just saw" → fine to fast-path).
        static $mediaTriggers = [
            'generate ', 'create ', 'draw ', 'paint ', 'sketch ', 'render ',
            'make a picture', 'make an image', 'make a video', 'make a song',
            'image of', 'picture of', 'photo of', 'illustration of',
            // German imperatives. `generiere`/`generier` cover "generiere
            // ein bild...", "generier mir...", "generiert eine grafik..."
            // which would otherwise slip past the fast-path and get
            // misclassified as `general`/chat (#952).
            'erstelle', 'erzeuge', 'zeichne', 'male ', 'rendere',
            'generiere', 'generier ', 'generiert ',
            'genera ', 'crea ', 'dibuja ',
            'génère', 'crée', 'dessine',
        ];
        $lower = mb_strtolower($trimmed);
        foreach ($mediaTriggers as $trigger) {
            if (str_contains($lower, $trigger)) {
                return false;
            }
        }

        // Note: there is no longer a `$searchTriggers` blocklist here.
        // It was a workaround for the fast-path's inability to decide
        // `web_search` on its own — under the project-wide policy
        // "search unless explicitly opted out", the fast-path no longer
        // needs to defer to the slow AI sorter just to surface results
        // (see issue #1000). The actual search decision is owned by
        // `WebSearchTopicPolicy` and applied in `MessageProcessor`.
        return true;
    }

    /**
     * Cheap language heuristic for fast-path classification.
     *
     * The full AI sorter detects language too — we lose that signal when we
     * skip it, so reproduce a "good enough" guess locally. Uses common
     * stopwords as anchors. When the stopword signal is weak, falls back
     * to the conversation's prior language (issue #980) so a short German
     * follow-up like "das habe ich dir gegeben" doesn't snap the
     * conversation to English just because it lacks distinctive stopwords.
     *
     * Decision order:
     *   1. Confident heuristic hit (score >= CONFIDENT_THRESHOLD) → use it
     *   2. Prior language from recent IN-direction history → use it
     *   3. Weak heuristic best guess (any non-zero score) → use it
     *   4. Final fallback → 'en'
     *
     * IMPORTANT: returns a 2-character ISO code, NEVER `'auto'`. The
     * `'auto'` sentinel is used elsewhere in the pipeline (fixed-prompt
     * widget mode) for the system-prompt directive only and is NOT
     * persistable to `BMESSAGES.BLANG` (varchar(2)). My fast-path
     * classification result flows through paths that DO persist BLANG
     * (e.g. WebhookController email reply), so leaking `'auto'` here
     * triggers SQLSTATE[22001] "Data too long for column 'BLANG'" on the
     * outgoing message insert. Stick to ISO codes.
     *
     * @param array<int, Message|array<string, mixed>> $conversationHistory recent thread, oldest → newest; used as a language prior when the current message is too short to classify confidently
     */
    private function detectLanguageHeuristic(string $text, array $conversationHistory = []): string
    {
        $scores = $this->scoreLanguageHeuristic($text);
        $best = array_key_first($scores);
        $bestScore = $scores[$best];

        // 1. Strong heuristic signal → trust it. Threshold of 4 means at
        //    least two distinctive stopwords (each scores 2). This keeps the
        //    behaviour for messages with clear linguistic markers identical
        //    to the original implementation.
        if ($bestScore >= self::LANGUAGE_HEURISTIC_CONFIDENT_THRESHOLD) {
            return $best;
        }

        // 2. Weak signal → look at the conversation prior. Short messages
        //    with few stopwords are exactly where the heuristic struggles
        //    (issue #980: "das habe ich dir gegeben" only matches "ich").
        //    Reuse the language we already detected for earlier user turns
        //    instead of snapping back to 'en' and breaking the conversation
        //    language mid-flow.
        $priorLanguage = $this->detectPriorLanguageFromHistory($conversationHistory);
        if (null !== $priorLanguage) {
            return $priorLanguage;
        }

        // 3. Heuristic found *something* but it's below the confident
        //    threshold AND we have no prior. Prefer the best guess over a
        //    blind 'en' default — for a single-hit message like "merci",
        //    'fr' is a far better guess than 'en'.
        if ($bestScore > 0) {
            return $best;
        }

        // 4. No signal anywhere → 'en' as last-resort default. The 2-char
        //    constraint matters: BMESSAGES.BLANG is varchar(2), so even
        //    though the existing 'auto' sentinel works for in-memory routing,
        //    it'd break any downstream code that persists
        //    $classification['language'] to BLANG (email webhook reply,
        //    queue-mode chat persistence, ...).
        return 'en';
    }

    /**
     * Score each known language against the message text.
     *
     * Returns the score map sorted descending by score, so
     * `array_key_first()` is the best guess. Separated from
     * {@see detectLanguageHeuristic()} so tests and the prior-fallback
     * logic can inspect raw scores without re-implementing the loop.
     *
     * @return array<string, int> language code → hit score, sorted desc
     */
    private function scoreLanguageHeuristic(string $text): array
    {
        $lower = ' '.mb_strtolower($text).' ';

        $hits = ['de' => 0, 'en' => 0, 'fr' => 0, 'es' => 0, 'it' => 0];
        foreach (self::LANGUAGE_STOPWORDS as $lang => $stopwords) {
            foreach ($stopwords as $w) {
                if (str_contains($lower, $w)) {
                    $hits[$lang] += 2;
                }
            }
        }

        arsort($hits);

        return $hits;
    }

    /**
     * Pick the conversation's prior language from recent user (IN) turns.
     *
     * Walks the history backwards and tallies the language stored on each
     * incoming message (set by the classifier on previous turns). Returns
     * the most-frequent language across the last few user turns, or `null`
     * when no usable signal is available.
     *
     * Why we only look at IN-direction messages: the assistant's outgoing
     * messages inherit the classification language too, but counting them
     * would double-weight every turn. Sticking to the user side keeps the
     * prior aligned with whatever language the *user* actually writes in.
     *
     * Why we ignore 'NN' and 'auto': both are sentinels meaning "no
     * language assigned yet" (entity default and widget auto-mode
     * respectively); neither is a usable language prior.
     *
     * @param array<int, Message|array<string, mixed>> $conversationHistory thread, oldest → newest. Plain arrays (sometimes used by callers building synthetic history) are ignored — only Message entities carry the persisted BLANG we need.
     */
    private function detectPriorLanguageFromHistory(array $conversationHistory): ?string
    {
        if (empty($conversationHistory)) {
            return null;
        }

        // Tally the language stored on the last N user-direction messages.
        // 5 turns is a balance: enough to smooth out a single odd entry,
        // short enough to react when the user switches language mid-chat.
        $maxTurnsConsidered = 5;
        $tally = [];
        $considered = 0;

        foreach (array_reverse($conversationHistory) as $msg) {
            if (!$msg instanceof Message) {
                continue;
            }
            if ('IN' !== $msg->getDirection()) {
                continue;
            }

            $lang = $msg->getLanguage();
            // Reject sentinels and anything that is not a 2-char ISO code.
            // 'NN' is the BLANG default for unclassified rows; 'auto' is
            // the widget-mode sentinel. Both leak through if we don't
            // explicitly filter them here.
            if ('' === $lang || 'NN' === $lang || 'auto' === $lang || 2 !== strlen($lang)) {
                continue;
            }

            $tally[$lang] = ($tally[$lang] ?? 0) + 1;
            ++$considered;
            if ($considered >= $maxTurnsConsidered) {
                break;
            }
        }

        if (empty($tally)) {
            return null;
        }

        arsort($tally);

        return array_key_first($tally);
    }

    /**
     * Synapse Routing is currently a BETA feature and OFF by default.
     *
     * Why off-by-default:
     *   - The embedding-based router can mis-route in edge cases (sticky topic
     *     after a file analysis turn, granular vs canonical topics, models
     *     with mismatched dimensions, ...).
     *   - Operators must explicitly opt-in via the admin UI / system config.
     *
     * The proven AI-sorter (`MessageSorter`) remains the default routing
     * path. The toggle is read from BCONFIG group `QDRANT_SEARCH`, key
     * `SYNAPSE_ROUTING_ENABLED`.
     */
    public function isSynapseEnabled(): bool
    {
        $value = $this->configRepository->getValue(0, 'QDRANT_SEARCH', 'SYNAPSE_ROUTING_ENABLED');

        if (null === $value) {
            return false;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }
}
