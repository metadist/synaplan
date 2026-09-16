<?php

declare(strict_types=1);

namespace App\Service\Stt;

/**
 * Appends raw audio chunks and turns the pending buffer into a file
 * {@see \App\AI\Service\AiFacade::transcribe()} can consume.
 */
final class SttAudioAssembler
{
    public const ENCODING_AUTO = 'auto';
    public const ENCODING_PCM = 'pcm_s16le';
    public const ENCODING_WAV = 'wav';
    public const ENCODING_WEBM = 'webm';
    public const ENCODING_OGG = 'ogg';
    public const ENCODING_MP3 = 'mp3';
    public const ENCODING_M4A = 'm4a';
    public const ENCODING_FLAC = 'flac';

    /**
     * @return list<string>
     */
    public static function encodings(): array
    {
        return [
            self::ENCODING_AUTO,
            self::ENCODING_PCM,
            self::ENCODING_WAV,
            self::ENCODING_WEBM,
            self::ENCODING_OGG,
            self::ENCODING_MP3,
            self::ENCODING_M4A,
            self::ENCODING_FLAC,
        ];
    }

    public function pendingPath(string $sessionDir): string
    {
        return $sessionDir.'/pending.bin';
    }

    public function append(string $pendingPath, string $chunk): int
    {
        $dir = dirname($pendingPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create STT session audio directory');
        }

        $written = file_put_contents($pendingPath, $chunk, FILE_APPEND | LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException('Failed to append audio chunk');
        }

        return $written;
    }

    public function pendingSize(string $pendingPath): int
    {
        if (!is_file($pendingPath)) {
            return 0;
        }

        return (int) filesize($pendingPath);
    }

    /**
     * Build a temporary audio file suitable for the configured STT provider.
     *
     * Caller must unlink the returned path.
     */
    public function buildTranscribeFile(
        string $pendingPath,
        string $encoding,
        int $sampleRate,
        int $channels,
    ): string {
        if (!is_file($pendingPath) || 0 === filesize($pendingPath)) {
            throw new \InvalidArgumentException('No pending audio to transcribe');
        }

        $data = file_get_contents($pendingPath);
        if (false === $data || '' === $data) {
            throw new \InvalidArgumentException('No pending audio to transcribe');
        }

        $detected = $this->detectFormat($data, $encoding);
        $tmp = sys_get_temp_dir().'/stt_'.bin2hex(random_bytes(8)).'.'.$detected['extension'];
        $payload = $detected['wrap_pcm']
            ? $this->wrapPcmWav($data, $sampleRate, $channels)
            : $data;

        if (false === file_put_contents($tmp, $payload)) {
            throw new \RuntimeException('Failed to write transcription temp file');
        }

        return $tmp;
    }

    public function clearPending(string $pendingPath): void
    {
        if (is_file($pendingPath)) {
            file_put_contents($pendingPath, '', LOCK_EX);
        }
    }

    /**
     * @return array{extension: string, wrap_pcm: bool}
     */
    public function detectFormat(string $data, string $encoding): array
    {
        if (self::ENCODING_PCM === $encoding) {
            return ['extension' => 'wav', 'wrap_pcm' => true];
        }

        if (self::ENCODING_AUTO !== $encoding) {
            return ['extension' => $encoding, 'wrap_pcm' => false];
        }

        $magic = $this->sniffMagic($data);
        if (null !== $magic) {
            return ['extension' => $magic, 'wrap_pcm' => false];
        }

        // Bare PCM from a local capture pipeline — wrap so whisper.cpp / ffmpeg
        // and the cloud providers all see a real WAV file.
        return ['extension' => 'wav', 'wrap_pcm' => true];
    }

    public function wrapPcmWav(string $pcm, int $sampleRate, int $channels): string
    {
        $sampleRate = max(8000, min(48000, $sampleRate));
        $channels = max(1, min(2, $channels));
        $byteRate = $sampleRate * $channels * 2;
        $blockAlign = $channels * 2;
        $dataSize = strlen($pcm);

        $header = pack(
            'A4VA4A4VvvVVvvA4V',
            'RIFF',
            36 + $dataSize,
            'WAVE',
            'fmt ',
            16,
            1,
            $channels,
            $sampleRate,
            $byteRate,
            $blockAlign,
            16,
            'data',
            $dataSize,
        );

        return $header.$pcm;
    }

    private function sniffMagic(string $data): ?string
    {
        if (strlen($data) < 12) {
            return null;
        }

        if (str_starts_with($data, 'RIFF') && str_contains(substr($data, 0, 12), 'WAVE')) {
            return 'wav';
        }
        if (str_starts_with($data, 'OggS')) {
            return 'ogg';
        }
        if (str_starts_with($data, 'fLaC')) {
            return 'flac';
        }
        if (str_starts_with($data, 'ID3') || str_starts_with($data, "\xff\xfb") || str_starts_with($data, "\xff\xf3")) {
            return 'mp3';
        }
        // EBML / WebM / Matroska
        if (str_starts_with($data, "\x1a\x45\xdf\xa3")) {
            return 'webm';
        }
        // MPEG-4 / M4A (`ftyp` at offset 4)
        if ('ftyp' === substr($data, 4, 4)) {
            return 'm4a';
        }

        return null;
    }
}
