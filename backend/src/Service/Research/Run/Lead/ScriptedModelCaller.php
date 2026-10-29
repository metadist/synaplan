<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Service\Research\Run\Stage\ResearchModelCaller;
use App\Service\Research\Run\Stage\ResearchModelReply;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Recorded reader answers for the conformance suite. */
#[Exclude]
final class ScriptedModelCaller implements ResearchModelCaller
{
    /** @var list<ResearchModelReply> */
    public array $script = [];

    /** Answered once the script is used up. */
    public ?ResearchModelReply $fallback = null;

    /** @var list<string> */
    public array $users = [];

    public function answer(int $userId, int $modelId, string $system, string $user, ?StructuredOutputSchema $schema, ?\Closure $onText = null): ResearchModelReply
    {
        $this->users[] = $user;
        $reply = array_shift($this->script) ?? $this->fallback ?? ResearchModelReply::failed();
        if ($onText instanceof \Closure && null === $schema && '' !== $reply->text) {
            $onText($reply->text);
        }

        return $reply;
    }
}
