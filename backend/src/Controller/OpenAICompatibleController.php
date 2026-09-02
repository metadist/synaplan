<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Exception\ProviderException;
use App\AI\OpenAI\OpenAiGatewayToolLoop;
use App\AI\Service\AiFacade;
use App\AI\Stream\StreamChunk;
use App\AI\Tool\ToolCallAccumulator;
use App\Entity\Model;
use App\Entity\User;
use App\Message\SummarizeApiSessionCommand;
use App\Repository\ModelRepository;
use App\Service\Api\OpenAiChatCompletionRequest;
use App\Service\Api\OpenAiChatCompletionRequestException;
use App\Service\Api\OpenAiChatCompletionResponder;
use App\Service\Api\OpenAiToolCallingGate;
use App\Service\MessagesGateway\ApiSessionSummaryService;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * OpenAI-compatible API endpoints.
 *
 * Allows any OpenAI SDK to use Synaplan as a drop-in replacement
 * by pointing the base_url to this server's /v1/ path.
 */
#[OA\Tag(name: 'OpenAI Compatible', description: 'Drop-in compatible endpoints for OpenAI SDKs')]
class OpenAICompatibleController extends AbstractController
{
    public function __construct(
        private AiFacade $aiFacade,
        private ModelRepository $modelRepository,
        private ModelConfigService $modelConfigService,
        private RateLimitService $rateLimitService,
        private MessagesGatewayConfig $messagesGatewayConfig,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private OpenAiToolCallingGate $toolCallingGate,
        private OpenAiGatewayToolLoop $toolLoop,
    ) {
    }

