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
    private ChatRunAnswer $answers;

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
        $this->answers = new ChatRunAnswer($events, ChatRunProfile::limits()->maxToolsPerTurn);
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
        $seeded = null;
        try {
            $start = $this->open($request, $row, $seeded);
        } catch (\Throwable $e) {
            // The checkpoint was not written: the row must not stay "running" for a run that does not exist.
            if (null !== $row) {
                $this->records->abandon($row);
            }

            throw $e;
        }
        if ($start->opened) {
            $this->events->append($start->runId, ChatRunEvents::STARTED, ['chat_id' => $request->chatId]);
            $this->announce($start->runId, $seeded);
        }

        return $start;
    }

    public function stop(string $runId): void
    {
        $this->runs->stop($runId);
    }

    /**
     * @param-out string|null       $row    the run whose row this call wrote
     * @param-out ChatRunState|null $seeded the new run, when it took over a first answer
     */
    private function open(ChatRunRequest $request, ?string &$row, ?ChatRunState &$seeded): RunStart
    {
        return $this->runs->start($request->userId, $request->idempotencyKey, ChatRunStep::UNIT, function (string $runId) use ($request, &$row, &$seeded): ChatRunState {
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
            $state->replyMessageId = $request->replyMessageId;
            $state->rates = $request->prompt->rates;
            $state->window = $request->prompt->window;
            $state->head = \count($request->prompt->messages);
            if (null !== $request->first) {
                $this->answers->seed($state, $request->first);
                $seeded = $state;
            }
            $this->records->open($state, $request->startedBy, $request->job, $request->idempotencyKey);
            $row = $runId;

            return $state;
        });
    }

    /** The calls and plan of a first answer, now that the run has its log. */
    private function announce(string $runId, ?ChatRunState $seeded): void
    {
        if (null === $seeded) {
            return;
        }
        if ([] !== $seeded->plan) {
            $this->events->append($runId, ChatRunEvents::PLAN, ['items' => $seeded->plan]);
        }
        foreach ($seeded->pending as $call) {
            $this->events->append($runId, ChatRunEvents::TOOL_CALL, ['tool' => $call['name']]);
        }
    }
}
