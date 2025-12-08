<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Whisper.cpp Service for Audio Transcription.
 *
 * Uses whisper.cpp binary installed in Docker container
 */
class WhisperService
{
    private const SUPPORTED_FORMATS = ['ogg', 'mp3', 'wav', 'm4a', 'opus', 'flac', 'webm', 'aac', 'wma', 'mp4', 'avi', 'mov', 'mkv', 'mpeg', 'mpg'];

    // Whisper.cpp optimal format: 16kHz, mono, 16-bit PCM WAV
    private const OPTIMAL_SAMPLE_RATE = 16000;
    private const OPTIMAL_CHANNELS = 1;

    public function __construct(
        private LoggerInterface $logger,
        private string $whisperBinary = '/usr/local/bin/whisper',
        private string $whisperModelsPath = '/var/www/backend/var/whisper',
        private string $defaultModel = 'base',
        private string $ffmpegBinary = '/usr/bin/ffmpeg',
    ) {
    }

    /**
     * Transcribe audio file to text.
     *
     * @param string $audioPath Absolute path to audio file
     * @param array  $options   Options: model, language, translate, threads
     *
     * @return array ['text' => string, 'language' => string, 'duration' => float]
     */
    public function transcribe(string $audioPath, array $options = []): array
    {
        if (!file_exists($audioPath)) {
            throw new \InvalidArgumentException("Audio file not found: {$audioPath}");
        }

        $fileExtension = strtolower(pathinfo($audioPath, PATHINFO_EXTENSION));
        if (!in_array($fileExtension, self::SUPPORTED_FORMATS)) {
            throw new \InvalidArgumentException("Unsupported audio format: {$fileExtension}. Supported: ".implode(', ', self::SUPPORTED_FORMATS));
        }

        $startTime = microtime(true);

        // Convert to WAV 16kHz mono if needed (whisper.cpp requirement)
        $processedAudio = $this->convertAudio($audioPath);

        // Build whisper command
        $command = $this->buildCommand($processedAudio, $options);

        $this->logger->info('WhisperService: Starting transcription', [
            'file' => basename($audioPath),
            'size' => filesize($audioPath),
            'model' => $options['model'] ?? $this->defaultModel,
            'command' => implode(' ', $command),
        ]);

        try {
            $process = new Process($command);
            $process->setTimeout(600); // 10 minutes max
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $output = $process->getOutput();
            $errorOutput = $process->getErrorOutput();

            // Parse whisper output
            $result = $this->parseWhisperOutput($output, $errorOutput);

            $duration = microtime(true) - $startTime;

            $this->logger->info('WhisperService: Transcription complete', [
                'file' => basename($audioPath),
                'duration' => round($duration, 2).'s',
                'text_length' => strlen($result['text']),
                'detected_language' => $result['language'],
            ]);

            // Clean up temp file if created
            if ($processedAudio !== $audioPath && file_exists($processedAudio)) {
                unlink($processedAudio);
            }

            return [
                'text' => $result['text'],
                'language' => $result['language'],
                'duration' => round($duration, 2),
                'model' => $options['model'] ?? $this->defaultModel,
            ];
        } catch (ProcessFailedException $e) {
            $this->logger->error('WhisperService: Transcription failed', [
                'file' => basename($audioPath),
                'error' => $e->getMessage(),
                'output' => $process->getOutput(),
                'error_output' => $process->getErrorOutput(),
            ]);

            throw new \RuntimeException('Audio transcription failed: '.$e->getMessage(), 0, $e);
        } finally {
            // Cleanup temp file
            if (isset($processedAudio) && $processedAudio !== $audioPath && file_exists($processedAudio)) {
                unlink($processedAudio);
            }
        }
    }

    /**
     * Translate audio to English.
     */
    public function translateToEnglish(string $audioPath, array $options = []): array
    {
        $options['translate'] = true;

        return $this->transcribe($audioPath, $options);
    }

