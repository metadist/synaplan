<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Service\Research\Run\Stage\ResearchModelCaller;
use App\Service\Research\Run\Stage\ResearchModelReply;

/** Plays recorded model answers in order and keeps the prompts it was given. */
final class ReplayCaller implements ResearchModelCaller
{
    /** @var list<ResearchModelReply> */
    public array $script = [];

    /** @var list<array{system: string, user: string}> */
    public array $seen = [];

    public function answer(
        int $userId,
        int $modelId,
        string $system,
        string $user,
        ?StructuredOutputSchema $schema,
    ): ResearchModelReply {
        $this->seen[] = ['system' => $system, 'user' => $user];
        $next = array_shift($this->script);
        if (!$next instanceof ResearchModelReply) {
            throw new \RuntimeException('No replay left for the model call.');
        }

        return $next;
    }
}
