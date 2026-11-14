<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Chat\Stream\ChatStreamOrchestrator;
use App\Service\Realtime\SseWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs one web-chat turn inside the pump process.
 *
 * The orchestrator is the same one the web request uses. Its writer records
 * the frames on the run stream instead of the socket, so the bytes do not
 * change. The web request, or the watchdog, copies them to the client.
 */
final readonly class PumpChatRunner
{
    public function __construct(
        private UserRepository $users,
        private ChatStreamOrchestrator $orchestrator,
        private RedisRunStream $frames,
        private StreamRedis $redis,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function run(array $payload, string $incarnation): void
    {
        $runId = $payload['runId'] ?? null;
        if (!is_string($runId) || '' === $runId) {
            return;
        }
        $this->redis->markRunning($runId, $incarnation);
        try {
            StreamJobGuard::reject($payload);
            $userId = $payload['userId'] ?? null;
            $found = is_int($userId) || (is_string($userId) && ctype_digit($userId))
                ? $this->users->find((int) $userId)
                : null;
            $user = $found instanceof User ? $found : null;
            $turn = null !== $user ? ChatTurnJob::restore($payload, $user) : null;
            if (null === $turn) {
                $this->redis->appendFrame($runId, $this->nextSeq($runId), 'terminal', PumpFrames::DID_NOT_START);

                return;
            }
            $this->orchestrator->run($turn, new SseWriter(false, new RunStreamFrameSink($this->frames, $runId)));
        } catch (\Throwable $e) {
            $this->logger->error('Stream pump failed a chat turn', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
            $this->redis->appendFrame($runId, $this->nextSeq($runId), 'terminal', PumpFrames::DID_NOT_START);
        } finally {
            $this->redis->markFinished($runId);
            $this->entityManager->clear();
        }
    }

    private function nextSeq(string $runId): int
    {
        $last = 0;
        foreach ($this->frames->after($runId, 0) as $frame) {
            $last = max($last, $frame->seq);
        }

        return $last + 1;
    }
}
