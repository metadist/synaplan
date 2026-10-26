<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

use App\AI\Messages\DesktopTurnOptions;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One question through POST /v1/messages, the gateway loop after Phase B.
 *
 * The key is an evaluation key. A knowledge item also sends the folder header.
 * Without a model only a paired desktop key resolves one; any other key needs
 * --gateway-model.
 *
 * With web search the request declares Anthropic's web_search server tool,
 * as a desktop client does; the gateway answers it with its own search when
 * one is configured. Citations on a text block become Markdown links.
 */
final readonly class B1PlusAdapter
{
    private const MAX_TOKENS = 4096;
    private const TIMEOUT_SECONDS = 600;
    private const WEB_SEARCH_TOOL = ['type' => 'web_search_20250305', 'name' => 'web_search'];

    public function __construct(
        private HttpClientInterface $http,
    ) {
    }

    public function answer(CorpusItem $item, string $gatewayUrl, string $apiKey, ?string $folder, ?string $model = null, bool $webSearch = false): string
    {
        if ('' === trim($gatewayUrl) || '' === trim($apiKey)) {
            throw new \RuntimeException('B1+ needs --gateway-url and an API key (--api-key or RESEARCH_EVAL_API_KEY). Nothing was sent.');
        }
        if ($item->knowledge && (null === $folder || '' === trim($folder))) {
            throw new \RuntimeException('This item reads a knowledge folder. Pass --folder. Nothing was sent.');
        }

        $headers = [
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ];
        if (null !== $folder && '' !== trim($folder)) {
            $headers[DesktopTurnOptions::HEADER_RAG_GROUP_KEY] = $folder;
        }
        $body = [
            'max_tokens' => self::MAX_TOKENS,
            'messages' => [
                ['role' => 'user', 'content' => $item->question],
            ],
        ];
        if (null !== $model && '' !== trim($model)) {
            $body['model'] = trim($model);
        }
        if ($webSearch) {
            $body['tools'] = [self::WEB_SEARCH_TOOL];
        }

        $response = $this->http->request('POST', rtrim($gatewayUrl, '/').'/v1/messages', [
            'headers' => $headers,
            'json' => $body,
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
        ]);
        $status = $response->getStatusCode();
        try {
            $decoded = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new \RuntimeException('The gateway did not answer with JSON (status '.$status.'). Check --gateway-url.');
        }
        if ($status >= 400) {
            $error = $decoded['error'] ?? null;
            $message = \is_array($error) && \is_string($error['message'] ?? null)
                ? $error['message']
                : 'The gateway rejected the question.';
            throw new \RuntimeException($message);
        }

        $text = '';
        foreach ($decoded['content'] ?? [] as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? '') && isset($block['text'])) {
                $text .= (string) $block['text'].$this->citationLinks($block['citations'] ?? null);
            }
        }
        if ('' === trim($text)) {
            throw new \RuntimeException('The gateway returned no answer text.');
        }

        return trim($text);
    }

    private function citationLinks(mixed $citations): string
    {
        if (!\is_array($citations)) {
            return '';
        }
        $links = [];
        foreach ($citations as $citation) {
            $url = \is_array($citation) ? ($citation['url'] ?? null) : null;
            if (\is_string($url) && str_starts_with($url, 'http')) {
                $links[$url] = '[source]('.$url.')';
            }
        }

        return [] === $links ? '' : ' ('.implode(' ', $links).')';
    }
}
