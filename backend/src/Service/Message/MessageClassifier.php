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

    public function __construct(
        private MessageSorter $messageSorter,
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
            && $this->canFastPathClassify($message, $text, $conversationHistory)
        ) {
            $detectedLanguage = $this->detectLanguageHeuristic($text);

            $this->logger->info('MessageClassifier: Fast-path classification (skipped AI sorter)', [
                'message_id' => $messageId,
                'language' => $detectedLanguage,
                'text_length' => strlen($text),
            ]);

            // `web_search` is intentionally null on the fast-path: it never
            // calls the AI sorter, so there is no BWEBSEARCH vote. With the
            // "trust the model" policy a missing vote means no search, so
            // these trivial chats answer immediately without a web round-trip.
            // An explicit prompt `tool_internet=true` still forces search
            // later in `MessageProcessor` via `WebSearchTopicPolicy`.
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

        // 4. Classify with the LLM AI sorter (DEFAULTMODEL.SORT).
        $messageData = $this->buildMessageData($message);
        $result = $this->messageSorter->classify($messageData, $conversationHistory, $userId);

        $source = $result['source'] ?? 'ai_sorting';

        // The AI sorter returns a canonical topic (general, mediamaker,
        // officemaker, docsummary, …) that downstream code (mapTopicToIntent,
        // handler resolution, BFILEPATH keys) understands directly.
        $canonicalTopic = (string) ($result['topic'] ?? 'general');

        $this->logger->info('MessageClassifier: Classification complete', [
            'message_id' => $messageId,
            'topic' => $canonicalTopic,
            'language' => $result['language'],
            'web_search' => $result['web_search'] ?? false,
            'media_type' => $result['media_type'] ?? null,
            'duration' => $result['duration'] ?? null,
            'resolution' => $result['resolution'] ?? null,
            'source' => $source,
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

        if ($overrideModelId) {
            $classification['override_model_id'] = $overrideModelId;
        }

        // Pass through media_type if the sorter set BMEDIA (for the mediamaker
        // topic) so MediaGenerationHandler knows which provider to invoke.
        $mediaType = $result['media_type'] ?? null;
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
     * the fast path. Anything ambiguous — files, tool prefixes, media verbs —
     * still gets the full classifier.
     *
     * @param array<int, Message> $conversationHistory oldest-first thread
     */
    private function canFastPathClassify(Message $message, string $text, array $conversationHistory = []): bool
    {
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

        // Document-generation requests are usually very short and would
        // otherwise be shortcut to `general` ("schreibe es als docx",
        // "mach eine excel tabelle", #1042 review). If the message names a
        // supported office format/extension, defer to the AI sorter so it can
        // pick `officemaker`. PDF is intentionally excluded: we cannot produce
        // real PDFs, so we must not route PDF requests to the office maker.
        if (preg_match('/\b(docx|xlsx|pptx|csv|word|excel|powerpoint|spreadsheet|tabellenkalkulation|praesentation|präsentation)\b/iu', $trimmed)) {
            return false;
        }

        // Follow-up edits to a just-generated document are usually phrased
        // without naming the format again ("mach den Titel fett", "ändere das
        // in der Datei"). Two cases defer to the AI sorter so the edit reaches
        // `officemaker` instead of being shortcut to `general` (#1042 review):
        //
        // (a) The most recent assistant turn produced a file — the very next
        //     turn is almost certainly a follow-up about it, regardless of
        //     wording.
        // (b) A file was generated earlier in the thread and the current
        //     message references a document or its structure. This covers
        //     multi-message editing where normal chat is interleaved between
        //     edits ("...kannst du in der Datei den Titel ändern").
        if ($this->lastAssistantGeneratedFile($conversationHistory)) {
            return false;
        }

        if ($this->threadHasGeneratedFile($conversationHistory)
            && $this->mentionsDocumentReference($trimmed)) {
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

        // Note: there is no `$searchTriggers` blocklist here. The fast-path
        // deliberately answers trivial chats without a web search — under the
        // "trust the model" policy a fast-pathed message carries no BWEBSEARCH
        // vote and therefore does not search. Messages that genuinely need
        // live data are longer / less trivial and fall through to the AI
        // sorter, which votes for search itself.
        return true;
    }

    /**
     * Whether the most recent assistant turn in the thread produced a generated
     * file (its stored content is the "__FILE_GENERATED__:filename" marker).
     *
     * Only the latest assistant message is considered so the deferral is
     * limited to the turn directly following a file generation.
     *
     * @param array<int, Message> $conversationHistory oldest-first thread
     */
    private function lastAssistantGeneratedFile(array $conversationHistory): bool
    {
        for ($i = count($conversationHistory) - 1; $i >= 0; --$i) {
            $msg = $conversationHistory[$i];
            if ('OUT' !== $msg->getDirection()) {
                continue;
            }

            return str_starts_with((string) $msg->getText(), '__FILE_GENERATED__:');
        }

        return false;
    }

    /**
     * Whether any assistant turn in the thread produced a generated file.
     *
     * @param array<int, Message> $conversationHistory
     */
    private function threadHasGeneratedFile(array $conversationHistory): bool
    {
        foreach ($conversationHistory as $msg) {
            if ('OUT' === $msg->getDirection()
                && str_starts_with((string) $msg->getText(), '__FILE_GENERATED__:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the message refers to a document or one of its structural parts.
     *
     * Used together with {@see threadHasGeneratedFile()} to detect document
     * edits that span multiple turns. The noun list is intentionally
     * document-specific to keep false positives in normal chat low; the worst
     * case of a false positive is one extra AI-sorter call.
     */
    private function mentionsDocumentReference(string $text): bool
    {
        return 1 === preg_match(
            '/\b(datei|dokument|file|document|doc|tabelle|sheet|spreadsheet|folie|slide|'
            .'titel|title|überschrift|ueberschrift|heading|spalte|column|zeile|row|zelle|cell)\b/iu',
            $text
        );
    }

    /**
     * Cheap language heuristic for fast-path classification.
     *
     * The full AI sorter detects language too — we lose that signal when we
     * skip it, so reproduce a "good enough" guess locally. Uses common
     * stopwords as anchors, falls back to `'en'` when too ambiguous.
     *
     * IMPORTANT: returns a 2-character ISO code, NEVER `'auto'`. The
     * `'auto'` sentinel is used elsewhere in the pipeline (fixed-prompt
     * widget mode) for the system-prompt directive only and is NOT
     * persistable to `BMESSAGES.BLANG` (varchar(2)). My fast-path
     * classification result flows through paths that DO persist BLANG
     * (e.g. WebhookController email reply), so leaking `'auto'` here
     * triggers SQLSTATE[22001] "Data too long for column 'BLANG'" on the
     * outgoing message insert. Stick to ISO codes.
     */
    private function detectLanguageHeuristic(string $text): string
    {
        // Normalize first: collapse every run of non-letters to a single
        // space, then pad with spaces. Without this, punctuation-hugging
        // tokens defeat the space-delimited anchors below — e.g.
        // "wer bist du?" lowercases to " wer bist du? " and the " du " /
        // "du?" mismatch meant the most common German phrase scored 0 and
        // fell back to English. Letters (incl. umlauts) survive; everything
        // else becomes a separator.
        $normalized = preg_replace('/[^\p{L}]+/u', ' ', mb_strtolower($text)) ?? '';
        $lower = ' '.trim($normalized).' ';

        $hits = [
            'de' => 0, 'en' => 0, 'fr' => 0, 'es' => 0, 'it' => 0,
        ];

        // German: stopwords, question words, pronouns, common verbs and
        // greetings. These short function words are what carry the signal in
        // a one-line chat ("wer bist du?", "wie geht es dir?", "danke").
        foreach ([
            ' ich ', ' der ', ' die ', ' das ', ' und ', ' nicht ', ' ist ', ' für ',
            ' können ', ' kannst ', ' möchte ', ' über ', ' wäre ', ' mit ', ' auch ',
            ' wer ', ' wie ', ' was ', ' warum ', ' wo ', ' wann ', ' welche ', ' welcher ',
            ' du ', ' bist ', ' bin ', ' sind ', ' hast ', ' habe ', ' mir ', ' mich ',
            ' dich ', ' dein ', ' deine ', ' machst ', ' heißt ', ' gibt ', ' soll ',
            ' mein ', ' eine ', ' einen ', ' hallo ', ' danke ', ' bitte ', ' guten ',
        ] as $w) {
            if (str_contains($lower, $w)) {
                $hits['de'] += 2;
            }
        }
        // English: stopwords, question words, pronouns, common verbs and
        // greetings.
        foreach ([
            ' the ', ' and ', ' you ', ' please ', ' what ', ' write ', ' dont ', ' im ', ' ill ',
            ' who ', ' how ', ' why ', ' where ', ' when ', ' which ',
            ' are ', ' is ', ' do ', ' does ', ' can ', ' your ', ' me ', ' my ', ' a ', ' an ',
            ' hello ', ' hi ', ' hey ', ' thanks ', ' thank ',
        ] as $w) {
            if (str_contains($lower, $w)) {
                $hits['en'] += 2;
            }
        }
        // French.
        foreach ([
            ' le ', ' la ', ' les ', ' un ', ' une ', ' est ', ' pour ', ' avec ', ' écrire ',
            ' qui ', ' quoi ', ' comment ', ' pourquoi ', ' es ', ' tu ', ' merci ', ' bonjour ',
        ] as $w) {
            if (str_contains($lower, $w)) {
                $hits['fr'] += 2;
            }
        }
        // Spanish.
        foreach ([
            ' el ', ' la ', ' los ', ' las ', ' por ', ' para ', ' escribir ',
            ' quién ', ' qué ', ' cómo ', ' por qué ', ' eres ', ' gracias ', ' hola ',
        ] as $w) {
            if (str_contains($lower, $w)) {
                $hits['es'] += 2;
            }
        }
        // Italian.
        foreach ([
            ' il ', ' lo ', ' la ', ' gli ', ' una ', ' per ', ' scrivere ',
            ' chi ', ' cosa ', ' come ', ' perché ', ' sei ', ' grazie ', ' ciao ',
        ] as $w) {
            if (str_contains($lower, $w)) {
                $hits['it'] += 2;
            }
        }

        arsort($hits);
        $best = array_key_first($hits);
        $bestScore = $hits[$best];

        // A single distinctive anchor (score 2) is already a strong signal
        // for a short chat one-liner, so we trust it; below that we have no
        // signal and fall back to 'en'. The 2-char constraint matters:
        // BMESSAGES.BLANG is varchar(2), so even though the 'auto' sentinel
        // works for in-memory routing, it'd break any downstream code that
        // persists $classification['language'] to BLANG (email webhook reply,
        // queue-mode chat persistence, ...).
        return $bestScore >= 2 ? $best : 'en';
    }
}
