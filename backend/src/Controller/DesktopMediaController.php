<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Exception\ProviderException;
use App\Entity\User;
use App\Service\Desktop\DesktopGeneratedMediaService;
use App\Service\Exception\NoModelAvailableException;
use App\Service\Exception\RateLimitExceededException;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Generated image / video / speech for paired desktop keys (`desktop:messages`).
 *
 * Lives under `/v1/` so existing pairing keys work without a re-pair or a new
 * scope. The project IMAGE / VIDEO / SPEAK catalog key is required — the
 * account TEXT2PIC / TEXT2SOUND default is never substituted.
 */
#[OA\Tag(name: 'OpenAI Compatible')]
final class DesktopMediaController extends AbstractController
{
    public function __construct(
        private readonly DesktopGeneratedMediaService $media,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/v1/media/generate', name: 'desktop_media_generate', methods: ['POST'])]
    #[OA\Post(
        path: '/v1/media/generate',
        summary: 'Generate an image or video for a Desktop project',
        description: 'Paired desktop keys (`desktop:messages`). `model` is the catalog key from the project IMAGE (TEXT2PIC) or VIDEO (TEXT2VID) slot. The file is stored on the workspace; Desktop downloads it into the project out folder and uploads a searchable copy into `DESKTOP:{projectId}`.',
        security: [['Bearer' => []], ['ApiKey' => []]],
        tags: ['OpenAI Compatible']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['prompt', 'type', 'model'],
            properties: [
                new OA\Property(property: 'prompt', type: 'string', example: 'A cat on a wooden floor'),
                new OA\Property(property: 'type', type: 'string', enum: ['image', 'video'], example: 'image'),
                new OA\Property(property: 'model', type: 'string', description: 'Catalog key `service:providerId:tag`', example: 'openai:gpt-image-1:text2pic'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Generated file URL')]
    #[OA\Response(response: 400, description: 'Invalid prompt, type, or model')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 422, description: 'Model not available')]
    #[OA\Response(response: 429, description: 'Rate limit exceeded')]
    public function generate(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->authRequired();
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $prompt = trim((string) ($data['prompt'] ?? ''));
        $type = trim((string) ($data['type'] ?? ''));
        $model = trim((string) ($data['model'] ?? ''));

        try {
            return $this->json($this->media->generate($user, $prompt, $type, $model));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (RateLimitExceededException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (NoModelAvailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ProviderException $e) {
            $this->logger->error('Desktop media generation provider error', [
                'user_id' => $user->getId(),
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\RuntimeException $e) {
            $this->logger->error('Desktop media generation failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/v1/audio/speech', name: 'desktop_audio_speech', methods: ['POST'])]
    #[OA\Post(
        path: '/v1/audio/speech',
        summary: 'Generate speech audio for a Desktop project',
        description: 'Paired desktop keys (`desktop:messages`). `model` is the catalog key from the project SPEAK (TEXT2SOUND) slot. Returns a stored audio file Desktop saves locally and adds to the project.',
        security: [['Bearer' => []], ['ApiKey' => []]],
        tags: ['OpenAI Compatible']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['text', 'model'],
            properties: [
                new OA\Property(property: 'text', type: 'string', example: 'Good morning'),
                new OA\Property(property: 'model', type: 'string', example: 'openai:tts-1:text2sound'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Generated audio file URL')]
    #[OA\Response(response: 400, description: 'Invalid text or model')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 422, description: 'Model not available')]
    #[OA\Response(response: 429, description: 'Rate limit exceeded')]
    public function speech(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->authRequired();
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $text = trim((string) ($data['text'] ?? ''));
        $model = trim((string) ($data['model'] ?? ''));

        try {
            return $this->json($this->media->speak($user, $text, $model));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (RateLimitExceededException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (NoModelAvailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ProviderException $e) {
            $this->logger->error('Desktop speech generation provider error', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\RuntimeException $e) {
            $this->logger->error('Desktop speech generation failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function authRequired(): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => [
                    'message' => 'Authentication required',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ],
            Response::HTTP_UNAUTHORIZED
        );
    }
}
