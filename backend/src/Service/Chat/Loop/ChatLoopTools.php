<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Tool\Execution\ToolLimits;

/**
 * The loop's own tools: `update_plan` keeps the short work list, `finish`
 * ends a run that has one. Neither goes through the tool registry; the loop
 * answers them itself.
 */
final class ChatLoopTools
{
    public const UPDATE_PLAN = 'update_plan';
    public const FINISH = 'finish';

    public const MAX_PLAN_ITEMS = 5;
    public const MAX_ITEM_CHARS = 200;
    public const MAX_RESULT_CHARS = ToolLimits::RESULT_CHARS * 4;

    /** @return array<string, mixed> */
    public static function updatePlan(): array
    {
        return self::fn(
            self::UPDATE_PLAN,
            'Set the short plan for work with more than two steps, and keep it current. List only what is still to do or in progress, at most 5 items. Mark an item done when it is done.',
            [
                'items' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_PLAN_ITEMS,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string', 'description' => 'One step, a few words.'],
                            'state' => ['type' => 'string', 'enum' => ChatRunState::PLAN_STATES],
                        ],
                        'required' => ['text', 'state'],
                    ],
                ],
            ],
            ['items'],
        );
    }

    /** @return array<string, mixed> */
    public static function finish(): array
    {
        return self::fn(
            self::FINISH,
            'End the work with the final answer for the person.',
            [
                'result' => ['type' => 'string', 'description' => 'The complete final answer, in the language of the person.'],
                'outcome' => ['type' => 'string', 'enum' => ['done', 'partial'], 'description' => 'done when every step was done, partial otherwise.'],
            ],
            ['result', 'outcome'],
        );
    }

    /**
     * Apply an `update_plan` call to the run. The text is what the model gets back.
     *
     * @param array<string, mixed> $args
     */
    public static function applyPlan(ChatRunState $state, array $args): string
    {
        $items = $args['items'] ?? null;
        if (!\is_array($items)) {
            return 'update_plan needs items: a list of {text, state}.';
        }
        $plan = [];
        foreach ($items as $item) {
            if (!\is_array($item) || !\is_string($item['text'] ?? null) || '' === trim($item['text'])) {
                continue;
            }
            $itemState = \in_array($item['state'] ?? null, ChatRunState::PLAN_STATES, true) ? (string) $item['state'] : ChatRunState::PLAN_PENDING;
            $plan[] = ['text' => mb_substr(trim($item['text']), 0, self::MAX_ITEM_CHARS), 'state' => $itemState];
            if (\count($plan) >= self::MAX_PLAN_ITEMS) {
                break;
            }
        }
        $state->plan = $plan;
        $open = $state->openItems();

        return [] === $open ? 'Plan updated. Every item is done.' : 'Plan updated. Open: '.implode('; ', $open).'.';
    }

    /**
     * The final answer of a `finish` call, or null when it carries none.
     *
     * @param array<string, mixed> $args
     */
    public static function finishResult(array $args): ?string
    {
        $result = $args['result'] ?? null;
        if (!\is_string($result) || '' === trim($result)) {
            return null;
        }

        return mb_substr($result, 0, self::MAX_RESULT_CHARS);
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string>         $required
     *
     * @return array<string, mixed>
     */
    private static function fn(string $name, string $description, array $properties, array $required): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => ['type' => 'object', 'properties' => $properties, 'required' => $required],
            ],
        ];
    }
}
