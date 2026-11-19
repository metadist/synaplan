<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

/**
 * The web-search wrap-up of the Messages gateway: once the search budget is
 * spent, one more turn without tools asks for the answer, and a turn that
 * still has no answer gets a short recovery text instead of nothing.
 */
final readonly class WebSearchWrapUp
{
    public const WRAP_UP_AFTER_SEARCH = 'You already have the web search results. Answer the user\'s question now from these results, as completely as the question needs, and cite the https URLs you used. Do not search again.';
    public const EMPTY_SEARCH_RECOVERY = 'I looked this up but could not turn the results into an answer. Please try again, or ask in a different way.';

    /**
     * @param list<array<string, mixed>> $ours
     */
    public static function countWebSearches(array $ours): int
    {
        $n = 0;
        foreach ($ours as $block) {
            if (WebSearchTool::NAME === ($block['name'] ?? '')) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * Groq (and some others) cite footnotes like `【3†L1】` and never write an
     * http URL. One more wrap-up turn, without tools, asks for the URL.
     *
     * @param array<string, mixed> $lastBody
     * @param array<string, mixed> $currentBody
     */
    public static function shouldWrapForMissingUrl(array $lastBody, int $webSearchRounds, array $currentBody): bool
    {
        if ($webSearchRounds < 1 || self::alreadyWrapped($currentBody)) {
            return false;
        }
        $content = $lastBody['content'] ?? [];
        if (!\is_array($content)) {
            return false;
        }
        $text = self::assistantText($content);

        return '' !== $text && !self::textHasHttpUrl($text);
    }

    /**
     * @param array{content: list<array<string, mixed>>} $turn
     * @param array<string, mixed>                       $currentBody
     */
    public static function shouldWrapForMissingUrlFromTurn(array $turn, int $webSearchRounds, array $currentBody): bool
    {
        return self::shouldWrapForMissingUrl(
            ['content' => $turn['content']],
            $webSearchRounds,
            $currentBody,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function alreadyWrapped(array $body): bool
    {
        $messages = $body['messages'] ?? [];
        if (!\is_array($messages) || [] === $messages) {
            return false;
        }
        $last = $messages[array_key_last($messages)];

        return \is_array($last)
            && 'user' === ($last['role'] ?? '')
            && self::WRAP_UP_AFTER_SEARCH === ($last['content'] ?? null);
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return array<string, mixed>
     */
    public static function forceWrapUp(array $requestBody): array
    {
        // Drop tools entirely. `tool_choice: none` still leaves the
        // declarations in the payload; Groq then 400s with "Tool choice is
        // none, but model called a tool" when the model ignores the hint.
        unset($requestBody['tools'], $requestBody['tool_choice']);
        $messages = $requestBody['messages'] ?? [];
        if (!\is_array($messages)) {
            $messages = [];
        }
        $messages[] = ['role' => 'user', 'content' => self::WRAP_UP_AFTER_SEARCH];
        $requestBody['messages'] = $messages;

        return $requestBody;
    }

    /**
     * Groq treats a request without tools as `tool_choice: none` and 400s when
     * the model still emits a function call. After wrap-up that is not a
     * user-facing failure — the search already ran.
     */
    public static function isToolChoiceConflict(mixed $body): bool
    {
        $message = '';
        if (\is_array($body)) {
            $error = \is_array($body['error'] ?? null) ? $body['error'] : [];
            $message = (string) ($error['message'] ?? $body['message'] ?? '');
        } elseif (\is_string($body)) {
            $message = $body;
        }
        $lower = strtolower($message);

        return str_contains($lower, 'tool choice is none')
            && str_contains($lower, 'called a tool');
    }

    public static function textHasHttpUrl(string $text): bool
    {
        $found = [];
        self::collectHttpUrls($text, $found);

        return [] !== $found;
    }

    /**
     * @param array<string, mixed>      $requestBody
     * @param array<string, mixed>|null $lastOkBody
     *
     * @return array<string, mixed>
     */
    public static function recoverWrapUpBody(array $requestBody, int $webSearchRounds, ?array $lastOkBody): array
    {
        $text = self::recoverWrapUpText($requestBody, $webSearchRounds);
        $body = \is_array($lastOkBody) ? $lastOkBody : [];
        if ('message' !== ($body['type'] ?? 'message')) {
            $body = [];
        }
        $id = $body['id'] ?? null;
        $model = $body['model'] ?? $requestBody['model'] ?? '';
        $usage = \is_array($body['usage'] ?? null) ? $body['usage'] : ['input_tokens' => 0, 'output_tokens' => 0];

        return [
            'id' => \is_string($id) && '' !== $id ? $id : 'msg_wrap_'.bin2hex(random_bytes(8)),
            'type' => 'message',
            'role' => 'assistant',
            'model' => \is_string($model) ? $model : '',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => $body['stop_sequence'] ?? null,
            'usage' => $usage,
        ];
    }

    /**
     * @param array<string, mixed> $requestBody
     */
    public static function recoverWrapUpText(array $requestBody, int $webSearchRounds): string
    {
        if ($webSearchRounds < 1) {
            return self::EMPTY_SEARCH_RECOVERY;
        }
        $urls = self::httpUrlsFromSearchResults($requestBody['messages'] ?? []);
        if ([] === $urls) {
            return self::EMPTY_SEARCH_RECOVERY;
        }

        return 'I looked this up. Sources: '.implode(' ', \array_slice($urls, 0, 5));
    }

    /**
     * URLs from executed `tool_result` blocks only — never the user prompt
     * or earlier assistant turns.
     *
     * @return list<string>
     */
    private static function httpUrlsFromSearchResults(mixed $messages): array
    {
        $found = [];
        if (!\is_array($messages)) {
            return [];
        }
        foreach ($messages as $message) {
            if (!\is_array($message) || 'user' !== ($message['role'] ?? '')) {
                continue;
            }
            $content = $message['content'] ?? null;
            if (!\is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (!\is_array($block) || 'tool_result' !== ($block['type'] ?? '')) {
                    continue;
                }
                self::collectHttpUrls($block['content'] ?? '', $found);
            }
        }

        return array_keys($found);
    }

    /**
     * @param array<string, true> $found
     */
    private static function collectHttpUrls(mixed $value, array &$found): void
    {
        if (\is_string($value)) {
            if (preg_match_all('#https?://[^\s<>"\']+#i', $value, $matches) < 1) {
                return;
            }
            foreach ($matches[0] as $raw) {
                $url = rtrim($raw, '.,);]>');
                $parts = parse_url($url);
                $host = \is_array($parts) ? ($parts['host'] ?? '') : '';
                if ('' === $host || !str_contains($host, '.')) {
                    continue;
                }
                $found[$url] = true;
            }

            return;
        }
        if (!\is_array($value)) {
            return;
        }
        foreach ($value as $item) {
            self::collectHttpUrls($item, $found);
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public static function withEmptySearchRecovery(array $body, int $webSearchRounds): array
    {
        if ($webSearchRounds < 1) {
            return $body;
        }
        $content = $body['content'] ?? [];
        if (!\is_array($content)) {
            $content = [];
        }
        if ('' !== self::assistantText($content)) {
            return $body;
        }
        $body['content'] = [['type' => 'text', 'text' => self::EMPTY_SEARCH_RECOVERY]];
        $body['stop_reason'] = 'end_turn';

        return $body;
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    public static function assistantText(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if ('text' === ($block['type'] ?? '') && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }

        return trim(implode('', $parts));
    }
}
