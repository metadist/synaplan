<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\AI\Tool\OpenAiToolShapes;

/**
 * Parsed POST /v1/chat/completions body.
 *
 * Messages stay intact (assistant tool_calls, role: tool, array content).
 * tools / tool_choice are validated through {@see OpenAiToolShapes}.
 */
final readonly class OpenAiChatCompletionRequest
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     */
    public function __construct(
        public array $messages,
        public ?string $model,
        public ?float $temperature,
        public ?int $maxTokens,
        public bool $stream,
        public array $tools,
        public mixed $toolChoice,
        public ?bool $parallelToolCalls,
        public bool $includeUsage,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $messages = $body['messages'] ?? null;
        if (!is_array($messages) || [] === $messages) {
            throw new OpenAiChatCompletionRequestException('messages is required and must be a non-empty array', 'missing_messages');
        }

        $normalizedMessages = [];
        foreach ($messages as $i => $message) {
            if (!is_array($message)) {
                throw new OpenAiChatCompletionRequestException(sprintf('messages[%s] must be an object', (string) $i), 'invalid_messages');
            }
            $normalizedMessages[] = $message;
        }

        $tools = [];
        if (array_key_exists('tools', $body)) {
            try {
                $tools = OpenAiToolShapes::validateTools($body['tools']);
            } catch (\InvalidArgumentException $e) {
                throw new OpenAiChatCompletionRequestException($e->getMessage(), 'invalid_tools');
            }
        }

        $toolChoice = null;
        if (array_key_exists('tool_choice', $body)) {
            try {
                $toolChoice = OpenAiToolShapes::validateToolChoice($body['tool_choice']);
            } catch (\InvalidArgumentException $e) {
                throw new OpenAiChatCompletionRequestException($e->getMessage(), 'invalid_tool_choice');
            }
        }

        $parallelToolCalls = null;
        if (array_key_exists('parallel_tool_calls', $body)) {
            $rawParallel = $body['parallel_tool_calls'];
            if (!is_bool($rawParallel) && 0 !== $rawParallel && 1 !== $rawParallel) {
                throw new OpenAiChatCompletionRequestException('parallel_tool_calls must be a boolean', 'invalid_parallel_tool_calls');
            }
            $parallelToolCalls = (bool) $rawParallel;
        }

        $includeUsage = false;
        $streamOptions = $body['stream_options'] ?? null;
        if (is_array($streamOptions)) {
            $includeUsage = (bool) ($streamOptions['include_usage'] ?? false);
        }

        $model = $body['model'] ?? null;
        if (null !== $model && !is_string($model)) {
            $model = is_scalar($model) ? (string) $model : null;
        }

        return new self(
            messages: $normalizedMessages,
            model: is_string($model) && '' !== $model ? $model : null,
            temperature: isset($body['temperature']) ? (float) $body['temperature'] : null,
            maxTokens: isset($body['max_tokens']) ? (int) $body['max_tokens'] : null,
            stream: (bool) ($body['stream'] ?? false),
            tools: $tools,
            toolChoice: $toolChoice,
            parallelToolCalls: $parallelToolCalls,
            includeUsage: $includeUsage,
        );
    }

    /**
     * Whether the client asked the model to consider tools.
     *
     * A non-empty tools[] always counts. tool_choice other than none also
     * counts (Decision: tools / tool_choice other than none).
     */
    public function requestsTools(): bool
    {
        if ([] !== $this->tools) {
            return true;
        }

        if (null === $this->toolChoice || 'none' === $this->toolChoice) {
            return false;
        }

        return true;
    }

    /**
     * Options forwarded to the chat provider (additive contract).
     *
     * @return array<string, mixed>
     */
    public function providerToolOptions(): array
    {
        $options = [];
        if ([] !== $this->tools) {
            $options['tools'] = $this->tools;
        }
        if (null !== $this->toolChoice) {
            $options['tool_choice'] = $this->toolChoice;
        }
        if (null !== $this->parallelToolCalls) {
            $options['parallel_tool_calls'] = $this->parallelToolCalls;
        }

        return $options;
    }
}
