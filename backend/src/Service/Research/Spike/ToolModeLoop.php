<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

use App\AI\Interface\ChatProviderInterface;

/**
 * One tool-mode lead. A search past the budget returns budget_used and does not run.
 */
final class ToolModeLoop
{
    /**
     * @return array{stopped: string, searches: int, toolResult: ?string}
     */
    public function run(ChatProviderInterface $provider, string $user, ToolBudget $budget): array
    {
        $messages = [
            ['role' => 'system', 'content' => 'Research spike lead. Call search or finish.'],
            ['role' => 'user', 'content' => $user],
        ];
        $tools = [[
            'type' => 'function',
            'function' => ['name' => 'search', 'parameters' => ['type' => 'object']],
        ], [
            'type' => 'function',
            'function' => ['name' => 'finish', 'parameters' => ['type' => 'object']],
        ]];
        $searches = 0;
        $reminded = false;
        $toolResult = null;

        for ($turn = 0; $turn < 4; ++$turn) {
            $result = $provider->chat($messages, ['model' => 'test-model', 'tools' => $tools]);
            $calls = is_array($result['tool_calls'] ?? null) ? $result['tool_calls'] : [];
            if ([] === $calls) {
                if ($reminded) {
                    return ['stopped' => 'no_progress', 'searches' => $searches, 'toolResult' => $toolResult];
                }
                $reminded = true;
                $messages[] = ['role' => 'user', 'content' => 'Call finish.'];
                continue;
            }

            foreach ($calls as $call) {
                $function = is_array($call['function'] ?? null) ? $call['function'] : [];
                $name = $function['name'] ?? '';
                $id = is_string($call['id'] ?? null) ? $call['id'] : 'call';
                if ('finish' === $name) {
                    return ['stopped' => 'finish', 'searches' => $searches, 'toolResult' => $toolResult];
                }
                if ('search' !== $name) {
                    continue;
                }
                $rejection = $budget->takeSearch();
                $toolResult = null === $rejection
                    ? '{"results":[]}'
                    : json_encode($rejection, JSON_THROW_ON_ERROR);
                if (null === $rejection) {
                    ++$searches;
                }
                $messages[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [$call]];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $id,
                    'content' => $toolResult,
                ];
            }
        }

        return ['stopped' => 'no_progress', 'searches' => $searches, 'toolResult' => $toolResult];
    }
}
