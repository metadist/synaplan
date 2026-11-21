<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Prompt\PromptCatalog;
use App\Service\Prompt\ChatPromptBlocks;

/**
 * Builds the system message of one unit: the chat's own prompt as the turn
 * had it, the loop rules of `tools:chat_loop`, and the project instructions
 * as they are now.
 */
final class ChatLoopPrompt
{
    public const TOPIC = 'tools:chat_loop';

    /** Added instead of the loop rules when the budget cannot pay for a long answer. */
    public const LOW_BUDGET = 'The monthly budget of this person is nearly used up, so this answer cannot use tools or search. '
        .'Answer from what you already know. Say in one short sentence, in the language of your answer, '
        .'that a deeper answer was not possible because the monthly budget is nearly used up.';

    /** Added instead of the loop rules when the share per answer cannot pay for a long answer with this model. */
    public const LOW_LIMIT = 'The cost limit per answer this person chose is too low for a long answer with this model, so this answer cannot use tools or search. '
        .'Answer from what you already know. Say in one short sentence, in the language of your answer, '
        .'that a deeper answer needs a higher cost limit per answer (Settings, Chat & AI) or a cheaper model.';

    public static function system(ChatRunState $state, ChatRunScope $scope): string
    {
        $text = rtrim($state->basePrompt)."\n\n".self::rules();
        $project = $scope->project;
        if (null !== $project && null !== $project->instructions) {
            $text .= ChatPromptBlocks::projectInstructions($project->name, $project->instructions);
        }

        return $text;
    }

    public static function rules(): string
    {
        foreach (PromptCatalog::all() as $entry) {
            if (self::TOPIC === $entry['topic']) {
                return $entry['prompt'];
            }
        }

        throw new \LogicException('The chat loop prompt is not in the catalog.');
    }
}
