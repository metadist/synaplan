<?php

namespace App\AI\Provider\OpenAI;

/**
 * Translates between the Chat Completions shapes the rest of Synaplan speaks
 * and the Responses API: the system message into `instructions`, the
 * conversation into input items (tool history as function calls and their
 * outputs), the answer's function calls into OpenAI tool calls, and its
 * usage into the internal token counts.
 */
final class OpenAIResponsesFormat
{
    /**
     * Extract the first system message content from a messages array.
     */
    public static function extractSystemMessage(array $messages): ?string
    {
        foreach ($messages as $message) {
            if ('system' === ($message['role'] ?? '')) {
                return \is_string($message['content']) ? $message['content'] : null;
            }
        }

        return null;
    }

    /**
     * Remove all system messages from a messages array.
     */
    public static function removeSystemMessages(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            static fn (array $msg) => 'system' !== ($msg['role'] ?? '')
        ));
    }

    /**
     * Convert Chat Completions message format to Responses API input items.
     *
     * Chat Completions uses: {type: "text", text: "..."} and {type: "image_url", image_url: {url: "..."}}
     * Responses API uses:    {type: "input_text"/"output_text", text: "..."} and {type: "input_image", image_url: "..."}
     * Tool history becomes `function_call` / `function_call_output` items.
     */
    public static function convertToResponsesFormat(array $messages): array
    {
        $items = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';

            if ('tool' === $role) {
                $output = $message['content'] ?? '';
                if (!is_string($output)) {
                    $output = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
                }
                $items[] = [
                    'type' => 'function_call_output',
                    'call_id' => (string) ($message['tool_call_id'] ?? ''),
                    'output' => $output,
                ];
                continue;
            }

            $isAssistant = 'assistant' === $role;
            $textType = $isAssistant ? 'output_text' : 'input_text';
            $toolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
            $contentParts = self::convertResponsesContentParts($message['content'] ?? '', $textType, [] !== $toolCalls);

            if ([] !== $contentParts) {
                $items[] = [
                    'role' => $role,
                    'content' => $contentParts,
                ];
            }

            foreach ($toolCalls as $call) {
                if (!is_array($call)) {
                    continue;
                }
                $fn = is_array($call['function'] ?? null) ? $call['function'] : [];
                $args = $fn['arguments'] ?? '{}';
                if (!is_string($args) || '' === $args) {
                    $args = '{}';
                }
                $items[] = [
                    'type' => 'function_call',
                    'call_id' => (string) ($call['id'] ?? ('call_'.bin2hex(random_bytes(6)))),
                    'name' => (string) ($fn['name'] ?? 'tool'),
                    'arguments' => $args,
                ];
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function convertResponsesContentParts(mixed $content, string $textType, bool $hasToolCalls): array
    {
        if (!is_array($content)) {
            $text = is_string($content) ? $content : '';
            if ('' === $text && $hasToolCalls) {
                return [];
            }

            return [[
                'type' => $textType,
                'text' => $text,
            ]];
        }

        $converted = [];
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }
            $type = $part['type'] ?? '';
            if ('text' === $type) {
                $converted[] = [
                    'type' => $textType,
                    'text' => $part['text'] ?? '',
                ];
            } elseif ('image_url' === $type) {
                $url = $part['image_url']['url'] ?? ($part['image_url'] ?? '');
                $converted[] = [
                    'type' => 'input_image',
                    'image_url' => $url,
                ];
            } else {
                $converted[] = $part;
            }
        }

        return $converted;
    }

    /**
     * @return list<array{id: string, type: 'function', function: array{name: string, arguments: string}}>
     */
    public static function extractResponsesToolCalls(array $responseArray): array
    {
        $calls = [];
        foreach ($responseArray['output'] ?? [] as $item) {
            if (!is_array($item) || 'function_call' !== ($item['type'] ?? '')) {
                continue;
            }
            $args = $item['arguments'] ?? '{}';
            if (!is_string($args) || '' === $args) {
                $args = '{}';
            }
            $calls[] = [
                'id' => (string) ($item['call_id'] ?? $item['id'] ?? ('call_'.bin2hex(random_bytes(6)))),
                'type' => 'function',
                'function' => [
                    'name' => (string) ($item['name'] ?? 'tool'),
                    'arguments' => $args,
                ],
            ];
        }

        return $calls;
    }

    public static function responsesOutputHasFunctionCall(mixed $output): bool
    {
        if (!is_array($output)) {
            return false;
        }
        foreach ($output as $item) {
            if (is_array($item) && 'function_call' === ($item['type'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize Responses API usage into the internal token format.
     *
     * Responses API uses input_tokens/output_tokens vs Chat Completions prompt_tokens/completion_tokens.
     */
    public static function normalizeResponsesUsage(array $responseData): array
    {
        $usage = $responseData['usage'] ?? [];

        return [
            'prompt_tokens' => $usage['input_tokens'] ?? 0,
            'completion_tokens' => $usage['output_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0,
            'cached_tokens' => $usage['input_tokens_details']['cached_tokens'] ?? 0,
            // OpenAI reports cache writes as `cache_write_tokens`; the internal
            // key keeps Anthropic's name. Reading the wrong key silently billed
            // written tokens as ordinary input (1.0x instead of 1.25x).
            'cache_creation_tokens' => $usage['input_tokens_details']['cache_write_tokens'] ?? 0,
        ];
    }

    /**
     * Reduce input to just the last user message for stateful conversations.
     *
     * When previous_response_id is used, the API already has the conversation context.
     * Only the new user message needs to be sent.
     */
    public static function reduceToLastUserMessage(array $input): array
    {
        foreach (array_reverse($input) as $msg) {
            if ('user' === ($msg['role'] ?? '')) {
                return [$msg];
            }
        }

        return $input;
    }
}
