<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

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
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/v1/chat/completions', name: 'openai_chat_completions', methods: ['POST'])]
    #[OA\Post(
        path: '/v1/chat/completions',
        summary: 'Create a chat completion (OpenAI-compatible)',
        description: 'Generates a chat completion. Accepts the same request format as the OpenAI API. Supports streaming via SSE when stream=true.',
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
                            new OA\Property(property: 'role', type: 'string', enum: ['system', 'user', 'assistant']),
                            new OA\Property(property: 'content', type: 'string'),
                        ]
                    ),
                    example: [['role' => 'user', 'content' => 'Hello!']]
                ),
                new OA\Property(property: 'temperature', type: 'number', example: 0.7),
                new OA\Property(property: 'max_tokens', type: 'integer', example: 4096),
                new OA\Property(property: 'stream', type: 'boolean', example: false),
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
                                    new OA\Property(property: 'content', type: 'string', example: 'Hello! How can I help?'),
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

        $messages = $body['messages'] ?? null;
        if (!is_array($messages) || empty($messages)) {
            return $this->openAiError('messages is required and must be a non-empty array', 'invalid_request_error', 'missing_messages', 400);
        }

        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'MESSAGES');
        if (!$rateLimitCheck['allowed']) {
            return $this->openAiError('Rate limit exceeded', 'rate_limit_error', 'rate_limit_exceeded', 429);
        }

        $modelString = $body['model'] ?? null;
        $temperature = isset($body['temperature']) ? (float) $body['temperature'] : null;
        $maxTokens = isset($body['max_tokens']) ? (int) $body['max_tokens'] : null;
        $stream = (bool) ($body['stream'] ?? false);

        $resolvedModel = $this->resolveModel($modelString, $user->getId());
        $completionId = 'chatcmpl-synaplan-'.bin2hex(random_bytes(12));
        $created = time();

        $options = [
            'model' => $resolvedModel['providerModelId'],
            'provider' => $resolvedModel['provider'],
        ];
        if (null !== $temperature) {
            $options['temperature'] = $temperature;
        }
        if (null !== $maxTokens) {
            $options['max_tokens'] = $maxTokens;
        }

        $this->logger->info('OpenAI-compatible chat request', [
            'user_id' => $user->getId(),
            'model_requested' => $modelString,
            'model_resolved' => $resolvedModel['providerModelId'],
            'provider' => $resolvedModel['provider'],
            'stream' => $stream,
            'messages_count' => count($messages),
        ]);

        if ($stream) {
            return $this->handleStream($user, $messages, $options, $completionId, $created, $resolvedModel['displayModel']);
        }

        return $this->handleNonStream($user, $messages, $options, $completionId, $created, $resolvedModel['displayModel']);
    }

    #[Route('/v1/models', name: 'openai_list_models', methods: ['GET'])]
    #[OA\Get(
        path: '/v1/models',
        summary: 'List available models (OpenAI-compatible)',
        description: 'Returns a list of all available models in OpenAI format.',
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
            $data[] = [
                'id' => $model->getProviderId() ?: $model->getName(),
                'object' => 'model',
                'created' => 1700000000,
                'owned_by' => strtolower($model->getService()),
            ];
        }

        return new JsonResponse([
            'object' => 'list',
            'data' => $data,
        ]);
    }

    private function handleNonStream(User $user, array $messages, array $options, string $completionId, int $created, string $displayModel): JsonResponse
    {
        try {
            $result = $this->aiFacade->chat($messages, $user->getId(), $options);

            return new JsonResponse([
                'id' => $completionId,
                'object' => 'chat.completion',
                'created' => $created,
                'model' => $displayModel,
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => $result['content'] ?? '',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI-compatible chat failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
            ]);

            return $this->openAiError($e->getMessage(), 'server_error', 'internal_error', 500);
        }
    }

    private function handleStream(User $user, array $messages, array $options, string $completionId, int $created, string $displayModel): StreamedResponse
    {
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        $response->setCallback(function () use ($user, $messages, $options, $completionId, $created, $displayModel) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            ob_implicit_flush(true);
            set_time_limit(0);
            ignore_user_abort(false);

            $firstChunk = true;

            try {
                $this->aiFacade->chatStream(
                    $messages,
                    function ($chunk) use ($completionId, $created, $displayModel, &$firstChunk) {
                        if (connection_aborted()) {
                            return;
                        }

                        $content = '';
                        if (is_array($chunk)) {
                            $content = $chunk['content'] ?? '';
                        } elseif (is_string($chunk)) {
                            $content = $chunk;
                        }

                        if ('' === $content) {
                            return;
                        }

                        if ($firstChunk) {
                            $this->writeSSE([
                                'id' => $completionId,
                                'object' => 'chat.completion.chunk',
                                'created' => $created,
                                'model' => $displayModel,
                                'choices' => [
                                    [
                                        'index' => 0,
                                        'delta' => ['role' => 'assistant'],
                                        'finish_reason' => null,
                                    ],
                                ],
                            ]);
                            $firstChunk = false;
                        }

                        $this->writeSSE([
                            'id' => $completionId,
                            'object' => 'chat.completion.chunk',
                            'created' => $created,
                            'model' => $displayModel,
                            'choices' => [
                                [
                                    'index' => 0,
                                    'delta' => ['content' => $content],
                                    'finish_reason' => null,
                                ],
                            ],
                        ]);
                    },
                    $user->getId(),
                    $options
                );

                $this->writeSSE([
                    'id' => $completionId,
                    'object' => 'chat.completion.chunk',
                    'created' => $created,
                    'model' => $displayModel,
                    'choices' => [
                        [
                            'index' => 0,
                            'delta' => new \stdClass(),
                            'finish_reason' => 'stop',
                        ],
                    ],
                ]);
                echo "data: [DONE]\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            } catch (\Throwable $e) {
                $errorPayload = [
                    'error' => [
                        'message' => $e->getMessage(),
                        'type' => 'server_error',
                        'code' => 'internal_error',
                    ],
                ];
                echo 'data: '.json_encode($errorPayload, JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                echo "data: [DONE]\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }
        });

        return $response;
    }

    /**
     * Resolve a model string (e.g., "gpt-4o") to a Synaplan model with provider info.
     *
     * @return array{provider: string, providerModelId: string, displayModel: string}
     */
    private function resolveModel(?string $modelString, int $userId): array
    {
        if ($modelString) {
            $model = $this->modelRepository->createQueryBuilder('m')
                ->where('m.providerId = :pid')
                ->andWhere('m.active = 1')
                ->setParameter('pid', $modelString)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($model) {
                return [
                    'provider' => strtolower($model->getService()),
                    'providerModelId' => $model->getProviderId(),
                    'displayModel' => $model->getProviderId(),
                ];
            }

            $model = $this->modelRepository->createQueryBuilder('m')
                ->where('m.name = :name')
                ->andWhere('m.active = 1')
                ->setParameter('name', $modelString)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($model) {
                return [
                    'provider' => strtolower($model->getService()),
                    'providerModelId' => $model->getProviderId(),
                    'displayModel' => $model->getProviderId(),
                ];
            }
        }

        $defaultModelId = $this->modelConfigService->getDefaultModel('CHAT', $userId);
        if ($defaultModelId) {
            $defaultModel = $this->modelRepository->find($defaultModelId);
            if ($defaultModel) {
                return [
                    'provider' => strtolower($defaultModel->getService()),
                    'providerModelId' => $defaultModel->getProviderId(),
                    'displayModel' => $defaultModel->getProviderId(),
                ];
            }
        }

        return [
            'provider' => 'openai',
            'providerModelId' => $modelString ?? 'gpt-4o',
            'displayModel' => $modelString ?? 'gpt-4o',
        ];
    }

    private function writeSSE(array $data): void
    {
        echo 'data: '.json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
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
