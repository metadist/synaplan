<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

use App\AI\Interface\ChatProviderInterface;

/**
 * One round-mode decision. A set finish ignores actions. A search past the budget is dropped.
 */
final class RoundModeLoop
{
    /**
     * @return array{stopped: string, searches: int}
     */
    public function run(ChatProviderInterface $provider, string $user, ToolBudget $budget): array
    {
        $result = $provider->chat(
            [
                ['role' => 'system', 'content' => 'Research spike decision.'],
                ['role' => 'user', 'content' => $user],
            ],
            ['model' => 'test-model'],
        );
        $content = $result['content'];
        $decision = $this->decision($content);
        $finish = $decision['finish'] ?? null;
        if (is_string($finish) && '' !== $finish) {
            return ['stopped' => $finish, 'searches' => 0];
        }

        $searches = 0;
        foreach ($decision['actions'] ?? [] as $action) {
            if (!is_array($action) || 'search' !== ($action['type'] ?? '')) {
                continue;
            }
            if (null !== $budget->takeSearch()) {
                continue;
            }
            ++$searches;
        }

        return ['stopped' => 'continue', 'searches' => $searches];
    }

    /**
     * @return array<string, mixed>
     */
    private function decision(string $content): array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if (false === $start || false === $end || $end <= $start) {
            return [];
        }

        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
