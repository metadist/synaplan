<?php

namespace App\Service\Message\Classification;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Document\DocumentKind;
use App\Service\Document\DocumentToolsConfig;
use App\Service\File\FileTypeResolver;
use App\Service\File\Office\DocumentThumbnailGenerator;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\Message\Routing\RoutingDecision;
use App\Service\Message\Routing\RoutingLayer;
use App\Service\Message\SpokenInput;
use App\Service\Multitask\MultitaskRoutingConfig;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * What a turn's attachments ask for, before the AI sorter: a document, audio
 * or video goes to file analysis (#595, #722), unless it is an office edit
 * (DOCUMENT_TOOLS.ALLOW_UPLOAD_EDIT), a merge or a PDF export the multi-task
 * engine runs as one node (#1694), or a demand to produce a new file, which
 * the planner arbitrates (#2047). Also tells the cascade whether a turn asks
 * to run code on its files or carries a picture.
 */
final readonly class AttachmentIntent
{
    /**
     * Explicit merge lexicon. Only verbs that mean "make ONE file out of
     * several" — a bare "speichern"/"save" is NOT one of them (see
     * {@see EXPORT_INTENT_PATTERN}).
     */
    private const MERGE_INTENT_PATTERN = '/(?:'
        .'\b(?:merge|combine|concatenate|zusammenführen|zusammenfügen|zusammenlegen|fusionner|combiner|combinar|fusionar|birleştir\w*)\b'
        .'|'
        .'\b(?:führe?|füge?|fügen)\b.{0,80}\bzusammen\b'
        .')/iu';

    /**
     * Explicit "turn this INTO a PDF" lexicon. Every alternative pairs a
     * conversion verb with the target format, so "speichere die PDFs im Ordner
     * X" (a save_to_folder request) no longer reads as an export.
     */
    private const EXPORT_INTENT_PATTERN = '/(?:'
        .'\b(?:export\w*|exportier\w*|convert\w*|konvertier\w*|umwandel\w*|wandle|exportar|convertir|convierte|enregistrer|dönüştür\w*)\b[^.!?]{0,40}\bpdfs?\b'
        .'|'
        .'\b(?:speicher\w*|save|guardar|guarda)\b\s*(?:\w+\s+){0,3}?\b(?:als|as|como|en|comme|olarak)\s+(?:eine[nmr]?\s+)?pdfs?\b'
        .'|'
        .'\b(?:als|as|hieraus|daraus)\s+(?:eine[nmr]?\s+)?pdfs?\b'
        .')/iu';

    /**
     * "…into ONE pdf". Turns an otherwise ambiguous export phrasing ("mach aus
     * beiden eine PDF") into a merge when several files are attached, while
     * "convert both files to PDF" (two separate PDFs) stays with the planner.
     */
    private const SINGLE_PDF_TARGET_PATTERN = '/\b(?:one|single|a\s+single|eine[nmr]?|un|una|seule|tek|einzige[nsr]?)\s+(?:\w+\s+){0,2}?pdfs?\b/iu';

    /**
     * Capabilities that only the planner can deliver alongside a merge. When a
     * message asks for one of them too ("merge both AND read it aloud"), the
     * deterministic single-node route would silently drop it (#1192), so we let
     * the planner see the whole turn instead.
     */
    private const COMPETING_INTENT_PATTERN = '/(?:'
        .'\b(?:aloud|audio|mp3|podcast|voice|vorlesen|vorlies\w*|sprich|audiodatei|sprachnachricht|voz|voix|sesli)\b'
        .'|\b(?:lies|liest)\b.{0,20}\bvor\b'
        .'|\bread\b.{0,20}\b(?:aloud|out\s+loud)\b'
        .'|\b(?:image|picture|photo|illustration|bild|grafik|imagen|foto|resim|görsel)\b'
        .'|\b(?:e-?mail|mail\s+it|maile?|verschick\w*|correo|courriel|posta)\b'
        .'|\b(?:folder|ordner|nextcloud|dropbox|carpeta|dossier|klasör)\b'
        .'|\b(?:translate|translation|übersetz\w*|traduc\w*|traduir\w*|çevir\w*)\b'
        .'|\b(?:summarize|summary|summarise|zusammenfassung|resum\w*|résum\w*|özetle\w*)\b'
        .'|\bfasse?\b.{0,40}\bzusammen\b'
        .')/iu';

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ?OfficeConverterClient $officeConverter = null,
        private ?DocumentToolsConfig $documentToolsConfig = null,
        private ?MultitaskRoutingConfig $multitaskConfig = null,
    ) {
    }

    /**
     * @return array<string, mixed>|null the classification, or null to let the cascade go on
     */
    public function route(Message $message, string $text): ?array
    {
        if (!$this->messageHasAnalyzableNonImageAttachment($message)) {
            return null;
        }

        $messageId = $message->getId();
        if ($this->shouldRouteOfficeEditToOfficemaker($message)) {
            $this->logger->info('MessageClassifier: Routing office edit to officemaker', [
                'message_id' => $messageId,
            ]);

            return [
                'topic' => 'officemaker',
                'language' => $message->getLanguage() ?: 'en',
                'intent' => 'document_generation',
                'source' => 'attachment_office_edit',
                'skip_sorting' => true,
            ];
        }
        $combineRoute = $this->attachmentDocumentFileIntent($message);
        if (null !== $combineRoute) {
            $this->logger->info('MessageClassifier: Routing attachment merge/export to '.$combineRoute, [
                'message_id' => $messageId,
            ]);

            // BTOPIC stays inside the prompt-topic namespace: `officemaker`
            // is the topic OFFICE_PDF_ROUTING already assigns to produce-a-PDF
            // turns, it has a seeded BPROMPTS row, and the chat info popover
            // links it to a real /ai/instructions entry. The capability is
            // selected from `intent` + `source`, not from the topic
            // ({@see TaskPlanExecutor::isDeterministicDocumentFileIntent()}).
            return [
                'topic' => 'officemaker',
                'language' => $message->getLanguage() ?: 'en',
                'intent' => $combineRoute,
                'source' => 'attachment_'.$combineRoute,
                'skip_sorting' => true,
            ];
        }
        if ($this->requestsFileProduction($message, $text)) {
            // Producing a NEW file from the attachment (chart from a
            // table, cleaned export) is not analysis — let the turn fall
            // through to the sorter. The multi_step force there sends it
            // to the planner, whose rule 3a arbitrates code_run vs
            // file_analysis (#2047).
            $this->logger->info('MessageClassifier: Produce-a-file demand skips analyzefile force-route', [
                'message_id' => $messageId,
            ]);

            return null;
        }

        $this->logger->info('MessageClassifier: Forcing analyzefile route (document, audio, or video attachment)', [
            'message_id' => $messageId,
        ]);

        $attachmentDecision = RoutingDecision::deterministic(RoutingLayer::AttachmentRule, 'analyzefile');

        return array_merge([
            'topic' => 'analyzefile',
            'language' => $message->getLanguage() ?: 'en',
            'intent' => 'file_analysis',
            'source' => $attachmentDecision->toClassificationSource(),
            'skip_sorting' => true,
        ], $attachmentDecision->toClassificationFields());
    }

    /**
     * Merge/export-as-PDF of attached office/PDF files must not be forced onto
     * analyzefile (#1694). Two or more combine-eligible files plus an explicit
     * merge prompt maps to `document_combine`. A single office file plus an
     * export-as-PDF prompt maps to `document_export` (the #1691 conversion).
     * Read or summarize prompts still return null and take analyzefile.
     *
     * Deliberately narrow: this route SKIPS the planner, so anything it claims
     * is delivered by exactly one server-side node and every other intent in
     * the same turn would be lost. Whenever the request is ambiguous or
     * compound we return null and let the planner decide — it knows
     * `document_combine` too (see {@see OfficePdfRoutingDecorator}).
     */
    private function attachmentDocumentFileIntent(Message $message): ?string
    {
        $text = trim((string) $message->getText());
        if ('' === $text) {
            return null;
        }

        $eligible = $this->combineEligibleAttachments($message);
        if ([] === $eligible) {
            return null;
        }

        // Neither capability exists without the multi-task engine: the legacy
        // InferenceRouter has no handler for these intents and would answer
        // with a plain chat turn that merely CLAIMS a PDF was produced.
        if (null === $this->multitaskConfig || !$this->multitaskConfig->isRoutingEnabled($message->getUserId())) {
            return null;
        }

        if (1 === preg_match(self::COMPETING_INTENT_PATTERN, $text)) {
            return null;
        }

        $wantsMerge = 1 === preg_match(self::MERGE_INTENT_PATTERN, $text);
        $wantsExport = 1 === preg_match(self::EXPORT_INTENT_PATTERN, $text);

        if (count($eligible) >= 2) {
            $wantsOnePdf = 1 === preg_match(self::SINGLE_PDF_TARGET_PATTERN, $text);

            return ($wantsMerge || ($wantsExport && $wantsOnePdf)) && $this->officeEngineReadyFor($eligible)
                ? 'document_combine'
                : null;
        }

        if ($wantsExport && !$wantsMerge && DocumentThumbnailGenerator::isOffice(DocumentThumbnailGenerator::extensionOf($eligible[0]))) {
            // Every single-file export runs through the converter, so without
            // an office engine the node can only fail.
            return $this->officeEngineReadyFor($eligible) ? 'document_export' : null;
        }

        return null;
    }

    /**
     * Whether the office engine is available for the conversions these sources
     * need. PDF-only merges never touch the converter; a single office source
     * makes it mandatory ({@see DocumentCombineService} throws
     * `engine_required` otherwise, and {@see DocumentExportRunner} hides its
     * capability from the planner for the same reason).
     *
     * @param list<File> $sources
     */
    private function officeEngineReadyFor(array $sources): bool
    {
        $needsEngine = false;
        foreach ($sources as $source) {
            if (DocumentThumbnailGenerator::isOffice(DocumentThumbnailGenerator::extensionOf($source))) {
                $needsEngine = true;
                break;
            }
        }

        return !$needsEngine || true === $this->officeConverter?->isEnabled();
    }

    /**
     * @return list<File>
     */
    private function combineEligibleAttachments(Message $message): array
    {
        $eligible = [];
        foreach ($message->getFiles() as $file) {
            $ext = DocumentThumbnailGenerator::extensionOf($file);
            if (DocumentThumbnailGenerator::isOffice($ext) || DocumentThumbnailGenerator::isPdf($ext)) {
                $eligible[] = $file;
            }
        }

        return $eligible;
    }

    /**
     * When DOCUMENT_TOOLS.ALLOW_UPLOAD_EDIT is on, an office attachment plus
     * an edit-intent prompt goes to officemaker instead of analyzefile.
     * Default-off so characterization snapshots stay unchanged.
     */
    private function shouldRouteOfficeEditToOfficemaker(Message $message): bool
    {
        if (null === $this->documentToolsConfig || !$this->documentToolsConfig->allowUploadEdit($message->getUserId())) {
            return false;
        }
        $text = trim((string) $message->getText());
        if ('' === $text) {
            return false;
        }
        if (!$this->messageHasOfficeDocumentAttachment($message)) {
            return false;
        }

        return 1 === preg_match(
            '/\b(edit|change|update|format|sort|insert|replace|merge|restyle|currency|conditional|'
            .'add (?:a |the )?(?:chart|sheet|column|row|slide|heading)|'
            .'bearbeiten|ändern|formatieren|einfügen|'
            .'editar|cambiar|modificar|'
            .'modifier|ajouter|'
            .'düzenle|değiştir|ekle)\b/iu',
            $text,
        );
    }

    private function messageHasOfficeDocumentAttachment(Message $message): bool
    {
        foreach ($message->getFiles() as $file) {
            $ext = $file->getFileType() ?: pathinfo($file->getFileName(), PATHINFO_EXTENSION);
            if (null !== DocumentKind::fromExtension((string) $ext)) {
                return true;
            }
        }
        if ($message->getFile() > 0) {
            $file = $this->em->getRepository(File::class)->find($message->getFile());
            if (null !== $file) {
                $ext = $file->getFileType() ?: pathinfo($file->getFileName(), PATHINFO_EXTENSION);
                if (null !== DocumentKind::fromExtension((string) $ext)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function messageHasAnalyzableNonImageAttachment(Message $message): bool
    {
        $files = $message->getFiles();
        if ($files->count() > 0) {
            foreach ($files as $file) {
                // A voice note whose transcript is the message text is the
                // user's words. Documents and other audio on the turn still count.
                if (SpokenInput::isSpokenAudio($message, $file)) {
                    continue;
                }
                if ($this->attachedFileIsAnalyzableNonImage($file)) {
                    return true;
                }
            }

            return false;
        }

        if ($message->getFile() > 0 && '' !== (string) $message->getFilePath()) {
            if (SpokenInput::isLegacySpokenAudio($message)) {
                return false;
            }

            $category = FileTypeResolver::resolveCategory(
                $message->getFileType() ?: '',
                '',
                (string) $message->getFilePath(),
            );

            return in_array($category, ['document', 'audio', 'video'], true);
        }

        return false;
    }

    /**
     * Explicit execution demand on attached files ("run python on ...",
     * "using Python, compute ...").
     *
     * Deliberately narrow on the trigger words but order-independent: it fires
     * only when a file is attached AND the message names a programming runtime
     * (python/node/script/code) AND an action verb, in either order. An
     * attachment alone (describe this image) or bare computation words
     * ("calculate 15%") must NOT force planning. Forcing the planner only skips
     * the single-step shortcut — the planner still picks code_run vs
     * file_analysis — so over-firing costs one planner call, never a wrong answer.
     */
    public function requestsCodeExecution(Message $message, string $text): bool
    {
        if ($message->getFile() <= 0 && $message->getFiles()->count() <= 0) {
            return false;
        }

        $namesRuntime = 1 === preg_match('/\b(python|node\.?js|node|javascript|script|code|program)\b/i', $text);
        if ($namesRuntime) {
            return 1 === preg_match('/\b(run|execute|compute|calculate|count|sum|total|process|parse|analy[sz]e|read|extract)\b/i', $text)
                || 1 === preg_match('/\b(führe|ausführen|ausfuehren|berechne|zähle|zaehle|verarbeite|lies|analysiere)\b/iu', $text);
        }

        // No runtime words: an attached document/image/table plus a
        // produce-a-file verb is still file work ("mach daraus ein
        // Balkendiagramm", "Stempel unten rechts drauf", "make a bar chart
        // from this table"). Without this arm such turns fall to the sorter,
        // which wobbles between file_analysis and the media generator and
        // sometimes denies a capability the installation has (#2047, #2049).
        return $this->requestsFileProduction($message, $text);
    }

    /**
     * Attached document/image/table plus a verb that produces a new file —
     * without naming a runtime. Questions about the file ("what does this
     * diagram show", "make me a summary") must not match: bare nouns fire
     * only for unambiguous compounds, otherwise the produce verb must pair
     * with a chart/file object (Turkish allows both orders: verb-last).
     */
    public function requestsFileProduction(Message $message, string $text): bool
    {
        if (!$this->messageHasWorkableFileAttachment($message)) {
            return false;
        }

        // German: produce verbs, compound chart nouns, image-edit verbs.
        if (1 === preg_match('/\b(mach\s+daraus|erzeug\w*|erstell\w*|(balken|torten|säulen|linien)?diagramm|stempel\w*|wasserzeichen|zuschneiden|skalier\w*|bereinig\w*|sauber)\b/iu', $text)) {
            return true;
        }

        // English: bare nouns only for unambiguous compounds, otherwise a
        // produce verb paired with a chart/file object.
        if (1 === preg_match('/\b(save\s+as|export\s+as|clean\s*up|(bar|pie|line)\s+chart|watermark|stamp|crop|resize|rotate)\b/i', $text)) {
            return true;
        }

        if (1 === preg_match('/\b(make|create|generat\w+|build|draw|plot)\b.{0,40}\b(chart|diagram|graph|plot|image|file)\b/i', $text)) {
            return true;
        }

        // Spanish, French, Turkish: same verb+object discipline (Turkish
        // allows the verb last), plus unambiguous compounds.
        if (1 === preg_match('/\b(crea|genera|haz|guarda|exporta)\b.{0,40}\b(gráfi?co|diagram?a|imagen|archivo)\b/iu', $text)
            || 1 === preg_match('/\b(diagrama de barras|diagrama circular|sello|marca de agua|recorta)\b/iu', $text)
        ) {
            return true;
        }

        if (1 === preg_match('/\b(crée|génère|fais|enregistre|exporte)\b.{0,40}\b(graphique|diagramme|image|fichier)\b/iu', $text)
            || 1 === preg_match('/\b(camembert|tampon|filigrane|recadre)\b/iu', $text)
        ) {
            return true;
        }

        return 1 === preg_match('/\b(oluştur|olustur|yarat|kaydet|kaydedin|oluşturun)\b.{0,40}\b(grafik|diyagram|resim|dosya)\b/iu', $text)
            || 1 === preg_match('/\b(grafik|diyagram|resim|dosya)\b.{0,40}\b(oluştur|olustur|yarat|kaydet|kaydedin|oluşturun)\b/iu', $text)
            || 1 === preg_match('/\bpasta grafiği\b|\b(damga|filigran|kırp)\b/iu', $text);
    }

    /**
     * At least one attached file the produce-a-file arm can work on: a
     * document/table or an image — or an untyped legacy attachment, which is
     * treated as workable so channel messages are not silently excluded.
     * Explicit audio/video attachments are not file-work inputs.
     */
    private function messageHasWorkableFileAttachment(Message $message): bool
    {
        foreach ($message->getFiles() as $file) {
            $category = FileTypeResolver::resolveCategory($file->getFileType() ?: '', $file->getFileName());
            if (in_array($category, ['document', 'image', ''], true)) {
                return true;
            }
        }

        if ($message->getFile() > 0) {
            $category = FileTypeResolver::resolveCategory(
                $message->getFileType() ?: '',
                '',
                (string) $message->getFilePath(),
            );

            return in_array($category, ['document', 'image', ''], true);
        }

        return false;
    }

    /**
     * True if the message carries at least one image attachment. Accepts the
     * generic kind `image` (as stored by generated-media pipelines, #1236) in
     * addition to concrete extensions, and falls back to the legacy single-file
     * columns for channel messages without File entities.
     */
    public function hasImageAttachment(Message $message): bool
    {
        foreach ($message->getFiles() as $file) {
            if ('image' === FileTypeResolver::resolveCategory($file->getFileType() ?: '', $file->getFileName())) {
                return true;
            }
        }

        if ($message->getFile() > 0) {
            return 'image' === FileTypeResolver::resolveCategory(
                $message->getFileType() ?: '',
                '',
                (string) $message->getFilePath(),
            );
        }

        return false;
    }

    private function attachedFileIsAnalyzableNonImage(File $file): bool
    {
        // Resolve the concrete category through the shared resolver (#1300) so a
        // generated file stored with a GENERIC kind ('audio'/'video'/'document')
        // is routed to file_analysis, not silently sent to the AI sorter + RAG.
        $category = FileTypeResolver::resolveCategory($file->getFileType() ?: '', $file->getFileName());

        return in_array($category, ['document', 'audio', 'video'], true);
    }
}
