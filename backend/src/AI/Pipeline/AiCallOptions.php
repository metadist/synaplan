<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * Typed options for an {@see AiCall} (`03_contracts.md` §1.7.2).
 */
final readonly class AiCallOptions
{
    /**
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>       $providerOptions
     */
    public function __construct(
        public ?int $modelId = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public bool $stream = false,
        public ?string $reasoningEffort = null,
        public ?StructuredOutputSchema $structuredOutput = null,
        public array $tools = [],
        public bool $callerMeters = false,
        public ?AiCallDeadline $deadline = null,
        public array $providerOptions = [],
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options, bool $stream = false, bool $callerMeters = false): self
    {
        $schema = $options['structured_output'] ?? null;
        $tools = \is_array($options['tools'] ?? null) ? array_values($options['tools']) : [];

        return new self(
            modelId: isset($options['model_id']) && is_numeric($options['model_id']) ? (int) $options['model_id'] : null,
            temperature: isset($options['temperature']) && is_numeric($options['temperature']) ? (float) $options['temperature'] : null,
            maxTokens: isset($options['max_tokens']) && is_numeric($options['max_tokens']) ? (int) $options['max_tokens'] : null,
            stream: $stream || (bool) ($options['stream'] ?? false),
            reasoningEffort: \is_string($options['reasoning_effort'] ?? null) ? $options['reasoning_effort'] : null,
            structuredOutput: $schema instanceof StructuredOutputSchema ? $schema : null,
            tools: $tools,
            callerMeters: $callerMeters || true === ($options['caller_meters'] ?? false),
            deadline: ($options['deadline'] ?? null) instanceof AiCallDeadline ? $options['deadline'] : null,
            providerOptions: $options,
        );
    }

    /**
     * @return string|array<string, mixed>|null
     */
    public function toolChoice(): string|array|null
    {
        $toolChoice = $this->providerOptions['tool_choice'] ?? null;

        return \is_string($toolChoice) || \is_array($toolChoice) ? $toolChoice : null;
    }

    public function language(): ?string
    {
        $language = $this->providerOptions['language'] ?? null;

        return \is_string($language) ? $language : null;
    }

    public function withDeadline(AiCallDeadline $deadline): self
    {
        return new self(
            modelId: $this->modelId,
            temperature: $this->temperature,
            maxTokens: $this->maxTokens,
            stream: $this->stream,
            reasoningEffort: $this->reasoningEffort,
            structuredOutput: $this->structuredOutput,
            tools: $this->tools,
            callerMeters: $this->callerMeters,
            deadline: $deadline,
            providerOptions: $this->providerOptions,
        );
    }
}
