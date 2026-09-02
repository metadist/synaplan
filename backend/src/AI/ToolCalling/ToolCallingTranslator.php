<?php

declare(strict_types=1);

namespace App\AI\ToolCalling;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<ToolDefinition> $tools
     * @param bool                 $withStructuredOutput whether the same request also carries a JSON schema;
     *                                                   providers MUST pass this, because for some of them the two
     *                                                   cannot coexist ({@see ToolCallingCapability::conflictsWithStructuredOutput()})
     *
     * @return array<string, mixed> extra request parameters to merge into the provider's
     *                              payload, or an empty array when the provider/model/streaming
     *                              combination cannot do native tool calling — callers MUST then
     *                              proceed without tools, never error out
     */
    public function translate(string $providerName, ?string $model, bool $streaming, array $tools, bool $withStructuredOutput = false): array
    {
        if ([] === $tools || !$this->capability->supports($providerName, $model, $streaming)) {
            return [];
        }

        // The schema wins: it is the caller's output contract and something
        // downstream parses against it, whereas "no tool call" is already a
        // valid outcome of every toolset we declare. Dropping the tools here
        // rather than merging both keeps the request valid on Groq and stops
        // Anthropic's forced schema tool from being overwritten.
        if ($withStructuredOutput && $this->capability->conflictsWithStructuredOutput($providerName)) {
            $this->logger->warning('Tool declaration dropped: provider cannot combine tools with structured output', [
                'provider' => $providerName,
                'model' => $model,
                'tools' => array_map(static fn (ToolDefinition $tool): string => $tool->name, $tools),
            ]);

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