    /**
     * Check if Whisper is available.
     */
    public function isAvailable(): bool
    {
        // Check if binary exists
        if (!file_exists($this->whisperBinary)) {
            $this->logger->debug('WhisperService: Binary not found', [
                'path' => $this->whisperBinary,
            ]);

            return false;
        }

        // Check if binary is executable
        if (!is_executable($this->whisperBinary)) {
            $this->logger->debug('WhisperService: Binary not executable', [
                'path' => $this->whisperBinary,
            ]);

            return false;
        }

        // Check if model directory exists
        if (!is_dir($this->whisperModelsPath)) {
            $this->logger->debug('WhisperService: Models directory not found', [
                'path' => $this->whisperModelsPath,
            ]);

            return false;
        }

        // Check if default model exists
        $modelPath = $this->getModelPath($this->defaultModel);
        if (!file_exists($modelPath)) {
            $this->logger->debug('WhisperService: Default model not found', [
                'model' => $this->defaultModel,
                'path' => $modelPath,
            ]);

            return false;
        }

        // Check if FFmpeg is available (needed for audio conversion)
        if (!$this->isFfmpegAvailable()) {
            $this->logger->debug('WhisperService: FFmpeg not available', [
                'ffmpeg_path' => $this->ffmpegBinary,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Check if FFmpeg is available.
     */
    private function isFfmpegAvailable(): bool
    {
        return file_exists($this->ffmpegBinary) && is_executable($this->ffmpegBinary);
    }

    /**
     * Get list of available models.
     */
    public function getAvailableModels(): array
    {
        $models = [];
        $modelFiles = glob($this->whisperModelsPath.'/ggml-*.bin');

        foreach ($modelFiles as $file) {
            $basename = basename($file, '.bin');
            $modelName = str_replace('ggml-', '', $basename);
            $models[] = $modelName;
        }

        return $models;
    }

    /**
     * Convert audio to optimal format for Whisper.cpp.
     *
     * Whisper.cpp works best with:
     * - Sample rate: 16kHz (OPTIMAL_SAMPLE_RATE)
     * - Channels: mono (OPTIMAL_CHANNELS)
     * - Format: 16-bit PCM WAV
     *
     * This handles ALL audio/video formats and extracts audio track automatically.
     * Platform-independent as long as FFmpeg is available.
     */
    private function convertAudio(string $audioPath): string
    {
        $fileExtension = strtolower(pathinfo($audioPath, PATHINFO_EXTENSION));

        // Always convert to ensure optimal format (16kHz, mono, 16-bit PCM)

        $tempWav = sys_get_temp_dir().'/'.uniqid('whisper_', true).'.wav';

        $this->logger->debug('WhisperService: Converting audio to optimal format', [
            'input' => basename($audioPath),
            'input_format' => $fileExtension,
            'output_format' => '16kHz mono PCM WAV',
        ]);

        $command = [
            $this->ffmpegBinary,
            '-i', $audioPath,
            '-ar', (string) self::OPTIMAL_SAMPLE_RATE,  // 16kHz sample rate
            '-ac', (string) self::OPTIMAL_CHANNELS,      // mono
            '-c:a', 'pcm_s16le',                         // 16-bit PCM
            '-f', 'wav',                                 // force WAV format
            '-vn',                                       // no video (extract audio only from video files)
            '-y',                                        // overwrite if exists
            '-loglevel', 'error',                        // only errors
            $tempWav,
        ];

        $process = new Process($command);
        $process->setTimeout(300); // 5 minutes max for conversion
        $process->run();

        if (!$process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput();
            $this->logger->error('WhisperService: Audio conversion failed', [
                'input' => basename($audioPath),
                'ffmpeg_binary' => $this->ffmpegBinary,
                'error' => $errorOutput,
                'exit_code' => $process->getExitCode(),
            ]);
            throw new \RuntimeException('Failed to convert audio format. FFmpeg error: '.$errorOutput);
        }

        $this->logger->debug('WhisperService: Audio conversion successful', [
            'input' => basename($audioPath),
            'output_size' => filesize($tempWav).' bytes',
        ]);

        return $tempWav;
    }

    /**
     * Build whisper command.
     */
    private function buildCommand(string $audioPath, array $options): array
    {
        $model = $options['model'] ?? $this->defaultModel;
        $modelPath = $this->getModelPath($model);

        if (!file_exists($modelPath)) {
            throw new \RuntimeException("Whisper model not found: {$model} at {$modelPath}");
        }

        $command = [
            $this->whisperBinary,
            '-m', $modelPath,
            '-f', $audioPath,
            '--output-txt',   // Text output
            '--no-timestamps', // No timestamps in output
        ];

        // Language hint (speeds up processing)
        if (isset($options['language'])) {
            $command[] = '-l';
            $command[] = $options['language'];
        } else {
            // Force auto language detection so that Whisper always reports detected language
            $command[] = '-l';
            $command[] = 'auto';
        }

        // Translation mode (translate to English)
        if (!empty($options['translate'])) {
            $command[] = '--translate';
        }

        // Threads (default: use all available)
        if (isset($options['threads'])) {
            $command[] = '-t';
            $command[] = (string) $options['threads'];
        } else {
            $command[] = '-t';
            $command[] = (string) (int) (shell_exec('nproc') ?? 4);
        }

        return $command;
    }

    /**
     * Get model file path.
     */
    private function getModelPath(string $model): string
    {
        return $this->whisperModelsPath.'/ggml-'.$model.'.bin';
    }

    /**
     * Parse whisper.cpp output.
     */
    private function parseWhisperOutput(string $output, string $errorOutput): array
    {
        // Whisper writes transcription to stdout
        $text = trim($output);

        // Remove [BLANK_AUDIO] markers
        $text = str_replace('[BLANK_AUDIO]', '', $text);
        $text = trim($text);

        // Try to detect language from error output (whisper logs this)
        $language = 'unknown';
        if (preg_match('/auto-detected language: (\w+)/i', $errorOutput, $matches)) {
            $language = strtolower($matches[1]);
        }

        // Fallback: if language could not be detected, default to English
        if ('unknown' === $language || '' === $language) {
            $language = 'en';
        }

        return [
            'text' => $text,
            'language' => $language,
        ];
    }

    /**
     * Get supported audio formats.
     */
    public function getSupportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }
}
