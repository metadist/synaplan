<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * One structured or plain model call. Tests replay recorded answers through
 * their own implementation. The live caller is the only production one.
 */
interface ResearchModelCaller
{
    /**
     * @param \Closure(string): void|null $onText receives each piece of a plain
     *                                            answer as it arrives; a structured call ignores it
     */
    public function answer(
        int $userId,
        int $modelId,
        string $system,
        string $user,
        ?StructuredOutputSchema $schema,
        ?\Closure $onText = null,
    ): ResearchModelReply;
}
