<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Exception\ProviderException;
use App\Entity\User;
use App\Service\Exception\NoModelAvailableException;
use App\Service\Exception\RateLimitExceededException;
use App\Service\MediaGenerationServiceInterface;
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
        private MediaGenerationServiceInterface $mediaService,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/generate-from-images', name: 'generate_from_images', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/media/generate-from-images',
        summary: 'Generate a new image from 1-2 input images and a text prompt (pic2pic)',
        description: 'Upload 1 or 2 reference images plus a text instruction to generate a new composite image. Supports OpenAI Responses API and Google Nano Banana.',
        security: [['Bearer' => []]],
        tags: ['Media']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['prompt', 'image1'],
                properties: [
                    new OA\Property(property: 'prompt', type: 'string', description: 'Instruction for combining/editing the images'),
                    new OA\Property(property: 'image1', type: 'string', format: 'binary', description: 'First input image (required)'),
                    new OA\Property(property: 'image2', type: 'string', format: 'binary', description: 'Second input image (optional)'),
                    new OA\Property(property: 'modelId', type: 'integer', description: 'Specific model ID', nullable: true),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Image generated successfully',
        content: new OA\JsonContent(
            required: ['success', 'file', 'provider', 'model'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'file',
                    type: 'object',
                    required: ['url', 'type', 'mimeType'],
                    properties: [
                        new OA\Property(property: 'url', type: 'string'),
                        new OA\Property(property: 'type', type: 'string', example: 'image'),
                        new OA\Property(property: 'mimeType', type: 'string', example: 'image/png'),
                    ]
                ),
                new OA\Property(property: 'provider', type: 'string', example: 'openai'),
                new OA\Property(property: 'model', type: 'string', example: 'gpt-image-1.5'),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 422, description: 'No models available')]
    #[OA\Response(response: 429, description: 'Rate limit exceeded')]
    #[OA\Response(response: 500, description: 'Generation failed')]
    public function generateFromImages(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $prompt = trim((string) $request->request->get('prompt', ''));
        $modelId = $request->request->get('modelId') ? (int) $request->request->get('modelId') : null;

        $imagePaths = [];
        foreach (['image1', 'image2'] as $field) {
            $file = $request->files->get($field);
            if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile && $file->isValid()) {
                $realPath = $file->getRealPath();
                if (false === $realPath) {
                    return $this->json(['error' => "Failed to read uploaded file: {$field}"], Response::HTTP_BAD_REQUEST);
                }
                $imagePaths[] = $realPath;
            }
        }

        if (empty($imagePaths)) {
            return $this->json(['error' => 'At least one input image (image1) is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->mediaService->generateFromImages($user, $prompt, $imagePaths, $modelId);

            return $this->json($result);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (RateLimitExceededException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (NoModelAvailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ProviderException $e) {
            $this->logger->error('Pic2pic generation provider error', [
                'user_id' => $user->getId(),
                'provider' => $e->getProviderName(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\RuntimeException $e) {
            $this->logger->error('Pic2pic generation failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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
        } catch (RateLimitExceededException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (NoModelAvailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ProviderException $e) {
            $this->logger->error('Media generation provider error', [
                'user_id' => $user->getId(),
                'type' => $type,
                'provider' => $e->getProviderName(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\RuntimeException $e) {
            $this->logger->error('Media generation failed', [
                'user_id' => $user->getId(),
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
