<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

use App\AI\Messages\DesktopTurnOptions;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One question through POST /v1/messages, the gateway loop after Phase B.
 *
 * The key is an evaluation key. A knowledge item also sends the folder header.
 */
final readonly class B1PlusAdapter
{
    public function __construct(
        private HttpClientInterface $http,
    ) {
    }

    public function answer(CorpusItem $item, string $gatewayUrl, string $apiKey, ?string $folder): string
    {
        if ('' === trim($gatewayUrl) || '' === trim($apiKey)) {
            throw new \RuntimeException('B1+ needs --gateway-url and --api-key. Nothing was sent.');
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

        $response = $this->http->request('POST', rtrim($gatewayUrl, '/').'/v1/messages', [
            'headers' => $headers,
            'json' => [
                'max_tokens' => 4096,
                'messages' => [
                    ['role' => 'user', 'content' => $item->question],
                ],
            ],
        ]);
        $status = $response->getStatusCode();
        $body = $response->toArray(false);
        if ($status >= 400) {
            $error = $body['error'] ?? null;
            $message = \is_array($error) && \is_string($error['message'] ?? null)
                ? $error['message']
                : 'The gateway rejected the question.';
            throw new \RuntimeException($message);
        }

        $text = '';
        foreach ($body['content'] ?? [] as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? '') && isset($block['text'])) {
                $text .= (string) $block['text'];
            }
        }
        if ('' === trim($text)) {
            throw new \RuntimeException('The gateway returned no answer text.');
        }

        return trim($text);
    }
}
