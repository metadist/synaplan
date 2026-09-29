<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Telegram shows a voice bubble only for OGG/Opus. Generated speech is
 * converted with ffmpeg; the caller sends it as a plain audio file when
 * the conversion is not possible.
 */
class TelegramVoiceConverter
{
    private const TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return string|null path of a temporary .ogg file the caller deletes
     */
    public function toVoice(string $absolutePath): ?string
    {
        $target = tempnam(sys_get_temp_dir(), 'tg_voice_');
        if (false === $target) {
            return null;
        }
        @unlink($target);
        $target .= '.ogg';

        $process = new Process(['ffmpeg', '-y', '-loglevel', 'error', '-i', $absolutePath, '-vn', '-c:a', 'libopus', '-b:a', '48k', $target]);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger->info('Telegram voice conversion failed', ['exception_class' => $e::class]);
            @unlink($target);

            return null;
        }
        if (!$process->isSuccessful() || !is_file($target) || 0 === filesize($target)) {
            $this->logger->info('Telegram voice conversion failed', ['exit_code' => $process->getExitCode()]);
            @unlink($target);

            return null;
        }

        return $target;
    }
}
