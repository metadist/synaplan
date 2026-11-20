<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Tool\Execution\ToolLimits;

/**
 * Reads one model answer of a chat run and moves the run on: queue its tool
 * calls, nudge a step without a tool while the plan is open, ask for the
 * required `finish` once the plan is done, or end the run.
 */
final readonly class ChatRunAnswer
{
    public const MAX_NUDGES = 5;

    public const JSON_REQUEST = 'Reply with only a JSON object {"result": "<the complete final answer>", "outcome": "done" or "partial"} and nothing else.';

    public function __construct(
        private ChatRunEvents $events,
        private int $maxToolsPerStep,
    ) {
    }

    /**
     * @return string|null the next unit, or null when the run ended
     */
    public function apply(ChatRunState $state, ChatRunReply $reply): ?string
    {
        ++$state->steps;
        $state->costMicro += max(0, $reply->costMicro);

        return match ($state->mode) {
            ChatRunState::MODE_FINISH => $this->finishCall($state, $reply),
            ChatRunState::MODE_FINISH_JSON => $this->finishJson($state, $reply),
            default => [] === $reply->toolCalls ? $this->noTool($state, $reply) : $this->toolCalls($state, $reply),
        };
    }

    /**
     * The provider refused the call. Asking for `finish` may be refused by a
     * model that takes no required tool choice: ask for plain JSON once. Any
     * other failure keeps the claim, so the kernel tries the unit again.
     */
    public function failed(ChatRunState $state, ChatRunModelFailed $failed): ?string
    {
        if (ChatRunState::MODE_FINISH === $state->mode) {
            $state->mode = ChatRunState::MODE_FINISH_JSON;

            return ChatRunStep::UNIT;
        }
        if (ChatRunState::MODE_FINISH_JSON === $state->mode) {
            $this->end($state, ChatRunEnd::FinishFallback, $state->lastAssistantText());

            return null;
        }

        throw $failed;
    }

    public function end(ChatRunState $state, ChatRunEnd $end, ?string $answer = null): void
    {
        $state->finish($end, $answer);
        $this->events->append($state->runId, ChatRunEvents::ENDED, ['status' => $state->status->value, 'reason' => $end->value]);
    }

    private function noTool(ChatRunState $state, ChatRunReply $reply): ?string
    {
        if ([] === $state->plan) {
            $this->end($state, ChatRunEnd::Answered, $reply->content);

            return null;
        }
        if ('' !== trim($reply->content)) {
            $state->messages[] = ['role' => 'assistant', 'content' => $reply->content];
        }
        if ($state->planDone()) {
            $state->mode = ChatRunState::MODE_FINISH;

            return ChatRunStep::UNIT;
        }
        ++$state->nudges;
        if ($state->nudges >= self::MAX_NUDGES) {
            $this->end($state, ChatRunEnd::NoProgress, $state->lastAssistantText());

            return null;
        }
        $open = $state->openItems();
        $state->messages[] = ['role' => 'user', 'content' => 'Continue with the next open item: '.$open[0].'. Use a tool, or update the plan when it is done.'];

        return ChatRunStep::UNIT;
    }

    private function toolCalls(ChatRunState $state, ChatRunReply $reply): string
    {
        $state->nudges = 0;
        $wire = [];
        foreach ($reply->toolCalls as $call) {
            $wire[] = [
                'id' => $call['id'],
                'type' => 'function',
                'function' => ['name' => $call['name'], 'arguments' => json_encode((object) $call['args'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE)],
            ];
            if (isset($call['signature'])) {
                // Gemini refuses a follow-up whose function call lost its thought signature.
                $wire[\array_key_last($wire)]['thought_signature'] = $call['signature'];
            }
        }
        $state->messages[] = ['role' => 'assistant', 'content' => $reply->content, 'tool_calls' => $wire];
        foreach ($reply->toolCalls as $i => $call) {
            $answer = match (true) {
                $i >= $this->maxToolsPerStep => ToolLimits::SKIPPED_RESULT,
                ChatLoopTools::UPDATE_PLAN === $call['name'] => $this->plan($state, $call['args']),
                ChatLoopTools::FINISH === $call['name'] => 'finish is offered only when every plan item is done. Keep working.',
                default => null,
            };
            if (null === $answer) {
                $state->pending[] = ['id' => $call['id'], 'name' => $call['name'], 'args' => $call['args']];
                $this->events->append($state->runId, ChatRunEvents::TOOL_CALL, ['tool' => $call['name']]);
                continue;
            }
            $state->messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'name' => $call['name'], 'content' => $answer];
        }

        return ChatRunStep::UNIT;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function plan(ChatRunState $state, array $args): string
    {
        $text = ChatLoopTools::applyPlan($state, $args);
        $this->events->append($state->runId, ChatRunEvents::PLAN, ['items' => $state->plan]);

        return $text;
    }

    private function finishCall(ChatRunState $state, ChatRunReply $reply): ?string
    {
        foreach ($reply->toolCalls as $call) {
            if (ChatLoopTools::FINISH !== $call['name']) {
                continue;
            }
            $result = ChatLoopTools::finishResult($call['args']);
            if (null !== $result) {
                $this->end($state, ChatRunEnd::Finished, $result);

                return null;
            }
        }
        $state->mode = ChatRunState::MODE_FINISH_JSON;

        return ChatRunStep::UNIT;
    }

    /** Ends the run either way: with the JSON result, or with the best text the run has. */
    private function finishJson(ChatRunState $state, ChatRunReply $reply): null
    {
        $text = trim($reply->content);
        if (1 === preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $fence)) {
            $text = $fence[1];
        }
        try {
            $data = json_decode($text, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        $result = \is_array($data) ? ChatLoopTools::finishResult($data) : null;
        if (null !== $result) {
            $this->end($state, ChatRunEnd::Finished, $result);
        } else {
            // Prose is shown; a broken JSON attempt is not.
            $plain = '' !== $text && !str_starts_with($text, '{') && !str_starts_with($text, '```') ? $reply->content : null;
            $this->end($state, ChatRunEnd::FinishFallback, $plain ?? $state->lastAssistantText());
        }

        return null;
    }
}
