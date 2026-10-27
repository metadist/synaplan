<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Service\Multitask\Plan\TaskPlan;

/**
 * One usage figure for a whole task, from the nodes that ran.
 *
 * A node the facade already billed does not add its tokens to the figure the
 * turn still has to bill. Those tokens stay on `display_usage` so the chat
 * can show them next to the cost. Nodes the facade did not see are summed
 * into `usage`, and that sum is what the turn's own row bills.
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
        $billedPrompt = 0;
        $billedCompletion = 0;
        $billedTotal = 0;
        $shownPrompt = 0;
        $shownCompletion = 0;
        $shownTotal = 0;
        $charged = 0.0;
        $meteredNodes = 0;
        $priceKnown = true;
        foreach ($plan->nodes as $usageNode) {
            $result = $context->getResult($usageNode->id);
            if (null === $result || !$result->isSuccessful()) {
                continue;
            }
            $nodeUsage = is_array($result->metadata['usage'] ?? null) ? $result->metadata['usage'] : [];
            $nodePrompt = (int) ($nodeUsage['prompt_tokens'] ?? 0);
            $nodeCompletion = (int) ($nodeUsage['completion_tokens'] ?? 0);
            $nodeTotal = (int) ($nodeUsage['total_tokens'] ?? ($nodePrompt + $nodeCompletion));
            $shownPrompt += $nodePrompt;
            $shownCompletion += $nodeCompletion;
            $shownTotal += $nodeTotal;
            if (true === ($result->metadata['facade_metered'] ?? false)) {
                ++$meteredNodes;
                $cost = $result->metadata['facade_charged_cost'] ?? null;
                if (is_string($cost) && is_numeric($cost)) {
                    $charged += (float) $cost;
                }
                if (false === ($result->metadata['facade_price_known'] ?? true)) {
                    $priceKnown = false;
                }
                continue;
            }
            $billedPrompt += $nodePrompt;
            $billedCompletion += $nodeCompletion;
            $billedTotal += $nodeTotal;
        }
        if ($billedTotal > 0) {
            $metadata['usage'] = [
                'prompt_tokens' => $billedPrompt,
                'completion_tokens' => $billedCompletion,
                'total_tokens' => $billedTotal,
            ];
        }
        if ($shownTotal > 0) {
            $metadata['display_usage'] = [
                'prompt_tokens' => $shownPrompt,
                'completion_tokens' => $shownCompletion,
                'total_tokens' => $shownTotal,
            ];
        }
        if ($meteredNodes > 0 && 0 === $billedTotal) {
            $metadata['facade_metered'] = true;
            $metadata['facade_charged_cost'] = number_format($charged, 6, '.', '');
            $metadata['facade_price_known'] = $priceKnown;
        }

        return $metadata;
    }
}
