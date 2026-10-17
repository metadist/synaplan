<?php

namespace App\AI\Provider\Google;

use App\AI\Tool\OpenAiToolShapes;

/**
 * Translates the OpenAI-style shapes the rest of Synaplan speaks into
 * Gemini's and back: a conversation into strictly alternating user and model
 * contents (function calls, function responses, inline images, blank turns
 * dropped), the tool list and tool choice into function declarations and a
 * tool config, and a Gemini function call into an OpenAI tool call.
 */
final class GoogleMessageFormat
{
    /**
     * Convert OpenAI-style messages to Gemini contents.
     *
     * @param array<int, array<string, mixed>> $messages
     *
     * @return list<array{role: string, parts: list<array<string, mixed>>}>
     */
    public static function contents(array $messages): array
    {
        $contents = [];

        $toolNamesById = [];

        foreach ($messages as $message) {
            $rawRole = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            if ('tool' === $rawRole) {
                $contents[] = self::toolResult($message, $content, $toolNamesById);
                continue;
            }

            $role = 'assistant' === $rawRole ? 'model' : 'user';
            $parts = [];
            $toolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
            foreach ($toolCalls as $call) {
                if (!is_array($call)) {
                    continue;
                }
                $fn = is_array($call['function'] ?? null) ? $call['function'] : [];
                $name = (string) ($fn['name'] ?? 'tool');
                $id = (string) ($call['id'] ?? '');
                if ('' !== $id) {
                    $toolNamesById[$id] = $name;
                }
                $args = $fn['arguments'] ?? [];
                if (is_string($args)) {
                    $decoded = json_decode($args, true);
                    $args = is_array($decoded) ? $decoded : [];
                }
                if (!is_array($args)) {
                    $args = [];
                }
                $parts[] = [
                    'functionCall' => [
                        'name' => $name,
                        'args' => $args,
                    ],
                ];
            }

            $parts = array_merge($parts, self::contentParts($content));

            // Drop turns that produced no usable parts — an empty `parts` array
            // is itself a 400 on Gemini.
            if ([] === $parts) {
                continue;
            }

            $contents[] = [
                'role' => $role,
                'parts' => $parts,
            ];
        }

        // Gemini requires strictly alternating user/model turns that begin with
        // a `user` turn. Dropping blank rows above can leave two same-role turns
        // adjacent, and the loaded history window can begin on an assistant/OUT
        // row — both are 400s. Mirror the AnthropicProvider normalisation.
        $contents = self::mergeConsecutive($contents);

        return self::dropLeadingModel($contents);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function withTools(array $payload, array $options): array
    {
        if (isset($options['tools']) && is_array($options['tools']) && [] !== $options['tools']) {
            $payload['tools'] = [[
                'functionDeclarations' => OpenAiToolShapes::toGeminiDeclarations($options['tools']),
            ]];
        }
        if (array_key_exists('tool_choice', $options)) {
            $config = OpenAiToolShapes::toGeminiToolConfig($options['tool_choice']);
            if (null !== $config) {
                $payload['toolConfig'] = $config;
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $functionCall
     *
     * @return array{id: string, type: 'function', function: array{name: string, arguments: string}}
     */
    public static function toolCall(array $functionCall): array
    {
        $args = $functionCall['args'] ?? $functionCall['arguments'] ?? [];
        if (is_string($args)) {
            $arguments = '' !== $args ? $args : '{}';
        } else {
            $arguments = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        return [
            'id' => 'call_'.bin2hex(random_bytes(6)),
            'type' => 'function',
            'function' => [
                'name' => (string) ($functionCall['name'] ?? 'tool'),
                'arguments' => $arguments,
            ],
        ];
    }

    /**
     * A tool result goes back as a user turn carrying the function response,
     * named after the call it answers.
     *
     * @param array<string, mixed>  $message
     * @param array<string, string> $toolNamesById
     *
     * @return array{role: string, parts: list<array<string, mixed>>}
     */
    private static function toolResult(array $message, mixed $content, array $toolNamesById): array
    {
        $toolCallId = (string) ($message['tool_call_id'] ?? '');
        $name = is_string($message['name'] ?? null) && '' !== $message['name']
            ? (string) $message['name']
            : ($toolNamesById[$toolCallId] ?? ($toolCallId ?: 'tool'));
        $result = $content;
        if (is_array($result)) {
            $result = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return [
            'role' => 'user',
            'parts' => [[
                'functionResponse' => [
                    'name' => $name,
                    'response' => ['content' => (string) $result],
                ],
            ]],
        ];
    }

    /**
     * The text and inline images of a message.
     *
     * @return list<array<string, mixed>>
     */
    private static function contentParts(mixed $content): array
    {
        $parts = [];
        if (is_string($content)) {
            // Skip blank turns: Gemini rejects empty text parts with a 400
            // ("empty text parameter"), so a blank history row (WhatsApp
            // media-only / placeholder / error rows) would break the turn.
            if ('' !== trim($content)) {
                $parts[] = ['text' => $content];
            }
        } elseif (is_array($content)) {
            foreach ($content as $part) {
                if (!is_array($part)) {
                    continue;
                }

                $type = $part['type'] ?? '';
                if ('text' === $type) {
                    $text = (string) ($part['text'] ?? '');
                    if ('' !== trim($text)) {
                        $parts[] = ['text' => $text];
                    }
                } elseif ('image_url' === $type) {
                    $imageUrl = $part['image_url']['url'] ?? $part['image_url'];
                    if (is_string($imageUrl) && str_starts_with($imageUrl, 'data:image')) {
                        // Base64 image
                        list($mime, $data) = explode(';', $imageUrl);
                        list(, $data) = explode(',', $data);
                        $parts[] = [
                            'inline_data' => [
                                'mime_type' => str_replace('data:', '', $mime),
                                'data' => $data,
                            ],
                        ];
                    }
                }
            }
        }

        return $parts;
    }

    /**
     * Merge consecutive same-role Gemini contents by concatenating their parts.
     *
     * @param list<array{role: string, parts: list<array<string, mixed>>}> $contents
     *
     * @return list<array{role: string, parts: list<array<string, mixed>>}>
     */
    private static function mergeConsecutive(array $contents): array
    {
        if (count($contents) < 2) {
            return $contents;
        }

        $merged = [$contents[0]];

        for ($i = 1, $count = count($contents); $i < $count; ++$i) {
            $last = &$merged[count($merged) - 1];

            if ($contents[$i]['role'] === $last['role']) {
                $last['parts'] = array_merge($last['parts'], $contents[$i]['parts']);
            } else {
                $merged[] = $contents[$i];
            }

            unset($last);
        }

        return $merged;
    }

    /**
     * Drop leading `model` turns so the conversation starts with a `user` turn,
     * as Gemini requires.
     *
     * @param list<array{role: string, parts: list<array<string, mixed>>}> $contents
     *
     * @return list<array{role: string, parts: list<array<string, mixed>>}>
     */
    private static function dropLeadingModel(array $contents): array
    {
        while ([] !== $contents && 'user' !== $contents[0]['role']) {
            array_shift($contents);
        }

        return $contents;
    }
}
