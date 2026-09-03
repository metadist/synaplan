<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Service\Multitask\Plan\Capability;

/**
 * JSON schema for {@see \App\Service\Multitask\TaskPlanner}'s planning call.
 *
 * Unlike {@see SortClassificationSchema}, `capability` is a fixed, code-owned
 * vocabulary ({@see Capability}) rather than a per-user dynamic list, so the
 * enum can be built once from `Capability::values()`.
 *
 * `inputs`/`params` are deliberately left as open, unconstrained objects:
 * their shape depends on the capability of the node they belong to (e.g. a
 * `calendar_event` node's `params` differ entirely from a `text2sound`
 * node's), so there is no single fixed property set to declare. This is why
 * the schema is built with `strict: false` — OpenAI/Groq strict mode requires
 * `additionalProperties: false` on every object in the tree, which is
 * incompatible with a genuinely open-ended `inputs`/`params` object. The
 * structural rules that DO apply (known node ids, no self-reference, acyclic
 * graph, `reply_node` resolves) stay enforced by
 * {@see \App\Service\Multitask\Plan\TaskPlanValidator} on the decoded output,
 * exactly as they are today for providers without schema support.
 */
final class TaskPlanSchema
{
    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(
            name: 'task_plan',
            schema: [
                'type' => 'object',
                'properties' => [
                    'version' => ['type' => 'integer', 'enum' => [1]],
                    'language' => ['type' => 'string'],
                    'reply_node' => ['type' => 'string'],
                    'tasks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'capability' => ['type' => 'string', 'enum' => Capability::values()],
                                'depends_on' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'inputs' => ['type' => 'object'],
                                'params' => ['type' => 'object'],
                            ],
                            'required' => ['id', 'capability'],
                        ],
                    ],
                ],
                'required' => ['version', 'language', 'reply_node', 'tasks'],
            ],
            strict: false,
        );
    }
}
