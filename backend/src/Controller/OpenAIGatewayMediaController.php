<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\FreeApiGatewayGuard;
use App\Service\Api\OpenAiEmbeddingsRequest;
use App\Service\Api\OpenAiEmbeddingsService;
use App\Service\Api\OpenAiGatewayException;
use App\Service\Api\OpenAiImageGenerationRequest;
use App\Service\Api\OpenAiImageGenerationService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * OpenAI-compatible embeddings and image generation on the `/v1` gateway,
 * next to {@see OpenAICompatibleController}. Same key auth, same metering.
 */
#[OA\Tag(name: 'OpenAI Compatible', description: 'Drop-in compatible endpoints for OpenAI SDKs')]
final class OpenAIGatewayMediaController extends AbstractController
{
    public function __construct(
        private readonly OpenAiEmbeddingsService $embeddings,
        private readonly OpenAiImageGenerationService $images,
    ) {
    }

    #[Route('/v1/embeddings', name: 'openai_embeddings', methods: ['POST'])]
    #[OA\Post(
        path: '/v1/embeddings',
        summary: 'Create embeddings (OpenAI-compatible)',
        description: 'Embeds one or more strings with the requested VECTORIZE model, or the account default when `model` is omitted. Metered as EMBEDDINGS.',
        security: [['Bearer' => []]],
        tags: ['OpenAI Compatible']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['input'],
            properties: [
                new OA\Property(property: 'model', type: 'string', example: 'bge-m3', description: 'providerId or name of an active embedding model. Falls back to the default VECTORIZE model.'),
                new OA\Property(property: 'input', description: 'A string or an array of up to 2048 strings', oneOf: [
                    new OA\Schema(type: 'string', example: 'The quick brown fox'),
                    new OA\Schema(type: 'array', items: new OA\Items(type: 'string')),
                ]),
                new OA\Property(property: 'encoding_format', type: 'string', enum: ['float', 'base64'], example: 'float'),
                new OA\Property(property: 'dimensions', type: 'integer', example: 1024, description: 'Forwarded to models that support shortened vectors'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Embeddings in input order',
        content: new OA\JsonContent(
            required: ['object', 'data', 'model', 'usage'],
            properties: [
                new OA\Property(property: 'object', type: 'string', example: 'list'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                    required: ['object', 'index', 'embedding'],
                    properties: [
                        new OA\Property(property: 'object', type: 'string', example: 'embedding'),
                        new OA\Property(property: 'index', type: 'integer', example: 0),
                        new OA\Property(property: 'embedding', description: 'Floats, or a base64 string of little-endian float32 when encoding_format=base64', oneOf: [
                            new OA\Schema(type: 'array', items: new OA\Items(type: 'number')),
                            new OA\Schema(type: 'string'),
                        ]),
                    ]
                )),
                new OA\Property(property: 'model', type: 'string', example: 'bge-m3'),
                new OA\Property(property: 'usage', required: ['prompt_tokens', 'total_tokens'], properties: [
                    new OA\Property(property: 'prompt_tokens', type: 'integer', example: 8),
                    new OA\Property(property: 'total_tokens', type: 'integer', example: 8),
                ], type: 'object'),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 404, description: 'Model not found')]
    #[OA\Response(response: 429, description: 'Rate limit or budget exceeded')]
    #[OA\Response(response: 502, description: 'The embedding provider did not return a result')]
    public function embeddings(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->handle($request, $user, fn (User $caller, array $body, bool $gated) => $this->embeddings->create(
            $caller,
            OpenAiEmbeddingsRequest::fromBody($body),
            $gated,
        ));
    }

    #[Route('/v1/images/generations', name: 'openai_images_generations', methods: ['POST'])]
    #[OA\Post(
        path: '/v1/images/generations',
        summary: 'Generate images (OpenAI-compatible)',
        description: 'Generates images with the requested TEXT2PIC model, or the account default when `model` is omitted. Nothing is stored on this server. Metered as IMAGES.',
        security: [['Bearer' => []]],
        tags: ['OpenAI Compatible']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['prompt'],
            properties: [
                new OA\Property(property: 'model', type: 'string', example: 'gpt-image-1', description: 'providerId or name of an active image model. Falls back to the default TEXT2PIC model.'),
                new OA\Property(property: 'prompt', type: 'string', example: 'A lighthouse at dawn, watercolor'),
                new OA\Property(property: 'n', type: 'integer', minimum: 1, maximum: 4, example: 1),
                new OA\Property(property: 'size', type: 'string', example: '1024x1024', description: '"auto" or WIDTHxHEIGHT'),
                new OA\Property(property: 'quality', type: 'string', enum: ['auto', 'standard', 'hd', 'low', 'medium', 'high']),
                new OA\Property(property: 'response_format', type: 'string', enum: ['url', 'b64_json'], example: 'url'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Generated images',
        content: new OA\JsonContent(
            required: ['created', 'data'],
            properties: [
                new OA\Property(property: 'created', type: 'integer', example: 1700000000),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'url', type: 'string', description: 'Provider URL, or a data: URL when the provider returned bytes only'),
                    new OA\Property(property: 'b64_json', type: 'string'),
                    new OA\Property(property: 'revised_prompt', type: 'string'),
                ])),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 404, description: 'Model not found')]
    #[OA\Response(response: 429, description: 'Rate limit or budget exceeded')]
    #[OA\Response(response: 502, description: 'The image provider did not return a result')]
    public function imageGenerations(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->handle($request, $user, fn (User $caller, array $body, bool $gated) => $this->images->create(
            $caller,
            OpenAiImageGenerationRequest::fromBody($body),
            $gated,
        ));
    }

    /**
     * @param callable(User, array<string, mixed>, bool): array<string, mixed> $action
     */
    private function handle(Request $request, ?User $user, callable $action): JsonResponse
    {
        if (null === $user) {
            return new OpenAiGatewayException('Authentication required', 'invalid_request_error', 'invalid_api_key', 401)->toResponse();
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return OpenAiGatewayException::invalid('Invalid JSON body', 'invalid_json')->toResponse();
        }

        try {
            return new JsonResponse($action($user, $body, FreeApiGatewayGuard::isGated($request)));
        } catch (OpenAiGatewayException $e) {
            return $e->toResponse();
        }
    }
}
