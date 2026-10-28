<?php

declare(strict_types=1);

namespace App\AI\Messages;

/**
 * Extracts the concatenated text blocks and upstream rate-limit headers from
 * an Anthropic Messages response body, and appends the one-time budget notice
 * when due.
 */
final class MessagesResponseText
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $responseBody
     */
    public static function extract(array $responseBody): string
    {
        $responseText = '';
        if (isset($responseBody['content']) && \is_array($responseBody['content'])) {
            foreach ($responseBody['content'] as $block) {
                if (\is_array($block) && 'text' === ($block['type'] ?? '') && isset($block['text'])) {
                    $responseText .= (string) $block['text'];
                }
            }
        }

        return $responseText;
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return array<string, string>
     */
    public static function extractUpstreamRateLimitHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $lower = strtolower($name);
            if (str_starts_with($lower, 'anthropic-ratelimit-') || 'request-id' === $lower || 'retry-after' === $lower) {
                $out[$name] = $values[0] ?? '';
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public static function appendBudgetNotice(array $body, string $noticeText): array
    {
        $content = $body['content'] ?? [];
        if (!\is_array($content)) {
            $content = [];
        }
        $content[] = [
            'type' => 'text',
            'text' => $noticeText,
        ];
        $body['content'] = $content;

        return $body;
    }
}
