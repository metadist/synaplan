<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Run\RunClaim;
use App\Service\Tool\Execution\ToolLimits;

/**
 * One unit of a chat run: re-check who and what the run may use, run the
 * tool calls of the last model answer, then make one model call.
 *
 * Before the paid call it reads the stop flag and the payer's budget. A
 * write that needs approval parks the run; nothing here approves it.
 */
final readonly class ChatRunStep
{
    public const UNIT = 'step';

    /** Said for a declined and for an expired approval alike, so it claims neither. */
    public const DECLINED = 'The person did not approve this action. Do not try it again in this run.';

    private ChatRunAnswer $answers;

    public function __construct(
        private ChatRunWorld $world,
        private ChatRunModel $model,
        private ChatRunBudget $budget,
        private ChatRunToolbox $tools,
        private ChatRunEvents $events,
    ) {
        $this->answers = new ChatRunAnswer($events, ChatRunProfile::limits()->maxToolsPerTurn);
    }

    /**
     * @return string|null the next unit, or null when the run ended or waits
     */
    public function run(ChatRunState $state, RunClaim $claim): ?string
    {
        $scope = $this->world->resolve($state);
        if ($scope instanceof ChatRunEnd) {
            $this->answers->end($state, $scope);

            return null;
        }
        if (!$this->runPending($state, $scope, $claim)) {
            return null;
        }
        if ($claim->cancelled()) {
            $this->answers->end($state, ChatRunEnd::StoppedByUser);

            return null;
        }
        if (!$this->budget->allows($scope->user)) {
            $this->answers->end($state, ChatRunEnd::BudgetUsed);

            return null;
        }
        $claim->renew();

        return $this->call($state, $scope);
    }

    /** The run was asked to stop before this unit began. */
    public function stop(ChatRunState $state): void
    {
        $this->answers->end($state, ChatRunEnd::StoppedByUser);
    }

    /** The unit was lost twice: end the run as interrupted, keeping what it has. */
    public function interrupt(ChatRunState $state): void
    {
        $this->answers->end($state, ChatRunEnd::WorkerLost);
    }

    private function call(ChatRunState $state, ChatRunScope $scope): ?string
    {
        $messages = [['role' => 'system', 'content' => ChatLoopPrompt::system($state, $scope)], ...$state->messages];
        [$tools, $choice] = match ($state->mode) {
            ChatRunState::MODE_FINISH => [[ChatLoopTools::finish()], 'required'],
            ChatRunState::MODE_FINISH_JSON => [[], null],
            default => [[...$this->tools->declarations($scope, $state->opened), ChatLoopTools::updatePlan()], 'auto'],
        };
        if (ChatRunState::MODE_FINISH_JSON === $state->mode) {
            $messages[] = ['role' => 'user', 'content' => ChatRunAnswer::JSON_REQUEST];
        }
        $this->events->append($state->runId, ChatRunEvents::STEP, ['step' => $state->steps + 1]);
        try {
            $reply = $this->model->call($state, $messages, $tools, $choice);
        } catch (ChatRunModelFailed) {
            return $this->answers->failed($state);
        }

        return $this->answers->apply($state, $reply);
    }

    /**
     * Runs the calls the last model answer left, in order.
     *
     * @return bool false when the run now waits for an approval or ended
     */
    private function runPending(ChatRunState $state, ChatRunScope $scope, RunClaim $claim): bool
    {
        while ([] !== $state->pending) {
            if ($claim->cancelled()) {
                $this->answers->end($state, ChatRunEnd::StoppedByUser);

                return false;
            }
            $call = $state->pending[0];
            $decision = $call['decision'] ?? null;
            if (null === $decision && isset($call['approval'])) {
                $state->waitForApproval();

                return false;
            }
            $result = match ($decision) {
                ChatRunState::DECISION_REJECTED => ChatToolResult::text(self::DECLINED),
                ChatRunState::DECISION_APPROVED => $this->tools->run($scope, $state, $call, $call['approval'] ?? null),
                default => $this->fresh($state, $scope, $call),
            };
            if ($result->waits()) {
                $state->pending[0]['approval'] = (int) $result->approvalId;
                $state->waitForApproval();
                $this->events->append($state->runId, ChatRunEvents::WAITING, ['reason' => ChatRunState::WAIT_APPROVAL, 'tool' => $call['name']]);

                return false;
            }
            $this->record($state, $call, $result->text);
            array_shift($state->pending);
            $claim->renew();
        }

        return true;
    }

    /**
     * @param array{id: string, name: string, args: array<string, mixed>} $call
     */
    private function fresh(ChatRunState $state, ChatRunScope $scope, array $call): ChatToolResult
    {
        if ($this->tools->isRead($call['name'])) {
            $seen = $state->reads[self::hash($call)] ?? null;
            $content = null === $seen ? null : ($state->messages[$seen]['content'] ?? null);
            if (\is_string($content)) {
                return ChatToolResult::text($content);
            }
        }

        return $this->tools->run($scope, $state, $call);
    }

    /**
     * @param array{id: string, name: string, args: array<string, mixed>} $call
     */
    private function record(ChatRunState $state, array $call, string $text): void
    {
        $state->messages[] = [
            'role' => 'tool',
            'tool_call_id' => $call['id'],
            'name' => $call['name'],
            'content' => ToolLimits::clamp($text),
        ];
        if ($this->tools->isRead($call['name'])) {
            $state->reads[self::hash($call)] = \count($state->messages) - 1;
        }
        $this->events->append($state->runId, ChatRunEvents::TOOL_RESULT, ['tool' => $call['name'], 'chars' => mb_strlen($text)]);
    }

    /**
     * @param array{id: string, name: string, args: array<string, mixed>} $call
     */
    private static function hash(array $call): string
    {
        $args = $call['args'];
        ksort($args);

        return 'r:'.sha1($call['name']."\n".json_encode($args, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
