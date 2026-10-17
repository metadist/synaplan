<?php

namespace App\Service\Message\Classification;

use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\Message\Routing\RoutingDecision;
use App\Service\Message\Routing\RoutingLayer;
use Psr\Log\LoggerInterface;

/**
 * The fast path (BCONFIG CLASSIFIER.FAST_PATH_ENABLED, off by default): an
 * obviously plain chat message (short, no attachment, no office format, no
 * media, audio or data-source words, no follow-up on generated work) skips
 * the AI sorter, but only when its language is certain. Anything ambiguous
 * goes on to the sorter.
 */
final readonly class FastPathClassifier
{
    /**
     * Media / media-generation triggers across the major UI languages
     * (EN/DE/ES/FR/IT/TR + a little PT). If any appear, the sorter may
     * pick a topic other than `general`/`chat` (e.g. mediamaker →
     * image_generation), so don't shortcut.
     *
     * Two families of trigger:
     *   1. Imperative verbs ("generate", "zeichne", "dibuja", …).
     *   2. Declarative / polite NOUN phrases ("image of", "bild einer",
     *      "una imagen de", …). These matter because a request like
     *      "hätte ich gerne das bild einer katze" carries NO imperative
     *      verb, so without the noun phrases it slipped past the
     *      fast-path, got classified as `general`/chat, and the chat
     *      model fabricated a (broken) markdown image instead of routing
     *      to the media generator. Same class of bug as the German
     *      imperative miss in #952, just a different phrasing.
     *
     * A false positive here only costs one extra AI-sorter call (it will
     * correctly fall back to chat), so we err on the generous side.
     */
    private const MEDIA_TRIGGERS = [
        // English
        'generate ', 'create ', 'draw ', 'paint ', 'sketch ', 'render ',
        'make a picture', 'make an image', 'make a video', 'make a song',
        'image of', 'picture of', 'photo of', 'illustration of',
        'an image', 'a picture', 'a photo', 'a drawing', 'an illustration',
        'a wallpaper', 'a logo', 'an icon',
        // German. `generiere`/`generier` cover the imperatives from #952;
        // the `bild …`/`foto …` noun phrases cover polite/declarative
        // requests like "hätte ich gerne das bild einer katze".
        'erstelle', 'erzeuge', 'zeichne', 'male ', 'rendere',
        'generiere', 'generier ', 'generiert ',
        'bild von', 'bild einer', 'bild eines', 'bild der', 'bild des',
        'bild mit', ' ein bild', 'foto von', 'foto einer', 'foto eines',
        ' ein foto', 'grafik von', 'grafik einer', 'zeichnung von',
        'illustration von', 'logo von', 'logo für', 'logo mit',
        // Spanish
        'genera ', 'crea ', 'dibuja ', 'imagen de', 'una imagen',
        'una foto', 'foto de', 'dibujo de', 'ilustración de', 'un logo',
        // French
        'génère', 'crée', 'dessine', 'image de', 'une image',
        'une photo', 'photo de', 'dessin de', 'illustration de', 'un logo',
        // Italian
        'crea un', 'genera un', 'disegna', 'immagine di', "un'immagine",
        'una immagine', 'foto di', 'disegno di',
        // Turkish (resim/görsel/fotoğraf = picture/visual/photo)
        'resim', 'resmi', 'resmini', 'görsel', 'görseli', 'fotoğraf',
        'çiz ', 'çizer misin', 'oluştur',
        // Portuguese
        'imagem de', 'uma imagem', 'uma foto', 'desenho de',
        'desenha', 'gera uma',
    ];

    /**
     * Audio / text-to-speech triggers. A request to read something aloud,
     * produce an MP3, narrate, or "say" something must reach the AI sorter
     * (→ mediamaker / BMEDIA=audio) AND, when it combines a content request
     * with an audio output ("write a love poem AND read it to me as an MP3"),
     * the multi-task planner. The fast-path emits source=fast_path_heuristic,
     * which TaskPlanExecutor treats as "single-node, no planning" — so a
     * shortcut here meant the chat model answered in prose and FABRICATED a
     * fake download link (https://files.example.com/...mp3) instead of
     * generating real audio. Deferring costs at most one extra sorter call.
     */
    private const AUDIO_TRIGGERS = [
        // English
        'mp3', 'wav', '.ogg', ' audio', 'audio ', 'read aloud', 'read it aloud',
        'read this aloud', 'say it', 'say this', 'speak ', 'spoken', 'voice over',
        'voiceover', 'text to speech', 'text-to-speech', ' tts', 'podcast',
        'narrate', 'narration', 'voice message', 'as speech', 'into speech',
        'out loud',
        // German
        'vorlesen', 'vorlies', 'lies vor', 'lies mir', 'lies das', 'vorgelesen',
        'sprich ', 'sprachausgabe', 'als sprache', 'audiodatei', 'audio datei',
        'sprachnachricht', 'vertone', 'vertonen', 'vertont', 'als hörbuch',
        'sprachversion', 'laut vor',
        // Spanish
        'en voz alta', 'léelo', 'leelo', 'lee en voz', 'audio de', 'a voz',
        // French
        'à voix haute', 'lis-moi', 'lis le', 'lire à voix', 'en audio',
        // Italian
        'ad alta voce', 'leggi ad', 'in audio',
    ];

    /**
     * Connected-data-source triggers. A message that references the
     * user's own knowledge base or a connected external system (CRM,
     * wiki, MCP server) must reach the AI sorter: the fast-path emits
     * source=fast_path_heuristic, which TaskPlanExecutor treats as
     * "single-node, no planning" — so a shortcut here would make the
     * multitask planner (and with it the `mcp_fetch` data node)
     * unreachable no matter what the user configured under
     * Manage → Connections → MCP Servers. Deferring costs one extra sorter call.
     */
    private const DATA_SOURCE_TRIGGERS = [
        // English
        'knowledge base', 'knowledgebase', ' my documents', ' my files',
        ' my notes', ' my crm', ' our crm', ' the crm', ' my wiki', ' our wiki',
        'mcp ', ' mcp',
        // German
        'wissensdatenbank', 'wissensbasis', 'meine dokumente', 'meinen dokumenten',
        'meine dateien', 'meinen dateien', 'meine notizen', 'unser crm', 'unser wiki',
        // Spanish
        'base de conocimiento', 'mis documentos', 'mis archivos', 'nuestro crm',
        // French
        'base de connaissances', 'mes documents', 'mes fichiers',
        // Italian
        'base di conoscenza', 'i miei documenti',
        // Turkish
        'bilgi taban', 'belgelerim', 'dosyalarım',
    ];

    public function __construct(
        private ConfigRepository $configRepository,
        private SelfAwareQuestion $selfAware,
        private LoggerInterface $logger,
        private ?OfficeConverterClient $officeConverter = null,
    ) {
    }

    /**
     * @param array<int, Message> $conversationHistory oldest-first thread
     *
     * @return array<string, mixed>|null the classification, or null to let the cascade go on
     */
    public function classify(Message $message, string $text, array $conversationHistory, ?int $overrideModelId, bool $forceWebSearch): ?array
    {
        // Phase 1c: fast-path. The full AI sorter call costs 200-800 ms TTFT
        // and is unnecessary for plain chat messages. If the message looks
        // unambiguously like a normal chat (short, no tool prefix, no
        // attachment, no media-generation keywords), we classify locally
        // with a regex/heuristic and skip the LLM entirely.
        // Falls through to the full sorter on any signal of ambiguity.
        if (null === $overrideModelId
            && !empty($text)
            && $this->isClassifierFastPathEnabled($message->getUserId())
            && $this->canFastPathClassify($message, $text, $conversationHistory)
        ) {
            // The fast-path skips the AI sorter, so it has to determine the
            // language itself. We only keep the latency win when we can
            // establish the language *without guessing*:
            //   1. a confident local text heuristic, else
            //   2. a language already pinned on the message (frontend UI
            //      locale or a previously detected turn; 'NN' is the entity
            //      default = unknown).
            // If neither is available we deliberately do NOT default to 'en'
            // — we fall through to the AI sorter below so the sorting model
            // defines BLANG. Guessing 'en' here is exactly how a German
            // "wer bist du?" got an English answer: the directive built
            // downstream from this value told the chat model to reply in
            // English.
            $confidentLanguage = ConfidentLanguage::of($message, $text);

            if (null !== $confidentLanguage) {
                $this->logger->info('MessageClassifier: Fast-path classification (skipped AI sorter)', [
                    'message_id' => $message->getId(),
                    'language' => $confidentLanguage,
                    'text_length' => strlen($text),
                ]);

                // `web_search` is intentionally null on the fast-path: it never
                // calls the AI sorter, so there is no BWEBSEARCH vote. With the
                // "trust the model" policy a missing vote means no search, so
                // these trivial chats answer immediately without a web round-trip.
                // An explicit per-message request (toggle, `/search`, "search
                // the web …") still searches via `WebSearchTopicPolicy`.
                $fastPathDecision = RoutingDecision::deterministic(RoutingLayer::FastPathHeuristic, 'general');

                $fastPath = array_merge([
                    'topic' => 'general',
                    'language' => $confidentLanguage,
                    'web_search' => null,
                    'source' => $fastPathDecision->toClassificationSource(),
                    'skip_sorting' => true,
                    'intent' => 'chat',
                    'model_id' => null,
                    'provider' => null,
                    'model_name' => null,
                ], $fastPathDecision->toClassificationFields());
                if ($forceWebSearch) {
                    $fastPath['force_web_search'] = true;
                }

                return $fastPath;
            }

            $this->logger->info('MessageClassifier: Fast-path declined (language ambiguous) — deferring to AI sorter', [
                'message_id' => $message->getId(),
                'text_length' => strlen($text),
            ]);
        }

        return null;
    }

    /**
     * Whether the Phase 1c fast-path is enabled (default: OFF — see below).
     *
     * TEMPORARILY DISABLED BY DEFAULT. The local regex/keyword heuristic
     * mis-routes requests it doesn't recognise (e.g. polite/declarative
     * media requests like "hätte ich gerne das bild einer katze") to
     * `general`/chat instead of the proper handler. Until the heuristic is
     * reworked we send EVERY message through the AI sorter (the model bound
     * to DEFAULTMODEL.SORT), which classifies far more reliably. The heuristic
     * code is intentionally KEPT, not removed — flip the default back to
     * `true` (or set BCONFIG `CLASSIFIER.FAST_PATH_ENABLED=1`) to re-enable.
     *
     * Read from BCONFIG group `CLASSIFIER`, key `FAST_PATH_ENABLED`. A
     * per-user row (BOWNERID = $userId) takes precedence over the global
     * row (BOWNERID = 0). Operators can therefore still opt INTO the
     * fast-path globally or per-account by inserting an explicit
     * `FAST_PATH_ENABLED=1` row, without touching this code.
     */
    private function isClassifierFastPathEnabled(int $userId): bool
    {
        if ($userId > 0) {
            $perUser = $this->configRepository->getValue($userId, 'CLASSIFIER', 'FAST_PATH_ENABLED');
            if (null !== $perUser) {
                return filter_var($perUser, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
            }
        }

        $value = $this->configRepository->getValue(0, 'CLASSIFIER', 'FAST_PATH_ENABLED');

        if (null === $value) {
            return false; // default-off (temporarily disabled — route everything via the AI sorter)
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
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

        if ($this->selfAware->matches($trimmed, $message->getUserId() > 0 ? $message->getUserId() : null)) {
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
        // pick `officemaker`. PDF is included only when the LibreOffice
        // engine is on — without it we still cannot produce real PDFs.
        $officeFormats = 'docx|xlsx|pptx|csv|word|excel|powerpoint|spreadsheet|tabellenkalkulation|praesentation|präsentation';
        if ($this->officePdfGenerationEnabled()) {
            $officeFormats .= '|pdf';
        }
        if (preg_match('/\b(?:'.$officeFormats.')\b/iu', $trimmed)) {
            return false;
        }

        // A follow-up on a just-generated document or picture is usually an
        // edit of it, which only the sorter routes to its handler (#1042).
        if ($this->followsGeneratedWork($conversationHistory, $trimmed)) {
            return false;
        }

        $lower = mb_strtolower($trimmed);
        if (self::mentionsAny($lower, self::MEDIA_TRIGGERS)) {
            return false;
        }

        if (self::mentionsAny($lower, self::AUDIO_TRIGGERS)) {
            return false;
        }

        if (self::mentionsAny($lower, self::DATA_SOURCE_TRIGGERS)) {
            return false;
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
     * Follow-up edits to a just-generated document are usually phrased
     * without naming the format again ("mach den Titel fett", "ändere das
     * in der Datei"). Two cases defer to the AI sorter so the edit reaches
     * `officemaker` instead of being shortcut to `general` (#1042 review):
     *
     * (a) The most recent assistant turn produced a file — the very next
     *     turn is almost certainly a follow-up about it, regardless of
     *     wording.
     * (b) A file was generated earlier in the thread and the current
     *     message references a document or its structure. This covers
     *     multi-message editing where normal chat is interleaved between
     *     edits ("...kannst du in der Datei den Titel ändern").
     *
     * The same two cases for generated MEDIA. Pictures and videos never
     * carry the `__FILE_GENERATED__:` marker the document guards key on —
     * they ride the message's file path — so "mach es blau" right after an
     * image generation missed every trigger below and was shortcut to
     * `general`, where the chat model can only talk about the picture
     * instead of editing it.
     *
     * @param array<int, Message> $conversationHistory oldest-first thread
     */
    private function followsGeneratedWork(array $conversationHistory, string $trimmed): bool
    {
        if (ThreadArtifacts::lastAssistantTurnIs($conversationHistory, ThreadArtifacts::isGeneratedFileMessage(...))) {
            return true;
        }

        if (ThreadArtifacts::anyAssistantTurnIs($conversationHistory, ThreadArtifacts::isGeneratedFileMessage(...))
            && ThreadArtifacts::mentionsDocumentReference($trimmed)) {
            return true;
        }

        if (ThreadArtifacts::lastAssistantTurnIs($conversationHistory, ThreadArtifacts::isGeneratedMediaMessage(...))) {
            return true;
        }

        if (ThreadArtifacts::anyAssistantTurnIs($conversationHistory, ThreadArtifacts::isGeneratedMediaMessage(...))
            && ThreadArtifacts::mentionsMediaReference($trimmed)) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $triggers
     */
    private static function mentionsAny(string $lower, array $triggers): bool
    {
        foreach ($triggers as $trigger) {
            if (str_contains($lower, $trigger)) {
                return true;
            }
        }

        return false;
    }

    private function officePdfGenerationEnabled(): bool
    {
        return null !== $this->officeConverter && $this->officeConverter->isEnabled();
    }
}
