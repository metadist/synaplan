<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Messages\MessagesGateway;
use App\AI\Messages\MessagesUsage;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\Entity\User;
use App\Service\MessagesGateway\ApiSessionSummaryService;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anthropic Messages API-compatible gateway (Claude Code & agent CLIs).
 *
 * Distinct from Synaplan's native chat SSE at /api/v1/messages/stream.
 * Errors use Anthropic's envelope: {"type":"error","error":{"type","message"}}.
 */
#[OA\Tag(name: 'Anthropic Compatible', description: 'Anthropic Messages API-compatible gateway for Claude Code')]
final class MessagesApiController extends AbstractController
{
    public function __construct(
        private readonly MessagesGateway $gateway,
        private readonly MessagesGatewayConfig $config,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/v1/messages', name: 'anthropic_messages', methods: ['POST'])]
    #[OA\Post(
        path: '/v1/messages',
        summary: 'Create a message (Anthropic Messages API-compatible)',
        description: 'Accepts the Anthropic Messages API request shape. Claude Code posts to /v1/messages?beta=true — match on path. Supports streaming via SSE when stream=true.',
        security: [['ApiKey' => []], ['Bearer' => []]],
        tags: ['Anthropic Compatible']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['model', 'messages', 'max_tokens'],
            properties: [
                new OA\Property(property: 'model', type: 'string', example: 'claude-sonnet-4-6'),
                new OA\Property(
                    property: 'messages',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'role', type: 'string', enum: ['user', 'assistant']),
                            new OA\Property(property: 'content', description: 'String or content-block array'),
                        ]
                    )
                ),
                new OA\Property(property: 'max_tokens', type: 'integer', example: 4096),
                new OA\Property(property: 'stream', type: 'boolean', example: false),
                new OA\Property(property: 'system', description: 'String or content-block array'),
                new OA\Property(property: 'tools', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'temperature', type: 'number'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Message response or SSE stream')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Gateway disabled')]
    #[OA\Response(response: 404, description: 'Model not found')]
    #[OA\Response(response: 429, description: 'Rate limit or budget exceeded')]
    public function messages(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->anthropicError('Authentication required', 'authentication_error', 401);
        }

        $prepared = $this->gateway->prepare($request, $user);
        if (false === $prepared['ok']) {
            return $this->anthropicError(
                $prepared['message'],
                $prepared['error_type'],
                $prepared['status'],
                $prepared['headers'] ?? [],
            );
        }

        if ($prepared['stream']) {
            return $this->streamResponse($prepared, $user);
        }

        $result = $this->gateway->executeComplete($prepared, $user);
        if (false === $result['ok']) {
            return $this->anthropicError(
                $result['message'],
                $result['error_type'],
                $result['status'],
                $result['headers'] ?? [],
            );
        }

        // Upstream error bodies are forwarded unmodified (Claude Code recovery
        // matches on error wording). Only wrap when the body is not already
        // Anthropic-shaped.
        $status = $result['status'];
        $body = $result['body'];
        if ($status >= 400 && \is_string($body)) {
            $response = new Response($body, $status, ['Content-Type' => 'application/json']);
        } elseif ($status >= 400 && \is_array($body) && isset($body['type']) && 'error' === $body['type']) {
            $response = new JsonResponse($body, $status);
        } elseif ($status >= 400 && \is_array($body)) {
            $response = new JsonResponse($body, $status);
        } else {
            $response = new JsonResponse($body, $status);
        }

        foreach ($result['headers'] as $name => $value) {
            $response->headers->set($name, $value);
        }

        $this->addWebSearchHeader($response, $prepared);
        $this->addVisionHeader($response, $prepared);
        if (!empty($prepared['debug']) && !empty($prepared['context_hash'])) {
            $response->headers->set('x-synaplan-context-hash', (string) $prepared['context_hash']);
        }

        return $response;
    }

    /**
     * Tell the caller how its `web_search` declaration was handled — `synaplan`
     * (we ran the search), `passthrough` (forwarded for the upstream to run) or
     * `off`. Without this, a model answering "I cannot search the web" is
     * indistinguishable from a misconfigured gateway.
     *
     * @param array<string, mixed> $prepared
     */
    private function addWebSearchHeader(Response $response, array $prepared): void
    {
        $mode = $prepared['web_search'] ?? null;
        if (\is_string($mode) && '' !== $mode && GatewayToolCatalog::WEB_SEARCH_NONE !== $mode) {
            $response->headers->set('x-synaplan-web-search', $mode);
        }
    }

    /**
     * Tell the caller how image turns were handled — `synaplan` (rewrote onto
     * a Synaplan vision model) or `passthrough` (left on the wire).
     *
     * @param array<string, mixed> $prepared
     */
    private function addVisionHeader(Response $response, array $prepared): void
    {
        $mode = $prepared['vision'] ?? null;
        if (\is_string($mode) && '' !== $mode && GatewayToolCatalog::VISION_NONE !== $mode) {
            $response->headers->set('x-synaplan-vision', $mode);
        }
    }

    #[Route('/v1/messages/count_tokens', name: 'anthropic_count_tokens', methods: ['POST'])]
    #[OA\Post(
        path: '/v1/messages/count_tokens',
        summary: 'Count tokens (Anthropic-compatible, optional)',
        description: 'Proxied to Anthropic for Anthropic-resolved models. Returns 404 for non-Anthropic providers — Claude Code estimates locally when this endpoint is absent or 404s.',
        security: [['ApiKey' => []], ['Bearer' => []]],
        tags: ['Anthropic Compatible']
    )]
    #[OA\Response(response: 200, description: 'Token count')]
    #[OA\Response(response: 404, description: 'Not available for this model/provider')]
    public function countTokens(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->anthropicError('Authentication required', 'authentication_error', 401);
        }

        if (!$this->config->isEnabled($user->getId())) {
            return $this->anthropicError('Messages gateway is disabled on this Synaplan instance.', 'permission_error', 403);
        }

        $prepared = $this->gateway->prepare(
            // Reuse prepare's validation/resolve by forcing stream=false and
            // injecting a synthetic max_tokens if missing (count_tokens doesn't require it).
            $this->normalizeCountTokensRequest($request),
            $user,
        );

        // prepare requires max_tokens; count_tokens clients may omit it — handled above.
        // For count_tokens we only need model resolution + credentials; rate-limit still applies.
        if (false === $prepared['ok']) {
            // Soften "max_tokens required" — shouldn't happen after normalize.
            return $this->anthropicError(
                $prepared['message'],
                $prepared['error_type'],
                $prepared['status'],
                $prepared['headers'] ?? [],
            );
        }

        if ('anthropic' !== $prepared['resolved']['provider']) {
            return $this->anthropicError(
                'count_tokens is only available for Anthropic models. Claude Code will estimate locally.',
                'not_found_error',
                404,
            );
        }

        try {
            $upstream = rtrim($this->config->upstreamUrl(), '/').'/v1/messages/count_tokens';
            $headers = [
                'x-api-key' => $prepared['translator_context']['api_key'],
                'content-type' => 'application/json',
                'anthropic-version' => $request->headers->get('anthropic-version') ?: '2023-06-01',
            ];
            $beta = $request->headers->get('anthropic-beta');
            if (null !== $beta && '' !== $beta) {
                $headers['anthropic-beta'] = $beta;
            }

            $body = $prepared['request_body'];
            unset($body['stream'], $body['max_tokens']);

            $response = $this->httpClient->request('POST', $upstream, [
                'headers' => $headers,
                'json' => $body,
                'timeout' => 60,
            ]);

            $status = $response->getStatusCode();
            $content = $response->getContent(false);

            return new Response($content, $status, ['Content-Type' => 'application/json']);
        } catch (\Throwable $e) {
            $this->logger->error('MessagesGateway: count_tokens failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
            ]);

            return $this->anthropicError('Token counting failed: '.$e->getMessage(), 'api_error', 502);
        }
    }

    /**
     * @param array{
     *     ok: true,
     *     stream: bool,
     *     headers: array<string, string>,
     *     raw_stream: bool,
     *     key_source: 'user'|'operator',
     *     resolved: array{provider: string, providerModelId: string, displayModel: string, model_id: int, requested: string, aliased_from: string|null},
     *     budget: array<string, mixed>,
     *     session_id: string|null,
     *     session_key: string,
     *     body_mutated: bool,
     *     request_body: array<string, mixed>,
     *     translator_context: array<string, mixed>,
     *     status: int,
     *     body: array<string, mixed>|string|null,
     *     usage: MessagesUsage,
     *     tool_loop: bool,
     *     tool_catalog: array<string, mixed>|null,
     *     replaced_server_tools: list<string>,
     *     web_search: string,
     *     vision: string,
     *     context_hash: string|null,
     *     debug: bool
     * } $prepared
     */
    private function streamResponse(array $prepared, User $user): StreamedResponse
    {
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');
        foreach ($prepared['headers'] as $name => $value) {
            $response->headers->set($name, $value);
        }
        $this->addWebSearchHeader($response, $prepared);
        $this->addVisionHeader($response, $prepared);
        if (!empty($prepared['debug']) && !empty($prepared['context_hash'])) {
            $response->headers->set('x-synaplan-context-hash', (string) $prepared['context_hash']);
        }

        $response->setCallback(function () use ($prepared, $user): void {
            while (ob_get_level()) {
                ob_end_clean();
            }
            ob_implicit_flush(true);
            set_time_limit(0);
            ignore_user_abort(false);

            $buffer = '';
            $highestBlockIndex = -1;
            $noticeEmitted = false;
            // Tee of streamed text deltas for the async session summary —
            // capped so a long response never bloats memory or the queue.
            $responseTextTee = '';
            $teeCap = ApiSessionSummaryService::EXCERPT_MAX_CHARS;
            $collectText = function (array $decoded) use (&$responseTextTee, $teeCap): void {
                if (mb_strlen($responseTextTee) >= $teeCap) {
                    return;
                }
                if ('content_block_delta' === ($decoded['type'] ?? '')
                    && 'text_delta' === ($decoded['delta']['type'] ?? '')
                    && \is_string($decoded['delta']['text'] ?? null)
                ) {
                    $responseTextTee .= $decoded['delta']['text'];
                }
            };

            $emit = function (string|array $chunk) use (&$buffer, &$highestBlockIndex, &$noticeEmitted, $collectText, $prepared, $user): void {
                set_time_limit(0);

                if (\is_array($chunk)) {
                    // Synthesized event path (Phase 2+); Phase 1 passthrough uses strings.
                    $event = $chunk['event'] ?? 'message';
                    $data = $chunk['data'] ?? [];
                    if (\is_array($data)) {
                        $collectText($data);
                    }
                    echo 'event: '.$event."\n";
                    echo 'data: '.json_encode($data, \JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();

                    return;
                }

                // Tee for budget-notice splice: track content_block indices and
                // inject a trailing text block before the final message_delta.
                $buffer .= $chunk;
                $out = '';
                while (false !== ($pos = strpos($buffer, "\n"))) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $rawLine = $line;
                    $line = rtrim($line, "\r");

                    if (str_starts_with($line, 'data:')) {
                        $payload = trim(substr($line, 5));
                        $decoded = json_decode($payload, true);
                        if (\is_array($decoded)) {
                            $collectText($decoded);
                            $type = $decoded['type'] ?? '';
                            if ('content_block_start' === $type && isset($decoded['index'])) {
                                $highestBlockIndex = max($highestBlockIndex, (int) $decoded['index']);
                            }
                            if ('message_delta' === $type && !$noticeEmitted) {
                                $stop = $decoded['delta']['stop_reason'] ?? null;
                                $usageArr = $decoded['usage'] ?? [];
                                $probe = new MessagesUsage(
                                    inputTokens: (int) ($usageArr['input_tokens'] ?? 0),
                                    outputTokens: (int) ($usageArr['output_tokens'] ?? 0),
                                    stopReason: \is_string($stop) ? $stop : null,
                                );
                                if ($this->gateway->shouldEmitBudgetNotice($prepared, $probe, $user->getId())) {
                                    $idx = $highestBlockIndex + 1;
                                    $text = $this->gateway->budgetNoticeText($prepared['budget']);
                                    $out .= $this->sseEvent('content_block_start', [
                                        'type' => 'content_block_start',
                                        'index' => $idx,
                                        'content_block' => ['type' => 'text', 'text' => ''],
                                    ]);
                                    $out .= $this->sseEvent('content_block_delta', [
                                        'type' => 'content_block_delta',
                                        'index' => $idx,
                                        'delta' => ['type' => 'text_delta', 'text' => "\n\n".$text],
                                    ]);
                                    $out .= $this->sseEvent('content_block_stop', [
                                        'type' => 'content_block_stop',
                                        'index' => $idx,
                                    ]);
                                    $noticeEmitted = true;
                                }
                            }
                        }
                    }

                    $out .= $rawLine."\n";
                }

                if ('' !== $out) {
                    echo $out;
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                }
            };

            try {
                $usage = $this->gateway->executeStream($prepared, $user, $emit);
                // Flush any remaining buffer (incomplete trailing line).
                if ('' !== $buffer) {
                    echo $buffer;
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                }
                // Queue the debounced session summary — only for streams that
                // actually produced billable output (mirrors recordUsage).
                if ($usage->outputTokens > 0 || $usage->inputTokens > 0) {
                    $this->gateway->dispatchSessionSummary($prepared, $user, $responseTextTee);
                }
            } catch (\Throwable $e) {
                $this->logger->error('MessagesGateway: stream failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $user->getId(),
                ]);
                echo $this->sseEvent('error', [
                    'type' => 'error',
                    'error' => [
                        'type' => 'api_error',
                        'message' => $e->getMessage(),
                    ],
                ]);
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }
        });

        return $response;
    }

    private function normalizeCountTokensRequest(Request $request): Request
    {
        $raw = $request->getContent();
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return $request;
        }
        if (!isset($decoded['max_tokens'])) {
            $decoded['max_tokens'] = 1;
        }
        $decoded['stream'] = false;

        return Request::create(
            $request->getRequestUri(),
            'POST',
            [],
            $request->cookies->all(),
            [],
            $request->server->all(),
            json_encode($decoded, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sseEvent(string $event, array $data): string
    {
        return 'event: '.$event."\n".'data: '.json_encode($data, \JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
    }

    /**
     * @param array<string, string> $headers
     */
    private function anthropicError(string $message, string $type, int $status, array $headers = []): JsonResponse
    {
        $response = new JsonResponse([
            'type' => 'error',
            'error' => [
                'type' => $type,
                'message' => $message,
            ],
        ], $status);

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
