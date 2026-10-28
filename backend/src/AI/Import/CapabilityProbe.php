<?php

declare(strict_types=1);

namespace App\AI\Import;

use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallContext;
use App\AI\Pipeline\AiCallOptions;
use App\AI\Pipeline\AiCallPayload;
use App\AI\Pipeline\AiCallPipeline;
use App\AI\Pipeline\AiCapability;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends two tiny requests to an OpenAI-compatible endpoint to see what a model
 * can actually do: a one-token chat completion and a one-input embedding. Each
 * is best-effort with a short timeout — the point is a quick "does it answer",
 * not a benchmark. Opt-in only (it spends a little credit) and never used for
 * native Ollama discovery, which reports capabilities differently.
 */
final readonly class CapabilityProbe
{
    private const TIMEOUT_SECONDS = 8.0;

    private AiCallPipeline $pipeline;

    public function __construct(
        private HttpClientInterface $httpClient,
        ?AiCallPipeline $pipeline = null,
    ) {
        $this->pipeline = $pipeline ?? new AiCallPipeline();
    }

    /**
     * @param array{base_url: string, api_key: string, headers: array<string, string>} $endpoint
     */
    public function probe(array $endpoint, string $providerId): ProbeResult
    {
        $base = rtrim($endpoint['base_url'], '/');
        $headers = $endpoint['headers'];
        if ('' !== $endpoint['api_key']) {
            $headers['Authorization'] = 'Bearer '.$endpoint['api_key'];
        }

        $started = hrtime(true);
        $chat = $this->tryCall(AiCapability::Chat, $providerId, $base.'/chat/completions', $headers, [
            'model' => $providerId,
            'messages' => [['role' => 'user', 'content' => 'ping']],
            'max_tokens' => 1,
        ]);
        $embeddings = $this->tryCall(AiCapability::Embed, $providerId, $base.'/embeddings', $headers, [
            'model' => $providerId,
            'input' => 'ping',
        ]);
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);

        return new ProbeResult($chat, $embeddings, $ms);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     *
     * @return ProbeResult::OK|ProbeResult::FAIL
     */
    private function tryCall(AiCapability $capability, string $providerId, string $url, array $headers, array $body): string
    {
        $call = new AiCall(
            capability: $capability,
            provider: 'openaicompatible',
            model: $providerId,
            context: AiCallContext::probe(),
            payload: new AiCallPayload(passthroughBody: $body),
            options: AiCallOptions::fromArray(['skip_circuit_breaker' => true, 'skip_model_health' => true]),
        );

        try {
            /* @var string */
            return $this->pipeline->run(
                $call,
                function () use ($url, $headers, $body): string {
                    $response = $this->httpClient->request('POST', $url, [
                        'timeout' => self::TIMEOUT_SECONDS,
                        'headers' => $headers + ['Content-Type' => 'application/json'],
                        'json' => $body,
                    ]);

                    return $response->getStatusCode() < 400 ? ProbeResult::OK : ProbeResult::FAIL;
                },
            )->value;
        } catch (\Throwable) {
            return ProbeResult::FAIL;
        }
    }
}
