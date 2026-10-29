<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Service\Research\Run\ResearchLease;

/**
 * One structured or plain model call. Tests replay recorded answers through
 * their own implementation. The live caller is the only production one.
 *
 * When $lease is set, the call is inside a claimed step. Renew it at least
 * every ResearchLease::waitMs() while the provider is still working, and
 * stop when renew returns false. A caller that cannot renew mid-request
 * returns inside that wait, so the claim is still held when it comes back.
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
        ?ResearchLease $lease = null,
    ): ResearchModelReply;
}
