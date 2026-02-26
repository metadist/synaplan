<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\MediaGenerationService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/media', name: 'api_media_')]
#[OA\Tag(name: 'Media')]
final class MediaController extends AbstractController
{
    public function __construct(
        private MediaGenerationService $mediaService,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/media/generate',
        summary: 'Generate image or video from text prompt',
        description: 'Synchronous media generation endpoint for external integrations (Nextcloud, API clients). Calls the configured AI provider and returns the generated file URL.',
        security: [['Bearer' => []]],
        tags: ['Media']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['prompt', 'type'],
            properties: [
                new OA\Property(
                    property: 'prompt',
                    type: 'string',
                    description: 'Text description of the media to generate',
                    example: 'A beautiful sunset over mountains with orange sky'
                ),
                new OA\Property(
                    property: 'type',
                    type: 'string',
                    enum: ['image', 'video'],
                    description: 'Type of media to generate',
                    example: 'image'
                ),
                new OA\Property(
                    property: 'modelId',
                    type: 'integer',
                    description: 'Specific model ID to use (uses user default TEXT2PIC or TEXT2VID model if omitted)',
                    nullable: true,
                    example: 53
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Media generated successfully',
        content: new OA\JsonContent(
            required: ['success', 'file', 'provider', 'model'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'file',
                    type: 'object',
                    required: ['url', 'type', 'mimeType'],
                    properties: [
                        new OA\Property(
                            property: 'url',
                            type: 'string',
                            description: 'Relative URL to download the generated file',
                            example: '/api/v1/files/uploads/01/000/00001/2026/02/media_1_openai_1740000000.png'
                        ),
                        new OA\Property(
                            property: 'type',
                            type: 'string',
                            enum: ['image', 'video'],
                            example: 'image'
                        ),
                        new OA\Property(
                            property: 'mimeType',
                            type: 'string',
                            example: 'image/png'
                        ),
                    ]
                ),
                new OA\Property(property: 'provider', type: 'string', example: 'openai'),
                new OA\Property(property: 'model', type: 'string', example: 'dall-e-3'),
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Invalid request (missing prompt or invalid type)',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Prompt is required'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(
        response: 422,
        description: 'No models available for requested media type',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'No model available for image generation'),
            ]
        )
    )]
    #[OA\Response(
        response: 429,
        description: 'Rate limit exceeded',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Rate limit exceeded for IMAGES. Used: 10/10'),
            ]
        )
    )]
    #[OA\Response(response: 500, description: 'Media generation failed')]
    public function generate(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $prompt = trim((string) ($data['prompt'] ?? ''));
        $type = trim((string) ($data['type'] ?? ''));
        $modelId = isset($data['modelId']) ? (int) $data['modelId'] : null;

        try {
            $result = $this->mediaService->generate($user, $prompt, $type, $modelId);

            return $this->json($result);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'Rate limit exceeded')) {
                return $this->json(['error' => $message], Response::HTTP_TOO_MANY_REQUESTS);
            }

            if (str_contains($message, 'No model available')) {
                return $this->json(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->logger->error('Media generation failed', [
                'user_id' => $user->getId(),
                'type' => $type,
                'error' => $message,
            ]);

            return $this->json(
                ['error' => 'Media generation failed: '.$message],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
