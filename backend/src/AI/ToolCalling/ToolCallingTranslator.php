<?php

declare(strict_types=1);

namespace App\AI\ToolCalling;

/**
 * Translates provider-agnostic {@see ToolDefinition}s into the request
 * options {@see \App\AI\Interface\ChatProviderInterface} documents.
 *
 * That wire shape is OpenAI's function-declaration format for every provider:
 * each provider maps it into its own dialect on the way out (Anthropic
 * `input_schema`, Gemini `functionDeclarations`, the Responses API's flat
 * tools) via {@see \App\AI\Tool\OpenAiToolShapes}. This class therefore has
 * no per-provider branches — it only decides WHETHER tools may be declared
 * and hands back the canonical shape.
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
     * @return array<string, mixed> request options to merge into `$options` for
     *                              {@see \App\AI\Service\AiFacade::chat()}, or an empty array when the
     *                              provider/model/streaming combination cannot do native tool calling —
     *                              callers MUST then proceed without tools, never error out
     */
    public function translate(string $providerName, ?string $model, bool $streaming, array $tools): array
    {
        if ([] === $tools || !$this->capability->supports($providerName, $model, $streaming)) {
            return [];
        }

        return [
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
        ];
    }
}
