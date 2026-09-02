<?php

declare(strict_types=1);

namespace App\AI\ToolCalling;

/**
 * Translates provider-agnostic {@see ToolDefinition}s into the request
 * parameters a specific provider dialect expects.
 *
 * Providers call this from inside their own payload construction, exactly as
 * they already do for {@see \App\AI\StructuredOutput\StructuredOutputTranslator}
 * — this class does not know how to merge its output into a request array;
 * that stays the provider's job.
 */
final readonly class ToolCallingTranslator
{
    public function __construct(
        private ToolCallingCapability $capability,
    ) {
    }

    /**
     * @param list<ToolDefinition> $tools
     *
     * @return array<string, mixed> extra request parameters to merge into the provider's
     *                              payload, or an empty array when the provider/model/streaming
     *                              combination cannot do native tool calling — callers MUST then
     *                              proceed without tools, never error out
     */
    public function translate(string $providerName, ?string $model, bool $streaming, array $tools): array
    {
        if ([] === $tools || !$this->capability->supports($providerName, $model, $streaming)) {
            return [];
        }

        return match ($this->capability->dialect($providerName)) {
            ToolCallingDialect::OPENAI_FUNCTIONS => [
                'tools' => array_map(
                    static fn (ToolDefinition $tool): array => [
                        'type' => 'function',
                        'function' => [
                            'name' => $tool->name,
                            'description' => $tool->description,
                            'parameters' => $tool->parameters,
                        ],
                    ],
                    $tools
                ),
                // `auto` — never `required`. The whole point of the routing
                // toolset is that "no tool call" is a meaningful answer
                // (= this is an ordinary chat turn), so forcing a call would
                // invert the default.
                'tool_choice' => 'auto',
            ],
            ToolCallingDialect::ANTHROPIC_TOOLS => [
                'tools' => array_map(
                    static fn (ToolDefinition $tool): array => [
                        'name' => $tool->name,
                        'description' => $tool->description,
                        'input_schema' => $tool->parameters,
                    ],
                    $tools
                ),
                'tool_choice' => ['type' => 'auto'],
            ],
            null => [],
        };
    }
}
