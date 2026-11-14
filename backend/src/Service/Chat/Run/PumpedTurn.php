<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

use App\Service\Chat\Stream\AttachSecret;
use App\Service\Chat\Stream\AttachToken;
use App\Service\Chat\Stream\RelayProof;
use App\Service\Chat\Stream\StreamTurnRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Uid\Uuid;

/**
 * Hands a signed-in web chat to the pump when the switch says so.
 *
 * Incognito, guests, widgets and a turn that is only a rate-limit report
 * stay in the request. When the pump cannot take the job, the request
 * stays in-process as well: the switch off and a Redis miss are the same
 * answer the person already gets.
 */
final readonly class PumpedTurn
{
    public function __construct(
        private StreamPumpConfig $config,
        private StreamRedis $redis,
        private RelayProof $relay,
        private AttachSecret $secret,
        #[Autowire(service: RedisRunStream::class)]
        private readonly RunStream $frames,
    ) {
    }

    public function response(Request $request, StreamTurnRequest $turn): ?Response
    {
        if ($turn->incognito || null !== $turn->rateLimitError || $turn->caller->isWidget() || $turn->caller->isGuest()) {
            return null;
        }
        $runId = Uuid::v4()->toRfc4122();
        if (!$this->config->handles('chat_stream', $runId)) {
            return null;
        }
        $payload = ChatTurnJob::payload($turn, $runId);
        try {
            $stored = $this->redis->enqueue(Uuid::v4()->toRfc4122(), $runId, $payload, time());
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (!$stored) {
            return null;
        }

        $secret = $this->secret->value();
        if ($this->relay->accepts($request) && '' !== $secret) {
            $token = AttachToken::sign($secret, $runId, $turn->caller->runOwnerKey(), 0, time() + 1800);
            $body = json_encode([
                'runId' => $runId,
                'token' => $token,
                'fromSeq' => 0,
                'sseIds' => true,
                'contentType' => 'text/event-stream',
                'headers' => [],
                'terminal' => PumpFrames::LOST_PUMP,
                'idleTimeoutSec' => 60,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return new Response($body, Response::HTTP_OK, [
                'Content-Type' => 'application/vnd.synaplan.attach+json',
                'Cache-Control' => 'no-store',
            ]);
        }

        $tail = new RunStreamTail($this->frames);

        return new StreamedResponse(
            static function () use ($tail, $runId): void {
                $tail->copy($runId);
            },
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ],
        );
    }
}
