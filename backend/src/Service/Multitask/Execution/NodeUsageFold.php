<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Service\Multitask\Plan\TaskPlan;

/**
 * One usage figure for a whole task, from the nodes that ran.
 *
 * A node the facade already billed does not add its tokens again. Its cost is
 * kept so the chat can show what the provider charged. Nodes the facade did
 * not see are still summed, and that sum is what the turn's own row bills.
 */
final class NodeUsageFold
{
    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    public static function into(TaskPlan $plan, NodeContext $context, array $metadata): array
    {
        $promptTokens = 0;
        $completionTokens = 0;
        $totalTokens = 0;
        $charged = 0.0;
        $meteredNodes = 0;
        foreach ($plan->nodes as $usageNode) {
            $usageNodeResult = $context->getResult($usageNode->id);
            if (null === $usageNodeResult || !$usageNodeResult->isSuccessful()) {
                continue;
            }
            if (true === ($usageNodeResult->metadata['facade_metered'] ?? false)) {
                ++$meteredNodes;
                $cost = $usageNodeResult->metadata['facade_charged_cost'] ?? null;
                if (is_string($cost) && is_numeric($cost)) {
                    $charged += (float) $cost;
                }
                continue;
            }
            $nodeUsage = $usageNodeResult->metadata['usage'] ?? null;
            if (!is_array($nodeUsage) || [] === $nodeUsage) {
                continue;
            }
            $nodePrompt = (int) ($nodeUsage['prompt_tokens'] ?? 0);
            $nodeCompletion = (int) ($nodeUsage['completion_tokens'] ?? 0);
            $promptTokens += $nodePrompt;
            $completionTokens += $nodeCompletion;
            $totalTokens += (int) ($nodeUsage['total_tokens'] ?? ($nodePrompt + $nodeCompletion));
        }
        if ($totalTokens > 0) {
            $metadata['usage'] = [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
            ];
        }
        if ($meteredNodes > 0 && 0 === $totalTokens) {
            $metadata['facade_metered'] = true;
            $metadata['facade_charged_cost'] = number_format($charged, 6, '.', '');
        }

        return $metadata;
    }
}
