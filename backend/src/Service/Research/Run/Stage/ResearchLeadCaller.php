<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\ToolCalling\ToolDefinition;

/**
 * One lead turn with native tools. Tests replay recorded turns. The live
 * caller is the only production one, and it does not force a tool call.
 */
interface ResearchLeadCaller
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<ToolDefinition>       $tools
     */
    public function turn(int $userId, int $modelId, array $messages, array $tools): ResearchLeadTurn;
}
