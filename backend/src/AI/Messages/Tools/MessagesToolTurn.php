<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\AI\Messages\MessagesEventEmitter;
use App\AI\Messages\MessagesUsage;
use App\Service\Runtime\RuntimeProfile;

/**
 * Messages wire helpers shared by the complete and the stream adapter: the
 * tool turn appended for the next call, the summed usage, the held stop.
 */
final readonly class MessagesToolTurn
{
    /**
     * @param list<array<string, mixed>> $content
     *
     * @return list<array<string, mixed>>
     */
    private static function dropEmptyThinking(array $content): array
    {
        $out = [];
        foreach ($content as $block) {
            if ('thinking' === ($block['type'] ?? '')) {
                $text = (string) ($block['thinking'] ?? $block['text'] ?? '');
                if ('' === trim($text)) {
                    continue;
                }
                $block['thinking'] = $text;
            }
            $out[] = $block;
        }

        return $out;
    }

    /**
     * Drop unpaired Anthropic server-tool uses from every assistant turn
     * before the next upstream call.
     *
     * @param array<string, mixed> $requestBody
     *
     * @return array<string, mixed>
     */
    public static function sanitizeRequestMessages(array $requestBody): array
    {
        $messages = $requestBody['messages'] ?? [];
        if (!\is_array($messages)) {
            return $requestBody;
        }
        $requestBody['messages'] = ServerToolReplay::sanitizeMessages($messages);

        return $requestBody;
    }

    /**
     * @param array<string, mixed>       $requestBody
     * @param list<array<string, mixed>> $assistantContent
     * @param list<array<string, mixed>> $toolResults
     *
     * @return array<string, mixed>
     */
    public static function appendToolTurn(array $requestBody, array $assistantContent, array $toolResults): array
    {
        $messages = $requestBody['messages'] ?? [];
        if (!\is_array($messages)) {
            $messages = [];
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => ServerToolReplay::sanitizeAssistantContent(
                self::dropEmptyThinking($assistantContent),
            ),
        ];
        $messages[] = [
            'role' => 'user',
            'content' => $toolResults,
        ];

        $requestBody['messages'] = $messages;

        return $requestBody;
    }

    /**
     * @param array{held_stop?: array{event: string, data: array<string, mixed>}|null} $turn
     */
    public static function finishStream(MessagesEventEmitter $emitter, array $turn): void
    {
        $held = $turn['held_stop'] ?? null;
        if (\is_array($held)) {
            $emitter->relay((string) $held['event'], $held['data'], isFinalTurn: true);
        }
        $emitter->ensureClosed();
    }

    /**
     * @param array<string, mixed> $translatorContext
     */
    public static function assistantFrom(array $translatorContext): ?RuntimeProfile
    {
        $profile = $translatorContext['runtime_profile'] ?? null;

        return $profile instanceof RuntimeProfile ? $profile : null;
    }

    public static function sumUsage(MessagesUsage $a, MessagesUsage $b): MessagesUsage
    {
        return new MessagesUsage(
            inputTokens: $a->inputTokens + $b->inputTokens,
            outputTokens: $a->outputTokens + $b->outputTokens,
            cacheCreationTokens: $a->cacheCreationTokens + $b->cacheCreationTokens,
            cacheCreation1hTokens: $a->cacheCreation1hTokens + $b->cacheCreation1hTokens,
            cacheReadTokens: $a->cacheReadTokens + $b->cacheReadTokens,
            stopReason: $b->stopReason ?? $a->stopReason,
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public static function withUsage(array $body, MessagesUsage $usage): array
    {
        $body['usage'] = [
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_creation_input_tokens' => $usage->cacheCreationTokens,
            'cache_read_input_tokens' => $usage->cacheReadTokens,
        ];

        // Mirror Anthropic's TTL breakdown so a client inspecting the aggregated
        // multi-turn usage (e.g. for its own cost estimate) sees the same shape
        // it would from a single-turn response. Clamp defensively: summing
        // per-turn usage (sumUsage()) should never let the 1h count exceed the
        // total, but a negative ephemeral_5m_input_tokens would be an invalid
        // token count and could confuse a client parsing this breakdown.
        if ($usage->cacheCreation1hTokens > 0) {
            $cacheCreation1h = min($usage->cacheCreation1hTokens, $usage->cacheCreationTokens);
            $body['usage']['cache_creation'] = [
                'ephemeral_5m_input_tokens' => $usage->cacheCreationTokens - $cacheCreation1h,
                'ephemeral_1h_input_tokens' => $cacheCreation1h,
            ];
        }

        return $body;
    }
}
