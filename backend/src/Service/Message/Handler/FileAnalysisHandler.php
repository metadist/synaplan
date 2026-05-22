<?php

namespace App\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Service\Message\MessagePreProcessor;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * File Analysis Handler.
 *
 * Handles file analysis requests:
 * - For documents (PDF, DOCX, etc.) with pre-extracted text: Uses Chat AI
 * - For images without extracted text: Uses Vision AI
 * - For audio files: Requires pre-transcribed text from PreProcessor
 *
 * Issue #978: when the user attaches multiple files to a single message,
 * combine the extracted text of ALL document/audio attachments into one
 * chat-model prompt instead of silently dropping every file after the
 * first. Images are still analysed one-at-a-time because the provider
 * Vision APIs accept a single image per call, but their results are
 * aggregated into a single response when multiple images are uploaded.
 *
 * Note: File type extensions are defined in MessagePreProcessor to avoid duplication.
 */
#[AutoconfigureTag('app.message.handler')]
final readonly class FileAnalysisHandler implements MessageHandlerInterface
{
    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    public function getName(): string
    {
        return 'file_analysis';
    }

    /**
     * Non-streaming handle method.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options        reserved for parity with the interface (file analysis
     *                                             does not currently consume these flags but accepts
     *                                             them so callers can use the same signature
     *                                             across handlers)
     */
    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $this->notify($progressCallback, 'analyzing', 'Analyzing file...');

        $userPrompt = $message->getText();

        $filesInfo = $this->getFilesInfo($message);

        if ([] === $filesInfo) {
            $this->logger->error('FileAnalysisHandler: No file found', [
                'message_id' => $message->getId(),
            ]);

            return [
                'content' => 'No file was provided for analysis. Please upload a file and try again.',
                'metadata' => ['error' => 'no_file'],
            ];
        }

        $this->logFilesInfo($message, $filesInfo, streaming: false);

        $route = $this->routeFiles($filesInfo);

        switch ($route['kind']) {
            case 'documents':
                // Documents (optionally bundled with transcribed audio as extra
                // context) — analyse all of them in a single chat-model call.
                return $this->handleDocumentsWithChatModel(
                    $message,
                    $route['documents'],
                    $userPrompt,
                    $classification,
                    $progressCallback,
                );

            case 'audio':
                // Issue #955: respond conversationally to voice messages.
                // Multiple recordings get their transcripts joined so the LLM
                // sees the full spoken content (#978).
                return $this->handleAudioWithChatModel(
                    $message,
                    $route['audio'],
                    $userPrompt,
                    $classification,
                    $progressCallback,
                );

            case 'images':
                return $this->handleImagesWithVisionModel(
                    $message,
                    $route['images'],
                    $userPrompt,
                    $classification,
                    $progressCallback,
                );

            case 'document_extraction_pending':
            case 'document_extraction_failed':
                return $this->buildDocumentExtractionError($route);

            case 'audio_not_transcribed':
                return $this->buildAudioNotTranscribedError($route['audio_files']);

            case 'unsupported':
            default:
                $this->logger->warning('FileAnalysisHandler: Unsupported file types only', [
                    'message_id' => $message->getId(),
                    'file_types' => array_map(static fn (array $f): ?string => $f['type'], $filesInfo),
                ]);

                return [
                    'content' => 'This file type cannot be analyzed. Supported types include documents (such as PDF, Word, Excel), images (such as JPG, PNG, GIF), and audio files (such as MP3, OGG, WAV).',
                    'metadata' => ['error' => 'unsupported_file_type'],
                ];
        }
    }

    /**
     * Handle with streaming support.
     */
    public function handleStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $this->notify($progressCallback, 'analyzing', 'Analyzing file...');

        $userPrompt = $message->getText();

        $filesInfo = $this->getFilesInfo($message);

        if ([] === $filesInfo) {
            $this->logger->error('FileAnalysisHandler: No file found (streaming)', [
                'message_id' => $message->getId(),
            ]);

            $streamCallback('No file was provided for analysis. Please upload a file and try again.');

            return [
                'metadata' => ['error' => 'no_file'],
            ];
        }

        $this->logFilesInfo($message, $filesInfo, streaming: true);

        $route = $this->routeFiles($filesInfo);

        switch ($route['kind']) {
            case 'documents':
                return $this->handleStreamDocumentsWithChatModel(
                    $message,
                    $route['documents'],
                    $userPrompt,
                    $classification,
                    $streamCallback,
                    $progressCallback,
                    $options,
                );

            case 'audio':
                return $this->handleStreamAudioWithChatModel(
                    $message,
                    $route['audio'],
                    $userPrompt,
                    $classification,
                    $streamCallback,
                    $progressCallback,
                    $options,
                );

            case 'images':
                return $this->handleStreamImagesWithVisionModel(
                    $message,
                    $route['images'],
                    $userPrompt,
                    $classification,
                    $streamCallback,
                    $progressCallback,
                );

            case 'document_extraction_pending':
            case 'document_extraction_failed':
                $error = $this->buildDocumentExtractionError($route);
                $streamCallback($error['content']);

                return ['metadata' => $error['metadata']];

            case 'audio_not_transcribed':
                $error = $this->buildAudioNotTranscribedError($route['audio_files']);
                $streamCallback($error['content']);

                return ['metadata' => $error['metadata']];

            case 'unsupported':
            default:
                $this->logger->warning('FileAnalysisHandler: Unsupported file types only (streaming)', [
                    'message_id' => $message->getId(),
                    'file_types' => array_map(static fn (array $f): ?string => $f['type'], $filesInfo),
                ]);

                $streamCallback('This file type cannot be analyzed. Supported types include documents (such as PDF, Word, Excel), images (such as JPG, PNG, GIF), and audio files (such as MP3, OGG, WAV).');

                return [
                    'metadata' => ['error' => 'unsupported_file_type'],
                ];
        }
    }

    /**
     * Issue #955: prompts that lead the LLM to treat a transcribed
     * voice message as a normal chat turn, never as a file to describe.
     *
     * The user expects the assistant to *answer* the spoken message
     * the same way it would answer typed text — not to produce
     * meta-commentary like
     * "The OGG audio file contains a very short recording that says…".
     *
     * Issue #978: when several voice notes are attached to the same
     * message, every transcript is included so the LLM can reply to
     * the full spoken content instead of just the first recording.
     *
     * @param list<array<string, mixed>> $audioFiles
     *
     * @return array{system: string, prompt: string}
     */
    private function buildAudioConversationalPrompt(array $audioFiles, string $userPrompt): array
    {
        $transcript = $this->joinAudioTranscripts($audioFiles);
        $cleanedUserPrompt = trim($userPrompt);

        $isGeneric = $this->isGenericAudioPlaceholder($cleanedUserPrompt, $transcript);

        $intro = count($audioFiles) > 1
            ? 'The user sent you '.count($audioFiles).' voice messages. The transcripts of what they actually said are provided below in order.'
            : 'The user sent you a voice message. The transcript of what they actually said is provided below.';

        $systemPrompt =
            $intro."\n\n"
            ."Respond directly and conversationally to the transcript, exactly as you would to a normal text chat message.\n\n"
            ."IMPORTANT — do NOT:\n"
            ."- describe, summarize, or analyze the audio file itself\n"
            ."- mention the file format (OGG, MP3, WAV, etc.) or that it is a recording\n"
            ."- say things like \"the audio file contains\", \"the recording says\", \"the user said in the audio\"\n"
            ."- quote the transcript back at the user unless they explicitly asked you to\n\n"
            ."Treat the transcript as if the user had typed it.\n\n"
            ."=== VOICE MESSAGE TRANSCRIPT ===\n"
            .$transcript."\n"
            .'=== END TRANSCRIPT ===';

        // When the user only attached audio (no extra text), feed the
        // transcript itself as the user turn so the LLM has something
        // concrete to reply to. When the user also typed something
        // alongside the recording (e.g. "translate this"), preserve
        // their instruction verbatim — the system prompt already gives
        // the model the transcript as context.
        $finalPrompt = $isGeneric ? $transcript : $cleanedUserPrompt;

        return [
            'system' => $systemPrompt,
            'prompt' => '' !== $finalPrompt ? $finalPrompt : $transcript,
        ];
    }

    /**
     * Concatenate the transcripts of every uploaded voice note. When
     * more than one recording is attached we label them so the LLM can
     * tell them apart; for the single-file case we preserve the old
     * (label-free) shape so existing tests/prompt expectations don't
     * regress.
     *
     * @param list<array<string, mixed>> $audioFiles
     */
    private function joinAudioTranscripts(array $audioFiles): string
    {
        if (1 === count($audioFiles)) {
            return trim((string) ($audioFiles[0]['text'] ?? ''));
        }

        $parts = [];
        foreach ($audioFiles as $index => $audio) {
            $transcript = trim((string) ($audio['text'] ?? ''));
            if ('' === $transcript) {
                continue;
            }
            $label = 'Voice message '.($index + 1);
            if (!empty($audio['name'])) {
                $label .= ' ('.$audio['name'].')';
            }
            $parts[] = "--- {$label} ---\n".$transcript;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Decide whether the user prompt is just a placeholder produced by
     * an "audio-only" upload, as opposed to a real instruction the user
     * typed alongside the voice note.
     *
     * The original implementation enumerated localized placeholders
     * from the Vue i18n bundle ("Please review the attached file.",
     * "Bitte prüfe die angehängte Datei.", …). That coupled the backend
     * to translation strings — a single typo or new locale silently
     * regressed the heuristic. Instead we rely on three locale-agnostic
     * signals:
     *
     *  1. Empty / whitespace-only prompt — the web frontend submits this
     *     when the user attached audio with no text, and `MessagePreProcessor`
     *     also normalizes the WhatsApp `[Audio message]` placeholder to
     *     the transcript before this handler runs.
     *  2. Prompt is identical to the transcript (case-insensitive) — the
     *     mobile app pre-fills the message text with the STT result and
     *     `MessagePreProcessor` does the same for `[Audio]` markers.
     *  3. Prompt is a single bracketed marker like `[audio]`, `[audio
     *     message]`, `[voice]`, `[voice note]` — the convention every
     *     mobile platform uses to signal "this row has no real text".
     *
     * Anything else is treated as a real instruction so the user's
     * verbatim wording (e.g. "translate this", "summarise this in two
     * lines") is preserved.
     */
    private function isGenericAudioPlaceholder(string $cleanedUserPrompt, string $transcript): bool
    {
        if ('' === $cleanedUserPrompt) {
            return true;
        }

        if (mb_strtolower($cleanedUserPrompt) === mb_strtolower($transcript)) {
            return true;
        }

        // Bracketed-marker rows used by mobile platforms when the body is
        // pure media. The regex matches a single `[…]` token (no nested
        // brackets) optionally surrounded by whitespace.
        return 1 === preg_match('/^\s*\[[^\[\]]+\]\s*$/u', $cleanedUserPrompt);
    }

    /**
     * Handle voice message(s) (audio with transcript) using Chat Model —
     * conversational reply path (issue #955). Multiple voice notes
     * attached to the same message are merged so every transcript
     * reaches the LLM (issue #978).
     *
     * @param list<array<string, mixed>> $audioFiles
     */
    private function handleAudioWithChatModel(
        Message $message,
        array $audioFiles,
        string $userPrompt,
        array $classification,
        ?callable $progressCallback,
    ): array {
        $this->notify($progressCallback, 'generating', 'Replying to voice message...');

        $prompts = $this->buildAudioConversationalPrompt($audioFiles, $userPrompt);

        $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
        $modelId = $classification['model_id']
            ?? $this->resolvePromptAiModel($classification)
            ?? $this->modelConfigService->getDefaultModel('CHAT', $effectiveUserId);
        $provider = null;
        $modelName = null;

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
        }

        $this->logger->info('FileAnalysisHandler: Replying to voice message', [
            'model_id' => $modelId,
            'provider' => $provider,
            'model' => $modelName,
            'user_id' => $message->getUserId(),
            'effective_user_id' => $effectiveUserId,
            'transcript_length' => strlen($prompts['system']),
            'voice_message_count' => count($audioFiles),
        ]);

        try {
            $messages = [
                ['role' => 'system', 'content' => $prompts['system']],
                ['role' => 'user', 'content' => $prompts['prompt']],
            ];

            $result = $this->aiFacade->chat(
                $messages,
                $message->getUserId(),
                [
                    'provider' => $provider,
                    'model' => $modelName,
                    'max_tokens' => 4000,
                ]
            );

            $this->notify($progressCallback, 'complete', 'Reply complete.');

            return [
                'content' => $result['content'],
                'metadata' => [
                    'provider' => $result['provider'] ?? $provider ?? 'unknown',
                    'model' => $result['model'] ?? $modelName ?? 'unknown',
                    'model_id' => $modelId,
                    'analyzed_file' => $this->describeFileList($audioFiles),
                    'analyzed_file_count' => count($audioFiles),
                    'analysis_type' => 'voice_message_reply',
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('FileAnalysisHandler: Voice message reply failed', [
                'error' => $e->getMessage(),
                'files' => $this->describeFileList($audioFiles),
            ]);

            return [
                'content' => 'Voice message reply failed: '.$e->getMessage(),
                'metadata' => [
                    'error' => $e->getMessage(),
                    'provider' => $provider,
                    'model' => $modelName,
                ],
            ];
        }
    }

    /**
     * Handle voice message(s) with streaming Chat Model (issues #955, #978).
     *
     * @param list<array<string, mixed>> $audioFiles
     */
    private function handleStreamAudioWithChatModel(
        Message $message,
        array $audioFiles,
        string $userPrompt,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback,
        array $options,
    ): array {
        $this->notify($progressCallback, 'generating', 'Replying to voice message...');

        $prompts = $this->buildAudioConversationalPrompt($audioFiles, $userPrompt);

        $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
        $modelId = $classification['model_id']
            ?? $this->resolvePromptAiModel($classification)
            ?? $this->modelConfigService->getDefaultModel('CHAT', $effectiveUserId);
        $provider = null;
        $modelName = null;

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
        }

        $this->logger->info('FileAnalysisHandler: Replying to voice message (streaming)', [
            'model_id' => $modelId,
            'provider' => $provider,
            'model' => $modelName,
            'user_id' => $message->getUserId(),
            'effective_user_id' => $effectiveUserId,
            'transcript_length' => strlen($prompts['system']),
            'voice_message_count' => count($audioFiles),
        ]);

        try {
            $messages = [
                ['role' => 'system', 'content' => $prompts['system']],
                ['role' => 'user', 'content' => $prompts['prompt']],
            ];

            $result = $this->aiFacade->chatStream(
                $messages,
                $streamCallback,
                $message->getUserId(),
                [
                    'provider' => $provider,
                    'model' => $modelName,
                    'max_tokens' => 4000,
                ]
            );

            $this->notify($progressCallback, 'complete', 'Reply complete.');

            return [
                'metadata' => [
                    'provider' => $result['provider'] ?? $provider ?? 'unknown',
                    'model' => $result['model'] ?? $modelName ?? 'unknown',
                    'model_id' => $modelId,
                    'analyzed_file' => $this->describeFileList($audioFiles),
                    'analyzed_file_count' => count($audioFiles),
                    'analysis_type' => 'voice_message_reply',
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('FileAnalysisHandler: Streaming voice message reply failed', [
                'error' => $e->getMessage(),
                'files' => $this->describeFileList($audioFiles),
            ]);

            $streamCallback('Voice message reply failed: '.$e->getMessage());

            return [
                'metadata' => [
                    'error' => $e->getMessage(),
                    'provider' => $provider,
                    'model' => $modelName,
                ],
            ];
        }
    }

    /**
     * Handle one-or-more documents with pre-extracted text using the
     * Chat Model. Issue #978: every attached document's content is
     * concatenated into the system prompt so the LLM can reason about
     * all uploaded files, not just the first one.
     *
     * @param list<array<string, mixed>> $documents
     */
    private function handleDocumentsWithChatModel(
        Message $message,
        array $documents,
        string $userPrompt,
        array $classification,
        ?callable $progressCallback,
    ): array {
        $this->notify(
            $progressCallback,
            'generating',
            count($documents) > 1 ? 'Analyzing document contents...' : 'Analyzing document content...',
        );

        $systemPrompt = $this->buildDocumentsSystemPrompt($documents);
        $finalPrompt = $this->buildDocumentsUserPrompt($userPrompt, $documents);

        // Model priority: Again model_id > Task-prompt aiModel > DB default (ANALYZE → CHAT)
        $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
        $modelId = $classification['model_id']
            ?? $this->resolvePromptAiModel($classification)
            ?? $this->modelConfigService->getDefaultModel('ANALYZE', $effectiveUserId)
            ?? $this->modelConfigService->getDefaultModel('CHAT', $effectiveUserId);
        $provider = null;
        $modelName = null;

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
        }

        $this->logger->info('FileAnalysisHandler: Using model for document analysis', [
            'model_id' => $modelId,
            'provider' => $provider,
            'model' => $modelName,
            'user_id' => $message->getUserId(),
            'effective_user_id' => $effectiveUserId,
            'document_count' => count($documents),
        ]);

        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $finalPrompt],
            ];

            $result = $this->aiFacade->chat(
                $messages,
                $message->getUserId(),
                [
                    'provider' => $provider,
                    'model' => $modelName,
                    'max_tokens' => 4000,
                ]
            );

            $this->notify($progressCallback, 'complete', 'Analysis complete.');

            return [
                'content' => $result['content'],
                'metadata' => [
                    'provider' => $result['provider'] ?? $provider ?? 'unknown',
                    'model' => $result['model'] ?? $modelName ?? 'unknown',
                    'model_id' => $modelId,
                    'analyzed_file' => $this->describeFileList($documents),
                    'analyzed_file_count' => count($documents),
                    'analysis_type' => 'chat_with_extracted_text',
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('FileAnalysisHandler: Chat analysis failed', [
                'error' => $e->getMessage(),
                'files' => $this->describeFileList($documents),
            ]);

            return [
                'content' => 'Document analysis failed: '.$e->getMessage(),
                'metadata' => [
                    'error' => $e->getMessage(),
                    'provider' => $provider,
                    'model' => $modelName,
                ],
            ];
        }
    }

    /**
     * Streaming variant of {@see handleDocumentsWithChatModel}.
     *
     * @param list<array<string, mixed>> $documents
     */
    private function handleStreamDocumentsWithChatModel(
        Message $message,
        array $documents,
        string $userPrompt,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback,
        array $options,
    ): array {
        $this->notify(
            $progressCallback,
            'generating',
            count($documents) > 1 ? 'Analyzing document contents...' : 'Analyzing document content...',
        );

        $systemPrompt = $this->buildDocumentsSystemPrompt($documents);
        $finalPrompt = $this->buildDocumentsUserPrompt($userPrompt, $documents);

        // Model priority: Again model_id > Task-prompt aiModel > DB default (ANALYZE → CHAT)
        $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
        $modelId = $classification['model_id']
            ?? $this->resolvePromptAiModel($classification)
            ?? $this->modelConfigService->getDefaultModel('ANALYZE', $effectiveUserId)
            ?? $this->modelConfigService->getDefaultModel('CHAT', $effectiveUserId);
        $provider = null;
        $modelName = null;

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
        }

        $this->logger->info('FileAnalysisHandler: Using model for document analysis (streaming)', [
            'model_id' => $modelId,
            'provider' => $provider,
            'model' => $modelName,
            'user_id' => $message->getUserId(),
            'effective_user_id' => $effectiveUserId,
            'document_count' => count($documents),
        ]);

        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $finalPrompt],
            ];

            $result = $this->aiFacade->chatStream(
                $messages,
                $streamCallback,
                $message->getUserId(),
                [
                    'provider' => $provider,
                    'model' => $modelName,
                    'max_tokens' => 4000,
                ]
            );

            $this->notify($progressCallback, 'complete', 'Analysis complete.');

            return [
                'metadata' => [
                    'provider' => $result['provider'] ?? $provider ?? 'unknown',
                    'model' => $result['model'] ?? $modelName ?? 'unknown',
                    'model_id' => $modelId,
                    'analyzed_file' => $this->describeFileList($documents),
                    'analyzed_file_count' => count($documents),
                    'analysis_type' => 'chat_with_extracted_text',
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('FileAnalysisHandler: Chat streaming analysis failed', [
                'error' => $e->getMessage(),
                'files' => $this->describeFileList($documents),
            ]);

            $streamCallback('Document analysis failed: '.$e->getMessage());

            return [
                'metadata' => [
                    'error' => $e->getMessage(),
                    'provider' => $provider,
                    'model' => $modelName,
                ],
            ];
        }
    }

    /**
     * Build the system prompt that bundles the extracted text of every
     * uploaded document. Single-file uploads keep the original layout
     * so the prompt the LLM sees is unchanged for the common case.
     *
     * @param list<array<string, mixed>> $documents
     */
    private function buildDocumentsSystemPrompt(array $documents): string
    {
        if (1 === count($documents)) {
            $doc = $documents[0];
            $prompt = "You are analyzing a document. The user has uploaded a file and wants to know about its contents.\n\n";
            $prompt .= "=== FILE INFORMATION ===\n";
            $prompt .= "Filename: {$doc['name']}\n";
            $prompt .= "Type: {$doc['type']}\n\n";
            $prompt .= "=== EXTRACTED CONTENT ===\n";
            $prompt .= $doc['text']."\n";
            $prompt .= "=== END OF CONTENT ===\n\n";
            $prompt .= 'Answer the user\'s question about this document. If they ask what\'s in the file, summarize the key points.';

            return $prompt;
        }

        $count = count($documents);
        $prompt = "You are analyzing {$count} documents. The user has uploaded multiple files and wants to know about their contents. Treat the documents as a related set — cross-reference them when the user's question spans more than one file, and clearly attribute any quotes or facts to the originating filename.\n\n";

        foreach ($documents as $index => $doc) {
            $position = $index + 1;
            $prompt .= "=== FILE {$position} OF {$count} INFORMATION ===\n";
            $prompt .= "Filename: {$doc['name']}\n";
            $prompt .= "Type: {$doc['type']}\n\n";
            $prompt .= "=== FILE {$position} EXTRACTED CONTENT ===\n";
            $prompt .= $doc['text']."\n";
            $prompt .= "=== END OF FILE {$position} CONTENT ===\n\n";
        }

        $prompt .= 'Answer the user\'s question using information from any of the files above. When summarizing, list the key points per file so the user can tell which document each statement came from.';

        return $prompt;
    }

    /**
     * Pick a sensible default user prompt when the user did not type
     * anything alongside their uploads.
     *
     * @param list<array<string, mixed>> $documents
     */
    private function buildDocumentsUserPrompt(string $userPrompt, array $documents): string
    {
        $userPrompt = trim($userPrompt);
        if ('' !== $userPrompt) {
            return $userPrompt;
        }

        return count($documents) > 1
            ? 'What is in these documents? Please summarize the content of each file.'
            : 'What is in this document? Please summarize the content.';
    }

    /**
     * Handle one-or-more images using the Vision Model. Issue #978:
     * vision providers only accept a single image per request, so
     * multi-image uploads are dispatched one-at-a-time and the
     * per-image descriptions are aggregated into a single reply that
     * the rest of the system handles like a normal handler result.
     *
     * @param list<array<string, mixed>> $images
     */
    private function handleImagesWithVisionModel(
        Message $message,
        array $images,
        string $userPrompt,
        array $classification,
        ?callable $progressCallback,
    ): array {
        $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
        $modelId = $classification['model_id']
            ?? $this->resolvePromptAiModel($classification)
            ?? $this->modelConfigService->getDefaultModel('PIC2TEXT', $effectiveUserId);
        $provider = null;
        $modelName = null;

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
        }

        $this->logger->info('FileAnalysisHandler: Using Vision model for image(s)', [
            'model_id' => $modelId,
            'provider' => $provider,
            'model' => $modelName,
            'user_id' => $message->getUserId(),
            'effective_user_id' => $effectiveUserId,
            'image_count' => count($images),
        ]);

        $perImage = $this->analyzeImageBatch($message, $images, $userPrompt, $provider, $modelName, $progressCallback);
        $content = $this->combineImageAnalyses($perImage, $images);

        $this->notify($progressCallback, 'complete', 'Analysis complete.');

        $firstSuccess = null;
        foreach ($perImage as $entry) {
            if (isset($entry['content'])) {
                $firstSuccess = $entry;
                break;
            }
        }

        return [
            'content' => $content,
            'metadata' => [
                'provider' => $firstSuccess['provider'] ?? $provider ?? 'unknown',
                'model' => $firstSuccess['model'] ?? $modelName ?? 'unknown',
                'model_id' => $modelId,
                'analyzed_file' => $this->describeFileList($images),
                'analyzed_file_count' => count($images),
                'analysis_type' => 'vision',
            ],
        ];
    }

    /**
     * Streaming variant — vision providers do not stream, so the
     * aggregated result is emitted in one chunk after every image has
     * been analyzed.
     *
     * @param list<array<string, mixed>> $images
     */
    private function handleStreamImagesWithVisionModel(
        Message $message,
        array $images,
        string $userPrompt,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback,
    ): array {
        $result = $this->handleImagesWithVisionModel(
            $message,
            $images,
            $userPrompt,
            $classification,
            $progressCallback,
        );

        if (isset($result['content'])) {
            $streamCallback($result['content']);
        }

        return [
            'metadata' => $result['metadata'] ?? [],
        ];
    }

    /**
     * Dispatch one vision call per image and capture either a
     * `content` field on success or an `error` field on failure so the
     * aggregator can render a partial response without throwing.
     *
     * @param list<array<string, mixed>> $images
     *
     * @return list<array<string, mixed>>
     */
    private function analyzeImageBatch(
        Message $message,
        array $images,
        string $userPrompt,
        ?string $provider,
        ?string $modelName,
        ?callable $progressCallback,
    ): array {
        $perImagePrompt = !empty($userPrompt)
            ? $userPrompt
            : (count($images) > 1
                ? 'Please describe this image in detail. The user uploaded several images in the same message — describe each one independently.'
                : 'Please describe this image in detail.');

        $results = [];

        foreach ($images as $index => $image) {
            $progressMessage = count($images) > 1
                ? sprintf('Analyzing image %d of %d...', $index + 1, count($images))
                : 'Analyzing image...';
            $this->notify($progressCallback, 'analyzing', $progressMessage);

            $fullPath = $this->uploadDir.'/'.$image['path'];
            if (!file_exists($fullPath)) {
                $this->logger->error('FileAnalysisHandler: File not found on disk', [
                    'path' => $image['path'],
                    'full_path' => $fullPath,
                    'image_index' => $index,
                ]);
                $results[] = [
                    'name' => $image['name'],
                    'error' => 'file_not_found',
                    'message' => "File not found: {$image['name']}",
                ];

                continue;
            }

            try {
                $analysis = $this->aiFacade->analyzeImage(
                    $image['path'],
                    $perImagePrompt,
                    $message->getUserId(),
                    [
                        'provider' => $provider,
                        'model' => $modelName,
                        'max_tokens' => 4000,
                    ]
                );

                $results[] = [
                    'name' => $image['name'],
                    'content' => $analysis['content'] ?? '',
                    'provider' => $analysis['provider'] ?? null,
                    'model' => $analysis['model'] ?? null,
                ];
            } catch (\Exception $e) {
                $this->logger->error('FileAnalysisHandler: Vision analysis failed', [
                    'error' => $e->getMessage(),
                    'file' => $image['name'],
                    'image_index' => $index,
                ]);

                $results[] = [
                    'name' => $image['name'],
                    'error' => 'analysis_failed',
                    'message' => 'Image analysis failed: '.$e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Merge per-image vision results into a single reply string. Keeps
     * the single-image output identical to the legacy format so that
     * existing UX (and any test snapshots) are not affected.
     *
     * @param list<array<string, mixed>> $results
     * @param list<array<string, mixed>> $images
     */
    private function combineImageAnalyses(array $results, array $images): string
    {
        if (1 === count($results)) {
            $only = $results[0];

            return $only['content'] ?? $only['message'] ?? 'Image analysis failed.';
        }

        $parts = [];
        foreach ($results as $index => $entry) {
            $position = $index + 1;
            $name = $entry['name'] ?? ($images[$index]['name'] ?? 'image '.$position);
            $body = $entry['content'] ?? $entry['message'] ?? 'Image analysis failed.';
            $parts[] = "### Image {$position}: {$name}\n\n{$body}";
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Get file information for every attachment on the message. Issue
     * #978: returning the full collection (instead of only the first
     * row) is the foundation for the multi-document routing in
     * {@see routeFiles()}.
     *
     * @return list<array<string, mixed>>
     */
    private function getFilesInfo(Message $message): array
    {
        $infos = [];

        $files = $message->getFiles();
        if ($files->count() > 0) {
            foreach ($files as $file) {
                /* @var File $file */
                $infos[] = $this->buildFileInfoFromEntity($file);
            }

            return $infos;
        }

        // Legacy single-file fallback (BMESSAGES.BFILEPATH / BFILETEXT).
        $filePath = $message->getFilePath();
        if ($filePath) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $infos[] = $this->buildFileInfo(
                id: null,
                name: basename($filePath),
                type: $extension,
                rawPath: $filePath,
                text: $message->getFileText() ?: '',
                status: null,
            );
        }

        return $infos;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFileInfoFromEntity(File $file): array
    {
        return $this->buildFileInfo(
            id: $file->getId(),
            name: $file->getFileName(),
            type: $file->getFileType(),
            rawPath: $file->getFilePath(),
            text: $file->getFileText(),
            status: $file->getStatus(),
        );
    }

    /**
     * Build a normalized file-info row from raw values. Keeping a
     * single factory keeps the legacy single-file path and the modern
     * `File` entity path in sync, so the routing layer never has to
     * special-case where a row originated.
     *
     * @return array<string, mixed>
     */
    private function buildFileInfo(
        ?int $id,
        ?string $name,
        ?string $type,
        ?string $rawPath,
        ?string $text,
        ?string $status,
    ): array {
        $normalizedType = strtolower((string) $type);
        $isImage = in_array($normalizedType, MessagePreProcessor::IMAGE_EXTENSIONS, true);
        $isAudio = in_array($normalizedType, MessagePreProcessor::AUDIO_EXTENSIONS, true);
        $isDocument = in_array($normalizedType, MessagePreProcessor::DOCUMENT_EXTENSIONS, true);

        // Normalize path to be relative to the upload directory (var/uploads).
        // DB values should be stored relative; older/legacy values may contain "/uploads/" or full URLs.
        $path = $this->normalizeRelativeUploadPath((string) $rawPath);

        return [
            'id' => $id,
            'name' => (string) ($name ?? basename($path)),
            'type' => (string) $type,
            'path' => $path,
            'text' => (string) ($text ?? ''),
            'status' => $status,
            'is_image' => $isImage,
            'is_audio' => $isAudio,
            'is_document' => $isDocument,
        ];
    }

    /**
     * Decide which handler path the uploaded files should travel down.
     * Returns a `kind` discriminator plus the buckets that path
     * consumes so the caller can switch on it without re-doing the
     * categorization.
     *
     * Priority rules:
     *  1. Any audio attachment is missing its transcript → surface
     *     `audio_not_transcribed` immediately. Audio is the slowest
     *     attachment to prepare in a multi-file bubble; processing
     *     only the subset that's ready silently drops the rest
     *     (Copilot review on PR #986). The user needs to wait/retry.
     *  2. Any document is still being extracted → surface
     *     `document_extraction_pending`. Same reasoning: don't run
     *     the chat model on half a bundle.
     *  3. Any document finished extraction with no usable text →
     *     surface `document_extraction_failed` so the user knows
     *     the file is unusable.
     *  4. Documents with extracted text → chat-model "document" path.
     *     Any transcribed audio is appended as virtual "transcript"
     *     documents so its content reaches the model too.
     *  5. Otherwise audio-only (one or many) with transcripts → the
     *     conversational voice-reply path.
     *  6. Otherwise images → the vision path.
     *  7. Otherwise → `unsupported`.
     *
     * @param list<array<string, mixed>> $filesInfo
     *
     * @return array{
     *     kind: string,
     *     documents?: list<array<string, mixed>>,
     *     audio?: list<array<string, mixed>>,
     *     images?: list<array<string, mixed>>,
     *     audio_files?: list<array<string, mixed>>,
     *     pending_documents?: list<array<string, mixed>>,
     *     failed_documents?: list<array<string, mixed>>
     * }
     */
    private function routeFiles(array $filesInfo): array
    {
        $documentsWithText = [];
        $documentsPending = [];
        $documentsFailed = [];
        $audioWithText = [];
        $audioMissingText = [];
        $images = [];

        foreach ($filesInfo as $info) {
            if ($info['is_audio']) {
                if ('' !== trim((string) ($info['text'] ?? ''))) {
                    $audioWithText[] = $info;
                } else {
                    $audioMissingText[] = $info;
                }
                continue;
            }

            if ($info['is_document']) {
                if ('' !== trim((string) ($info['text'] ?? ''))) {
                    $documentsWithText[] = $info;
                } else {
                    $isStillExtracting = in_array(
                        (string) ($info['status'] ?? ''),
                        ['uploaded', 'extracting'],
                        true
                    );
                    if ($isStillExtracting) {
                        $documentsPending[] = $info;
                    } else {
                        $documentsFailed[] = $info;
                    }
                }
                continue;
            }

            if ($info['is_image']) {
                $images[] = $info;
            }
        }

        // Surface "files-still-processing" errors BEFORE happily
        // proceeding with a partial set. Copilot review on PR #986
        // flagged that the previous routing happily fell through to
        // the documents/audio branches as soon as ONE file had
        // extracted text, silently dropping every other attachment.
        // For multi-file bubbles that's the exact data-loss bug we
        // were fixing for #978: "evaluate based on both" answers
        // turning into "evaluated against whichever file was ready
        // first" without the user being told.
        //
        // Resolution priorities, in order:
        //   1. Any audio attachment missing a transcript → tell the
        //      user audio is still being prepared, regardless of how
        //      many documents are ready. Audio transcription is
        //      usually the slowest path in a multi-file bubble.
        //   2. Any document still being extracted → tell the user
        //      to wait. Acting on only the ready subset has caused
        //      "where's the rest of my plan?" support escalations.
        //   3. Any document that finished extraction with no text →
        //      surface the extraction-failed message so the user
        //      knows that file is unusable, even if other docs
        //      succeeded.
        // Only once every attached doc/audio is ready do we hand
        // the bundle off to the documents/audio chat pipelines.
        if ([] !== $audioMissingText) {
            return [
                'kind' => 'audio_not_transcribed',
                'audio_files' => $audioMissingText,
            ];
        }

        if ([] !== $documentsPending) {
            return [
                'kind' => 'document_extraction_pending',
                'pending_documents' => $documentsPending,
                'failed_documents' => $documentsFailed,
            ];
        }

        if ([] !== $documentsFailed) {
            return [
                'kind' => 'document_extraction_failed',
                'pending_documents' => $documentsPending,
                'failed_documents' => $documentsFailed,
            ];
        }

        // Happy paths only run after every attached doc/audio is ready.
        // 1. Documents (with text) take priority. Merge any transcribed
        //    audio into the document set as a virtual transcript file so
        //    the chat model still sees the spoken content alongside the
        //    PDFs/MDs the user attached.
        if ([] !== $documentsWithText) {
            $documents = $documentsWithText;
            foreach ($audioWithText as $audio) {
                $documents[] = $this->wrapAudioAsTranscriptDocument($audio);
            }

            return ['kind' => 'documents', 'documents' => $documents];
        }

        // 2. Audio-only message(s) with usable transcripts → reply
        //    conversationally to the spoken content.
        if ([] !== $audioWithText && [] === $images) {
            return ['kind' => 'audio', 'audio' => $audioWithText];
        }

        // 3. Images only → vision path. We tolerate transcribed audio
        //    being present alongside images by passing the spoken
        //    content as the per-image prompt below isn't ideal, so we
        //    fall through to vision and rely on its prompt for now.
        if ([] !== $images && [] === $audioWithText) {
            return ['kind' => 'images', 'images' => $images];
        }

        // 3b. Mixed images + audio (no documents): describe the images
        //     and still attempt to route audio sensibly. The simplest
        //     pragmatic behaviour is to favour images so the user sees
        //     something useful; audio without a document is rare in
        //     this combination.
        if ([] !== $images) {
            return ['kind' => 'images', 'images' => $images];
        }

        return ['kind' => 'unsupported'];
    }

    /**
     * Treat a transcribed audio attachment as a virtual "transcript"
     * document so the multi-document prompt builder can include its
     * spoken content alongside real PDFs/MDs without growing a special
     * branch.
     *
     * @param array<string, mixed> $audio
     *
     * @return array<string, mixed>
     */
    private function wrapAudioAsTranscriptDocument(array $audio): array
    {
        $name = (string) ($audio['name'] ?? 'voice-message');
        $type = (string) ($audio['type'] ?? 'audio');

        return [
            'id' => $audio['id'] ?? null,
            'name' => $name.' (voice transcript)',
            'type' => $type.' transcript',
            'path' => $audio['path'] ?? '',
            'text' => "Voice message transcript:\n".trim((string) ($audio['text'] ?? '')),
            'status' => $audio['status'] ?? null,
            'is_image' => false,
            'is_audio' => false,
            'is_document' => true,
        ];
    }

    /**
     * @param array{
     *     kind: string,
     *     pending_documents?: list<array<string, mixed>>,
     *     failed_documents?: list<array<string, mixed>>
     * } $route
     *
     * @return array{content: string, metadata: array<string, mixed>}
     */
    private function buildDocumentExtractionError(array $route): array
    {
        $pending = $route['pending_documents'] ?? [];
        $failed = $route['failed_documents'] ?? [];
        $isStillExtracting = 'document_extraction_pending' === $route['kind'];

        $this->logger->error('FileAnalysisHandler: Document(s) without extracted text', [
            'pending_files' => $this->describeFileList($pending),
            'failed_files' => $this->describeFileList($failed),
            'still_extracting' => $isStillExtracting,
        ]);

        $firstStatus = ($pending[0]['status'] ?? $failed[0]['status'] ?? null);

        return [
            'content' => $isStillExtracting
                ? 'The document is still being prepared. Please wait a moment and send your question again — extraction is usually fast, but very large documents can take a few extra seconds.'
                : "I couldn't read any text from this document. It may be empty, scanned without OCR, password-protected, or in an unsupported format. Try a different file or paste the text directly.",
            'metadata' => [
                'error' => $isStillExtracting
                    ? 'document_extraction_in_progress'
                    : 'document_extraction_failed',
                'file_status' => $firstStatus,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $audioFiles
     *
     * @return array{content: string, metadata: array<string, mixed>}
     */
    private function buildAudioNotTranscribedError(array $audioFiles): array
    {
        $this->logger->error('FileAnalysisHandler: Audio file(s) without transcription', [
            'files' => $this->describeFileList($audioFiles),
        ]);

        return [
            'content' => 'Audio transcription failed or is not yet available. Please try again in a moment.',
            'metadata' => ['error' => 'audio_not_transcribed'],
        ];
    }

    /**
     * Log a compact summary that covers every attached file. The old
     * single-file logging missed everything past the first row, which
     * made it hard to spot multi-file dropping in production logs.
     *
     * @param list<array<string, mixed>> $filesInfo
     */
    private function logFilesInfo(Message $message, array $filesInfo, bool $streaming): void
    {
        $suffix = $streaming ? ' (streaming)' : '';
        $this->logger->info('FileAnalysisHandler: Processing file(s)'.$suffix, [
            'message_id' => $message->getId(),
            'file_count' => count($filesInfo),
            'files' => array_map(static fn (array $info): array => [
                'id' => $info['id'] ?? null,
                'name' => $info['name'] ?? null,
                'type' => $info['type'] ?? null,
                'is_image' => $info['is_image'] ?? false,
                'is_audio' => $info['is_audio'] ?? false,
                'is_document' => $info['is_document'] ?? false,
                'has_extracted_text' => '' !== trim((string) ($info['text'] ?? '')),
                'extracted_text_length' => strlen((string) ($info['text'] ?? '')),
                'status' => $info['status'] ?? null,
            ], $filesInfo),
        ]);
    }

    /**
     * Short, log/metadata-friendly summary of a file collection.
     *
     * @param list<array<string, mixed>> $files
     */
    private function describeFileList(array $files): string
    {
        $names = array_map(static fn (array $f): string => (string) ($f['name'] ?? 'unnamed'), $files);

        return implode(', ', $names);
    }

    /**
     * Normalize any stored/received file path into a path relative to var/uploads.
     * Examples:
     * - "13/000/00013/2025/12/file.pdf" => same
     * - "uploads/13/000/00013/2025/12/file.pdf" => "13/000/00013/2025/12/file.pdf"
     * - "/var/www/backend/var/uploads/13/..." => "13/..."
     * - "/api/v1/files/uploads/13/..." => "13/..."
     * - "/up/13/..." => "13/...".
     */
    private function normalizeRelativeUploadPath(string $path): string
    {
        $path = trim($path);

        // Strip known URL prefixes first
        $path = preg_replace('#^https?://[^/]+/#', '', $path) ?? $path;
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'api/v1/files/uploads/')) {
            $path = substr($path, strlen('api/v1/files/uploads/'));
        }
        if (str_starts_with($path, 'up/')) {
            $path = substr($path, strlen('up/'));
        }

        // Strip filesystem prefix if present
        if (str_contains($path, '/uploads/')) {
            $path = substr($path, strpos($path, '/uploads/') + strlen('/uploads/'));
        }

        // Some legacy code may have stored "uploads/..." relative to project root
        if (str_starts_with($path, 'uploads/')) {
            $path = substr($path, strlen('uploads/'));
        }

        return ltrim($path, '/');
    }

    /**
     * Extract the task-prompt aiModel override from classification metadata.
     */
    private function resolvePromptAiModel(array $classification): ?int
    {
        $promptMetadata = $classification['prompt_metadata'] ?? [];
        $aiModel = $promptMetadata['aiModel'] ?? null;

        return (null !== $aiModel && (int) $aiModel > 0) ? (int) $aiModel : null;
    }

    /**
     * Notify progress callback.
     */
    private function notify(?callable $callback, string $status, string $message): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'timestamp' => time(),
            ]);
        }
    }
}