    #[Route('/v1/chat/completions', name: 'openai_chat_completions', methods: ['POST'])]
    #[OA\Post(
        path: '/v1/chat/completions',
        summary: 'Create a chat completion (OpenAI-compatible)',
        description: 'Generates a chat completion. Accepts the same request format as the OpenAI API. Supports streaming via SSE when stream=true. Client tools are relayed as finish_reason=tool_calls when the resolved model passes the dual tool-calling gate.',
        security: [['Bearer' => []]],
        tags: ['OpenAI Compatible']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['messages'],
            properties: [
                new OA\Property(property: 'model', type: 'string', example: 'gpt-4o', description: 'Model ID (providerId from Synaplan). Falls back to user default if omitted.'),
                new OA\Property(
                    property: 'messages',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'role', type: 'string', enum: ['system', 'user', 'assistant', 'tool']),
                            new OA\Property(property: 'content', description: 'String or array of content parts'),
                            new OA\Property(property: 'tool_calls', type: 'array', items: new OA\Items(type: 'object')),
                            new OA\Property(property: 'tool_call_id', type: 'string'),
                        ]
                    ),
                    example: [['role' => 'user', 'content' => 'Hello!']]
                ),
                new OA\Property(property: 'temperature', type: 'number', example: 0.7),
                new OA\Property(property: 'max_tokens', type: 'integer', example: 4096),
                new OA\Property(property: 'stream', type: 'boolean', example: false),
                new OA\Property(
                    property: 'tools',
                    type: 'array',
                    description: 'OpenAI function declarations. Requires a model with synaplan:tool_use.',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'type', type: 'string', example: 'function'),
                            new OA\Property(
                                property: 'function',
                                properties: [
                                    new OA\Property(property: 'name', type: 'string', example: 'get_weather'),
                                    new OA\Property(property: 'description', type: 'string'),
                                    new OA\Property(property: 'parameters', type: 'object'),
                                ],
                                type: 'object'
                            ),
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(property: 'tool_choice', description: 'auto | none | required | {type:function,function:{name}}'),
                new OA\Property(property: 'parallel_tool_calls', type: 'boolean', example: true),
                new OA\Property(
                    property: 'stream_options',
                    properties: [
                        new OA\Property(property: 'include_usage', type: 'boolean', example: true),
                    ],
                    type: 'object'
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Chat completion response (non-streaming) or SSE stream (streaming)',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'id', type: 'string', example: 'chatcmpl-synaplan-abc123'),
                new OA\Property(property: 'object', type: 'string', example: 'chat.completion'),
                new OA\Property(property: 'created', type: 'integer', example: 1700000000),
                new OA\Property(property: 'model', type: 'string', example: 'gpt-4o'),
                new OA\Property(
                    property: 'choices',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'index', type: 'integer', example: 0),
                            new OA\Property(
                                property: 'message',
                                properties: [
                                    new OA\Property(property: 'role', type: 'string', example: 'assistant'),
                                    new OA\Property(property: 'content', type: 'string', example: 'Hello! How can I help?', nullable: true),
                                    new OA\Property(property: 'tool_calls', type: 'array', items: new OA\Items(type: 'object')),
                                ],
                                type: 'object'
                            ),
                            new OA\Property(property: 'finish_reason', type: 'string', example: 'stop'),
                        ]
                    )
                ),
                new OA\Property(
                    property: 'usage',
                    properties: [
                        new OA\Property(property: 'prompt_tokens', type: 'integer', example: 0),
                        new OA\Property(property: 'completion_tokens', type: 'integer', example: 0),
                        new OA\Property(property: 'total_tokens', type: 'integer', example: 0),
                    ],
                    type: 'object'
                ),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request or tools not supported on this model')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 404, description: 'Model not found')]
    #[OA\Response(response: 429, description: 'Rate limit exceeded')]
    public function chatCompletions(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->openAiError('Authentication required', 'invalid_request_error', 'invalid_api_key', 401);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->openAiError('Invalid JSON body', 'invalid_request_error', 'invalid_json', 400);
        }

        try {
            $parsed = OpenAiChatCompletionRequest::fromBody($body);
        } catch (OpenAiChatCompletionRequestException $e) {
            return $this->openAiError($e->getMessage(), 'invalid_request_error', $e->errorCode, 400);
        }

        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'MESSAGES');
        if (!$rateLimitCheck['allowed']) {
            return $this->openAiError('Rate limit exceeded', 'rate_limit_error', 'rate_limit_exceeded', 429);
        }

        return $this->dispatchChatCompletion($user, $parsed);
    }

    #[Route('/v1/models', name: 'openai_list_models', methods: ['GET'])]
    #[OA\Get(
        path: '/v1/models',
        summary: 'List available models (OpenAI-compatible)',
        description: 'Returns a list of all available models in OpenAI format. Models that pass the dual tool-calling gate include capabilities: ["synaplan:tool_use"].',
        security: [['Bearer' => []]],
        tags: ['OpenAI Compatible']
    )]
    #[OA\Response(
        response: 200,
        description: 'List of models',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'object', type: 'string', example: 'list'),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'string', example: 'gpt-4o'),
                            new OA\Property(property: 'object', type: 'string', example: 'model'),
                            new OA\Property(property: 'created', type: 'integer', example: 1700000000),
                            new OA\Property(property: 'owned_by', type: 'string', example: 'openai'),
                            new OA\Property(
                                property: 'capabilities',
                                type: 'array',
                                items: new OA\Items(type: 'string'),
                                example: ['synaplan:tool_use']
                            ),
                        ]
                    )
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    public function listModels(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->openAiError('Authentication required', 'invalid_request_error', 'invalid_api_key', 401);
        }

        $models = $this->modelRepository->findBy(['active' => 1]);
        $data = [];

        foreach ($models as $model) {
            $row = [
                'id' => $model->getProviderId() ?: $model->getName(),
                'object' => 'model',
                'created' => 1700000000,
                'owned_by' => strtolower($model->getService()),
            ];
            if ($this->toolCallingGate->allows($model)) {
                $row['capabilities'] = [OpenAiToolCallingGate::CAPABILITY];
            }
            $data[] = $row;
        }

        return new JsonResponse([
            'object' => 'list',
            'data' => $data,
        ]);
    }

    private function dispatchChatCompletion(User $user, OpenAiChatCompletionRequest $parsed): Response
    {
        $resolvedModel = $this->resolveModel($parsed->model, $user->getId());
        if (null === $resolvedModel) {
            return $this->openAiError(
                null !== $parsed->model && '' !== $parsed->model
                    ? sprintf('The model `%s` does not exist or is not available.', $parsed->model)
                    : 'No model specified and no default chat model is configured.',
                'invalid_request_error',
                'model_not_found',
                404,
            );
        }

        $gateAllows = $this->toolCallingGate->allows($resolvedModel['entity']);
        if ($parsed->requestsTools() && !$gateAllows) {
            return $this->openAiError(
                sprintf('The model `%s` does not support tools.', $resolvedModel['displayModel']),
                'invalid_request_error',
                'tools_not_supported',
                400,
            );
        }

        $options = array_merge([
            'model' => $resolvedModel['providerModelId'],
            'provider' => $resolvedModel['provider'],
            'include_usage' => $parsed->includeUsage,
        ], $parsed->providerToolOptions());
        if ($gateAllows && 'none' !== $parsed->toolChoice) {
            $options['server_tool_loop'] = true;
        }
        if (null !== $parsed->temperature) {
            $options['temperature'] = $parsed->temperature;
        }
        if (null !== $parsed->maxTokens) {
            $options['max_tokens'] = $parsed->maxTokens;
        }

        $this->logger->info('OpenAI-compatible chat request', [
            'user_id' => $user->getId(),
            'model_requested' => $parsed->model,
            'model_resolved' => $resolvedModel['providerModelId'],
            'provider' => $resolvedModel['provider'],
            'stream' => $parsed->stream,
            'messages_count' => count($parsed->messages),
            'tools' => count($parsed->tools),
        ]);

        $completionId = 'chatcmpl-synaplan-'.bin2hex(random_bytes(12));
        $created = time();
        $dbModelId = $resolvedModel['model_id'];

        if ($parsed->stream) {
            return $this->handleStream($user, $parsed->messages, $options, $completionId, $created, $resolvedModel['displayModel'], $dbModelId);
        }

        return $this->handleNonStream($user, $parsed->messages, $options, $completionId, $created, $resolvedModel['displayModel'], $dbModelId);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     */
    private function handleNonStream(User $user, array $messages, array $options, string $completionId, int $created, string $displayModel, ?int $dbModelId): JsonResponse
    {
        try {
            $result = !empty($options['server_tool_loop'])
                ? $this->toolLoop->complete($user, $messages, $options)
                : $this->aiFacade->chat($messages, $user->getId(), $options);
            $payload = OpenAiChatCompletionResponder::nonStreamPayload($completionId, $created, $displayModel, $result);
            $toolCalls = is_array($payload['choices'][0]['message']['tool_calls'] ?? null)
                ? $payload['choices'][0]['message']['tool_calls']
                : [];
            $content = is_string($result['content'] ?? null) ? $result['content'] : '';
            $responseText = OpenAiChatCompletionResponder::responseTextForMetering($content, $toolCalls);
            $loopNotes = is_array($result['loop_notes'] ?? null) ? $result['loop_notes'] : [];
            if ([] !== $loopNotes) {
                $responseText = trim($responseText."\n".implode("\n", $loopNotes));
            }

            $this->recordChatUsage($user, [
                'provider' => $result['provider'] ?? 'unknown',
                'model' => $result['model'] ?? 'unknown',
                'model_id' => $dbModelId,
                'usage' => $result['usage'] ?? [],
                'response_text' => $responseText,
                'input_text' => $this->lastUserText($messages),
                'source' => 'OPENAI_API',
            ]);

            $this->dispatchSessionSummary($user, $messages, $displayModel, $responseText);

            return new JsonResponse($payload);
        } catch (\Throwable $e) {
            ['status' => $status, 'type' => $type, 'code' => $code] = $this->describeFailure($e);

            $this->logger->error('OpenAI-compatible chat failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
                'status' => $status,
            ]);

            return $this->openAiError($e->getMessage(), $type, $code, $status);
        }
    }

    /**
     * Turn a failed completion into an OpenAI-shaped error.
     *
     * A provider rejecting the request (unreadable image, bad key, rate limit)
     * is not an internal error: answering 500 hides the cause and invites
     * clients to retry a request that can never succeed. Relay the upstream
     * status and the error type OpenAI clients branch on.
     *
     * Upstream 4xx about tools become 400 tools_not_supported, never a 500.
     *
     * @return array{status: int, type: string, code: string}
     */
    private function describeFailure(\Throwable $e): array
    {
        $status = 500;
        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof ProviderException && null !== $current->getUpstreamStatus()) {
                $status = $current->getUpstreamStatus();
                break;
            }

            if ($current instanceof HttpExceptionInterface) {
                $status = $current->getResponse()->getStatusCode();
                break;
            }
        }

        if ($status < 500 && self::isToolsUpstreamError($e->getMessage())) {
            return [
                'status' => 400, 'type' => 'invalid_request_error', 'code' => 'tools_not_supported',
            ];
        }

        return match (true) {
            Response::HTTP_UNAUTHORIZED === $status, Response::HTTP_FORBIDDEN === $status => [
                'status' => $status, 'type' => 'authentication_error', 'code' => 'invalid_api_key',
            ],
            Response::HTTP_TOO_MANY_REQUESTS === $status => [
                'status' => $status, 'type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded',
            ],
            $status < 500 => [
                'status' => $status, 'type' => 'invalid_request_error', 'code' => 'upstream_error',
            ],
            default => [
                'status' => $status, 'type' => 'server_error', 'code' => 'internal_error',
            ],
        };
    }

    private static function isToolsUpstreamError(string $message): bool
    {
        $haystack = strtolower($message);
        foreach (['tool_choice', 'tool_calls', 'function calling', 'function_call'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return str_contains($haystack, 'tool')
            && (str_contains($haystack, 'support') || str_contains($haystack, 'not enabled'));
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     */
    private function handleStream(User $user, array $messages, array $options, string $completionId, int $created, string $displayModel, ?int $dbModelId): StreamedResponse
    {
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        $response->setCallback(function () use ($user, $messages, $options, $completionId, $created, $displayModel, $dbModelId) {
            // Real HTTP (FrankenPHP / php-fpm) must drop leftover output
            // buffers or SSE never reaches the client. PHPUnit captures via
            // its own buffer and PHP_SAPI is cli — leave those alone, and
            // do not implicit-flush or the test buffer is emptied on echo.
            $flushToClient = 'cli' !== \PHP_SAPI;
            if ($flushToClient) {
                while (ob_get_level()) {
                    ob_end_clean();
                }
                ob_implicit_flush(true);
            }
            set_time_limit(0);
            ignore_user_abort(false);

            $firstChunk = true;
            $accumulatedContent = '';
            $finishReason = 'stop';
            $accumulator = new ToolCallAccumulator();
            $announcedIndexes = [];

            try {
                $onChunk = function ($chunk) use ($completionId, $created, $displayModel, &$firstChunk, &$accumulatedContent, &$finishReason, $accumulator, &$announcedIndexes, $flushToClient) {
                    if (connection_aborted()) {
                        return;
                    }

                    if (is_array($chunk) && 'finish' === ($chunk['type'] ?? '')) {
                        $reason = $chunk['finish_reason'] ?? null;
                        if (is_string($reason) && '' !== $reason) {
                            $finishReason = $reason;
                        }

                        return;
                    }

                    if (is_array($chunk) && 'tool_call_delta' === ($chunk['type'] ?? '')) {
                        $accumulator->addDelta($chunk);
                        if ($firstChunk) {
                            $this->writeSSE(OpenAiChatCompletionResponder::roleChunk($completionId, $created, $displayModel), $flushToClient);
                            $firstChunk = false;
                        }
                        $this->writeSSE(OpenAiChatCompletionResponder::toolCallDeltaChunk(
                            $completionId,
                            $created,
                            $displayModel,
                            $chunk,
                            $announcedIndexes,
                        ), $flushToClient);

                        return;
                    }

                    // Only visible answer text is forwarded — reasoning
                    // chunks (chain-of-thought) are never exposed (#1067).
                    $content = is_string($chunk) || is_array($chunk)
                        ? StreamChunk::visibleText($chunk)
                        : '';

                    if ('' === $content) {
                        return;
                    }

                    $accumulatedContent .= $content;

                    if ($firstChunk) {
                        $this->writeSSE(OpenAiChatCompletionResponder::roleChunk($completionId, $created, $displayModel), $flushToClient);
                        $firstChunk = false;
                    }

                    $this->writeSSE(OpenAiChatCompletionResponder::contentChunk($completionId, $created, $displayModel, $content), $flushToClient);
                };
                $streamMetadata = !empty($options['server_tool_loop'])
                    ? $this->toolLoop->stream($user, $messages, $onChunk, $options)
                    : $this->aiFacade->chatStream($messages, $onChunk, $user->getId(), $options);

                if (!$accumulator->isEmpty() && 'stop' === $finishReason) {
                    $finishReason = 'tool_calls';
                }

                $this->writeSSE(OpenAiChatCompletionResponder::finishChunk($completionId, $created, $displayModel, $finishReason), $flushToClient);

                if (!empty($options['include_usage'])) {
                    $usage = is_array($streamMetadata['usage'] ?? null) ? $streamMetadata['usage'] : [];
                    $this->writeSSE(OpenAiChatCompletionResponder::usageChunk($completionId, $created, $displayModel, $usage), $flushToClient);
                }

                echo "data: [DONE]\n\n";
                $this->flushSse($flushToClient);

                $toolCalls = $accumulator->isEmpty() ? [] : $accumulator->complete();
                $responseText = OpenAiChatCompletionResponder::responseTextForMetering($accumulatedContent, $toolCalls);
                $loopNotes = is_array($streamMetadata['loop_notes'] ?? null) ? $streamMetadata['loop_notes'] : [];
                if ([] !== $loopNotes) {
                    $responseText = trim($responseText."\n".implode("\n", $loopNotes));
                }

                $this->recordChatUsage($user, [
                    'provider' => $streamMetadata['provider'] ?? 'unknown',
                    'model' => $streamMetadata['model'] ?? 'unknown',
                    'model_id' => $dbModelId,
                    'usage' => $streamMetadata['usage'] ?? [],
                    'source' => 'OPENAI_API',
                    'input_text' => $this->lastUserText($messages),
                    'response_text' => $responseText,
                ]);

                $this->dispatchSessionSummary($user, $messages, $displayModel, $responseText);
            } catch (\Throwable $e) {
                ['status' => $status, 'type' => $type, 'code' => $code] = $this->describeFailure($e);
                $errorPayload = [
                    'error' => [
                        'message' => $e->getMessage(),
                        'type' => $type,
                        'code' => $code,
                    ],
                ];
                echo 'data: '.json_encode($errorPayload, JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                echo "data: [DONE]\n\n";
                $this->flushSse($flushToClient);

                $this->logger->error('OpenAI-compatible stream failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $user->getId(),
                    'status' => $status,
                ]);
            }
        });

        return $response;
    }

    /**
     * Queue the debounced per-session summary (rolling summary chat + usage
     * trail). OpenAI clients resend the full history each call, so the first
     * user message fingerprints the conversation as the session key — the
     * same fallback the Anthropic gateway uses when no session header exists.
     *
     * Shares MESSAGES_GATEWAY.SESSION_SUMMARY_ENABLED with the Anthropic
     * gateway: one switch governs "summarize my API traffic".
     *
     * @param list<array<string, mixed>> $messages
     */
    private function dispatchSessionSummary(User $user, array $messages, string $displayModel, string $responseText): void
    {
        if (!$this->messagesGatewayConfig->isSessionSummaryEnabled($user->getId())) {
            return;
        }

        $firstUserMessage = '';
        $lastUserMessage = '';
        foreach ($messages as $msg) {
            if ('user' !== ($msg['role'] ?? '')) {
                continue;
            }
            $text = $this->contentToText($msg['content'] ?? '');
            if ('' === $firstUserMessage) {
                $firstUserMessage = $text;
            }
            $lastUserMessage = $text;
        }

        // Without any user content the session key would degenerate to a
        // constant per-user hash and merge unrelated sessions — skip instead.
        if ('' === $firstUserMessage) {
            return;
        }

        $cap = ApiSessionSummaryService::EXCERPT_MAX_CHARS;

        try {
            $this->messageBus->dispatch(new SummarizeApiSessionCommand(
                userId: (int) $user->getId(),
                sessionKey: hash('sha256', $user->getId().'|'.$firstUserMessage),
                client: 'openai-api',
                model: $displayModel,
                requestExcerpt: mb_substr($lastUserMessage, 0, $cap),
                responseExcerpt: mb_substr($responseText, 0, $cap),
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('OpenAI-compatible: session summary dispatch failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
            ]);
        }
    }

    /**
     * Meter a completed chat call. Metering runs after the answer is produced
     * (and, when streaming, after it has already been written to the wire), so
     * a bookkeeping failure must never turn a successful completion into a 500.
     *
     * @param array<string, mixed> $metadata
     */
    private function recordChatUsage(User $user, array $metadata): void
    {
        try {
            $this->rateLimitService->recordUsage($user, 'API_CHAT', $metadata);
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI-compatible: recordUsage failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
            ]);
        }
    }

    /**
     * Text of the last user turn, for metering and the session summary.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function lastUserText(array $messages): string
    {
        foreach (array_reverse($messages) as $msg) {
            if ('user' !== ($msg['role'] ?? '')) {
                continue;
            }

            return $this->contentToText($msg['content'] ?? '');
        }

        return '';
    }

    /**
     * Readable text of an OpenAI-shaped `content` field.
     *
     * Content is a plain string for text-only turns and a list of content parts
     * as soon as the turn carries an image or audio. Metering, the session
     * summary and the activity trail all want the text a human would read, not
     * the base64 payload of the attachment.
     */
    private function contentToText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
                continue;
            }
            if (!is_array($part)) {
                continue;
            }

            $text = match ((string) ($part['type'] ?? '')) {
                'text' => is_string($part['text'] ?? null) ? $part['text'] : '',
                'image_url' => '[image]',
                'input_audio' => '[audio]',
                'file' => '[file]',
                default => '',
            };
            if ('' !== $text) {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Resolve a model string (e.g., "gpt-4o") to a Synaplan model with provider info.
     *
     * @return array{provider: string, providerModelId: string, displayModel: string, model_id: int, entity: Model}|null
     */
    private function resolveModel(?string $modelString, int $userId): ?array
    {
        if ($modelString) {
            $model = $this->modelRepository->createQueryBuilder('m')
                ->where('m.providerId = :pid')
                ->andWhere('m.active = 1')
                ->setParameter('pid', $modelString)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($model instanceof Model) {
                return $this->resolvedFromEntity($model);
            }

            $model = $this->modelRepository->createQueryBuilder('m')
                ->where('m.name = :name')
                ->andWhere('m.active = 1')
                ->setParameter('name', $modelString)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($model instanceof Model) {
                return $this->resolvedFromEntity($model);
            }
        }

        $defaultModelId = $this->modelConfigService->getDefaultModel('CHAT', $userId);
        if ($defaultModelId) {
            $defaultModel = $this->modelRepository->find($defaultModelId);
            if ($defaultModel instanceof Model) {
                return $this->resolvedFromEntity($defaultModel);
            }
        }

        // No requested model matched and no CHAT default is configured. Return
        // null so the caller responds with a 4xx instead of silently routing to
        // a hardcoded OpenAI model — which would bill an unconfigured vendor and
        // record usage with a null model_id (unattributable cost) (#1320).
        $this->logger->warning('OpenAI-compatible: No matching model and no CHAT default configured', [
            'requested_model' => $modelString,
            'user_id' => $userId,
        ]);

        return null;
    }

    /**
     * @return array{provider: string, providerModelId: string, displayModel: string, model_id: int, entity: Model}
     */
    private function resolvedFromEntity(Model $model): array
    {
        return [
            'provider' => strtolower($model->getService()),
            'providerModelId' => $model->getProviderId(),
            'displayModel' => $model->getProviderId(),
            'model_id' => (int) $model->getId(),
            'entity' => $model,
        ];
    }

    private function writeSSE(array $data, bool $flushToClient = true): void
    {
        echo 'data: '.json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
        $this->flushSse($flushToClient);
    }

    private function flushSse(bool $flushToClient): void
    {
        if (!$flushToClient) {
            return;
        }
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    private function openAiError(string $message, string $type, string $code, int $httpStatus): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'message' => $message,
                'type' => $type,
                'param' => null,
                'code' => $code,
            ],
        ], $httpStatus);
    }
}
