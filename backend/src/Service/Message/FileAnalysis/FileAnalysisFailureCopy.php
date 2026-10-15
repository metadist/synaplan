<?php

declare(strict_types=1);

namespace App\Service\Message\FileAnalysis;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Model\AccountLanguage;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Stt\SpeechFailure;
use App\Service\Stt\WhisperService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the user reads when a file cannot be analysed: no file, an
 * unsupported type, a file still being prepared, or one that could not be
 * read. Pending copy asks the user to wait; failed copy names the cause and
 * never promises a retry that cannot succeed (#1908).
 */
final readonly class FileAnalysisFailureCopy
{
    private const AUDIO_PENDING_FALLBACK = 'The recording is still being prepared. Please wait a moment and send your question again.';

    private const AUDIO_FAILED_FALLBACK = 'This recording could not be transcribed. Speech-to-text is not set up on this server, or the audio could not be understood. Connect a speech-to-text service under Settings, or try a different file.';

    private const AUDIO_SPEECH_OFF_FALLBACK = 'Local speech is turned off, so this recording was not transcribed. Turn on local speech, or choose a speech model in Settings.';

    private const AUDIO_BINARY_MISSING_FALLBACK = 'Speech recognition is not installed on this server, so this recording was not transcribed. Install speech recognition, or choose a speech model in Settings.';

    private const AUDIO_MODEL_MISSING_FALLBACK = 'The local speech model file is missing, so this recording was not transcribed. Add that model, or choose a speech model in Settings.';

    public function __construct(
        private AiFacade $aiFacade,
        private LoggerInterface $logger,
        private ?ChatErrorPresenter $chatErrorPresenter = null,
        private ?TranslatorInterface $translator = null,
        private ?WhisperService $whisperService = null,
    ) {
    }

    /**
     * @return array{content: string, metadata: array<string, mixed>}
     */
    public function noFile(): array
    {
        return [
            'content' => 'No file was provided for analysis. Please upload a file and try again.',
            'metadata' => ['error' => 'no_file'],
        ];
    }

    /**
     * @return array{content: string, metadata: array<string, mixed>}
     */
    public function unsupported(): array
    {
        return [
            'content' => 'This file type cannot be analyzed. Supported types include documents (such as PDF, Word, Excel), images (such as JPG, PNG, GIF), audio files (such as MP3, OGG, WAV), and videos (such as MP4, MOV).',
            'metadata' => ['error' => 'unsupported_file_type'],
        ];
    }

    /**
     * The copy for a route that stops at files still being prepared or
     * unreadable; null for a route that can be analysed.
     *
     * @param array<string, mixed> $route
     *
     * @return array{content: string, metadata: array<string, mixed>}|null
     */
    public function forBlockedRoute(array $route, Message $message): ?array
    {
        return match ($route['kind']) {
            'document_extraction_pending', 'document_extraction_failed' => $this->documentExtraction($route),
            'audio_transcription_pending', 'audio_transcription_failed' => $this->audioTranscription($route, $message),
            'video_transcription_pending', 'video_transcription_failed' => $this->videoTranscription($route),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $classification
     */
    public function forException(\Throwable $e, array $classification): string
    {
        $lang = 'en';
        if (isset($classification['language']) && is_string($classification['language']) && '' !== trim($classification['language'])) {
            $lang = $classification['language'];
        }
        if ($this->chatErrorPresenter instanceof ChatErrorPresenter) {
            return $this->chatErrorPresenter->present($e, $lang)->userText;
        }

        return 'Something went wrong while answering this request. Please try again.';
    }

    public function imageFailure(): string
    {
        return $this->forException(new \RuntimeException('Image analysis failed'), []);
    }

    /**
     * @param array<string, mixed> $route
     *
     * @return array{content: string, metadata: array<string, mixed>}
     */
    private function documentExtraction(array $route): array
    {
        $pending = $route['pending_documents'] ?? [];
        $failed = $route['failed_documents'] ?? [];
        $isStillExtracting = 'document_extraction_pending' === $route['kind'];

        $this->logger->error('FileAnalysisHandler: Document(s) without extracted text', [
            'pending_files' => AnalysisFileResolver::describe($pending),
            'failed_files' => AnalysisFileResolver::describe($failed),
            'still_extracting' => $isStillExtracting,
        ]);

        return [
            'content' => $isStillExtracting
                ? 'The document is still being prepared. Please wait a moment and send your question again — extraction is usually fast, but very large documents can take a few extra seconds.'
                : "I couldn't read any text from this document. It may be empty, scanned without OCR, password-protected, or in an unsupported format. Try a different file or paste the text directly.",
            'metadata' => [
                'error' => $isStillExtracting ? 'document_extraction_in_progress' : 'document_extraction_failed',
                'file_status' => $pending[0]['status'] ?? $failed[0]['status'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $route
     *
     * @return array{content: string, metadata: array<string, mixed>}
     */
    private function audioTranscription(array $route, Message $message): array
    {
        $isStillProcessing = 'audio_transcription_pending' === $route['kind'];

        $this->logger->error('FileAnalysisHandler: Audio file(s) without transcription', [
            'files' => AnalysisFileResolver::describe($route['audio_files'] ?? []),
            'still_processing' => $isStillProcessing,
        ]);

        [$key, $fallback] = $isStillProcessing
            ? ['file_analysis.audio_pending', self::AUDIO_PENDING_FALLBACK]
            : $this->audioFailedCopy($message);

        return [
            'content' => $this->trans($key, $fallback, $this->normalizeLocale($message->getLanguage())),
            'metadata' => ['error' => $isStillProcessing ? 'audio_transcription_in_progress' : 'audio_transcription_failed'],
        ];
    }

    /**
     * @return array{0: string, 1: string} translation key and English fallback
     */
    private function audioFailedCopy(Message $message): array
    {
        $reason = null;
        if (null !== $this->whisperService && !$this->aiFacade->hasConfiguredSttProvider($message->getUserId())) {
            // Null means the files are present and something else failed
            // (FFmpeg, a binary that cannot start). That stays the generic
            // transcription failure, not "speech recognition is not installed".
            $reason = $this->whisperService->unavailableReason();
        }

        return match ($reason) {
            SpeechFailure::SPEECH_OFF => ['file_analysis.audio_speech_off', self::AUDIO_SPEECH_OFF_FALLBACK],
            SpeechFailure::BINARY_MISSING => ['file_analysis.audio_binary_missing', self::AUDIO_BINARY_MISSING_FALLBACK],
            SpeechFailure::MODEL_MISSING => ['file_analysis.audio_model_missing', self::AUDIO_MODEL_MISSING_FALLBACK],
            default => ['file_analysis.audio_failed', self::AUDIO_FAILED_FALLBACK],
        };
    }

    /**
     * @param array<string, mixed> $route
     *
     * @return array{content: string, metadata: array<string, mixed>}
     */
    private function videoTranscription(array $route): array
    {
        $isStillProcessing = 'video_transcription_pending' === $route['kind'];

        $this->logger->error('FileAnalysisHandler: Video(s) without transcript', [
            'files' => AnalysisFileResolver::describe($route['video_files'] ?? []),
            'still_processing' => $isStillProcessing,
        ]);

        return [
            'content' => $isStillProcessing
                ? 'The video is still being processed — its audio is being transcribed. Please wait a moment and send your question again. Longer videos can take a little while.'
                : "I couldn't get any speech from this video. It may have no spoken audio, be silent, or be in a format I can't transcribe. Try a different file or describe what you need.",
            'metadata' => ['error' => $isStillProcessing ? 'video_transcription_in_progress' : 'video_transcription_failed'],
        ];
    }

    private function normalizeLocale(string $lang): string
    {
        $normalized = strtolower(substr(trim($lang), 0, 2));

        return in_array($normalized, AccountLanguage::SUPPORTED, true) ? $normalized : 'en';
    }

    private function trans(string $key, string $fallback, string $locale): string
    {
        if (null === $this->translator) {
            return $fallback;
        }

        $translated = $this->translator->trans($key, [], 'ai_errors', $locale);

        return ('' === $translated || $translated === $key) ? $fallback : $translated;
    }
}
