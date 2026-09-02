<?php

declare(strict_types=1);

namespace App\AI\ToolCalling;

/**
 * One tool invocation the model asked for, normalised across providers.
 *
 * Providers return tool calls in incompatible shapes — OpenAI nests them under
 * `choices[].message.tool_calls[].function` with `arguments` as a JSON *string*,
 * Anthropic emits `content[]` blocks of `type: tool_use` with `input` already
 * decoded, Google uses `functionCall` with `args`. {@see ToolCallParser}
 * flattens all of them into this one shape, so call-sites never branch on the
 * provider.
 */
final readonly class ToolCall
{
    /**
     * @param string               $id        provider-assigned call id; empty string when the provider does
     *                                        not supply one (Google). Only needed to correlate a tool RESULT
     *                                        back to its call, which the single-shot routing use case in
     *                                        {@see \App\Service\Message\Routing\RoutingToolset} never does.
     * @param string               $name      the {@see ToolDefinition::$name} the model picked
     * @param array<string, mixed> $arguments decoded arguments; an empty array both when the tool takes no
     *                                        arguments and when the model sent an unparseable argument blob
     *                                        (see {@see ToolCallParser} — a malformed argument object must
     *                                        never lose the fact that the tool was called)
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
    ) {
    }
}
