<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An external research agent, eval only. The endpoint returns {"text":"..."}.
 *
 * This does not call a provider on its own. Point --b4-endpoint at the agent
 * you want scored. It is never a CI gate.
 */
final readonly class B4Adapter
{
    /** Deep-research agents take up to half an hour per question. */
    private const TIMEOUT_SECONDS = 2100;

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
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'json' => [
                'item_id' => $item->id,
                'question' => $item->question,
            ],
        ]);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException('The external agent rejected the question (status '.$status.').');
        }
        try {
            $body = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new \RuntimeException('The external agent did not answer with JSON. It must return {"text":"..."}.');
        }
        $text = $body['text'] ?? null;
        if (!\is_string($text) || '' === trim($text)) {
            throw new \RuntimeException('The external agent returned no answer text.');
        }

        return trim($text);
    }
}
