<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Repository\VoiceSessionRepository;
use Psr\Log\LoggerInterface;

/**
 * Closes conversations whose gateway stopped ticking. The last stored
 * usage is what gets billed. A reconnect does not open a second row.
 */
final readonly class VoiceLiveReaper
{
    public const STALE_SECONDS = 45;

    public function __construct(
        private VoiceSessionRepository $sessions,
        private VoiceLiveUsageService $usage,
        private VoiceLiveConcurrency $concurrency,
        private LoggerInterface $logger,
    ) {
    }

    public function reap(int $staleAfterSeconds = self::STALE_SECONDS): int
    {
        $now = time();
        $closed = 0;
        foreach ($this->sessions->findOpen() as $session) {
            if (($now - $this->usage->lastActivityUnix($session)) < $staleAfterSeconds) {
                continue;
            }
            $this->usage->finalizeStored($session, 'network_lost');
            $this->concurrency->release($session->getProvider());
            $this->logger->info('Live voice session reaped', [
                'session_id' => $session->getId(),
                'provider' => $session->getProvider(),
                'reason' => 'network_lost',
            ]);
            ++$closed;
        }

        return $closed;
    }
}
