<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An external research agent, eval only. The endpoint returns {"text":"..."}.
 *
 * This does not call a provider on its own. Point --b4-endpoint at the agent
 * you want scored. It is never a CI gate.
 */
final readonly class B4Adapter
{
    public function __construct(
        private HttpClientInterface $http,
    ) {
    }

    public function answer(CorpusItem $item, string $endpoint): string
    {
        if ('' === trim($endpoint)) {
            throw new \RuntimeException('B4 needs --b4-endpoint that returns {"text":"..."}. Nothing was sent.');
        }

        $response = $this->http->request('POST', $endpoint, [
            'timeout' => 2100,
            'max_duration' => 2100,
            'json' => [
                'item_id' => $item->id,
                'question' => $item->question,
            ],
        ]);
        $status = $response->getStatusCode();
        $body = $response->toArray(false);
        if ($status >= 400) {
            throw new \RuntimeException('The external agent rejected the question.');
        }
        $text = $body['text'] ?? null;
        if (!\is_string($text) || '' === trim($text)) {
            throw new \RuntimeException('The external agent returned no answer text.');
        }

        return trim($text);
    }
}
