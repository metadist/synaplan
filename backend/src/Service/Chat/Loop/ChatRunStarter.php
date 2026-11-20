<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Run\RunKeys;
use App\Service\Run\RunService;
use App\Service\Run\RunStart;
use Psr\Clock\ClockInterface;

/**
 * Opens a chat run: the checkpoint, its `BRUNS` row, and the first unit on
 * the worker queue. A retry with the same idempotency key returns the run
 * the first call opened and pays nothing. Nothing starts while the loop is
 * off.
 */
final readonly class ChatRunStarter
{
    private RunService $runs;

    public function __construct(
        ChatRunStore $store,
        MessengerChatRunUnitBus $bus,
        private ChatRunRecords $records,
        private ChatRunEvents $events,
        private ChatLoopConfig $config,
        private ClockInterface $clock,
        RunKeys $keys,
    ) {
        $this->runs = new RunService($keys, $store->runs(), $bus, $clock);
    }

    /**
     * @throws ChatLoopOff when the loop is switched off
     */
    public function start(ChatRunRequest $request): RunStart
    {
        if (!$this->config->isEnabled()) {
            throw new ChatLoopOff();
        }
        $row = null;
        try {
            $start = $this->open($request, $row);
        } catch (\Throwable $e) {
            // The checkpoint was not written: the row must not stay "running" for a run that does not exist.
            if (null !== $row) {
                $this->records->abandon($row);
            }

            throw $e;
        }
        if ($start->opened) {
            $this->events->append($start->runId, ChatRunEvents::STARTED, ['chat_id' => $request->chatId]);
        }

        return $start;
    }

    public function stop(string $runId): void
    {
        $this->runs->stop($runId);
    }

    /**
     * @param-out string|null $row the run whose row this call wrote
     */
    private function open(ChatRunRequest $request, ?string &$row): RunStart
    {
        return $this->runs->start($request->userId, $request->idempotencyKey, ChatRunStep::UNIT, function (string $runId) use ($request, &$row): ChatRunState {
            $state = new ChatRunState(
                $runId,
                $request->userId,
                $request->chatId,
                $request->incomingMessageId,
                $request->prompt->provider,
                $request->prompt->model,
                $request->prompt->locale,
                $request->prompt->basePrompt,
                $request->prompt->messages,
                $this->clock->now()->getTimestamp(),
            );
            $this->records->open($state, $request->startedBy, $request->job, $request->idempotencyKey);
            $row = $runId;

            return $state;
        });
    }
}
