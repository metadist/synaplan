<?php

declare(strict_types=1);

namespace App\Controller\AI;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\Entity\User;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Manage the signed-in user's personal Higgsfield API credentials.
 *
 * Higgsfield uses a key+secret pair (Authorization: Key {key}:{secret}).
 * Synaplan stores per-user pairs encrypted at rest in BCONFIG via
 * {@see HiggsfieldCredentialResolver}, and falls back to a platform-wide
 * HIGGSFIELD_API_KEY/HIGGSFIELD_API_SECRET env default when no per-user
 * override exists.
 *
 * Endpoints (all authenticated, never expose the secret in responses):
 *   GET    /api/v1/ai-providers/higgsfield/credentials  — read state (masked)
 *   PUT    /api/v1/ai-providers/higgsfield/credentials  — set/replace user pair
 *   DELETE /api/v1/ai-providers/higgsfield/credentials  — clear user pair
 *   POST   /api/v1/ai-providers/higgsfield/credentials/test — verify the pair works
 */
#[Route('/api/v1/ai-providers/higgsfield/credentials', name: 'api_ai_higgsfield_credentials_')]
#[OA\Tag(name: 'AI Providers')]
final class HiggsfieldCredentialController extends AbstractController
{
    public function __construct(
        private readonly HiggsfieldCredentialResolver $resolver,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Read the current credential state for the signed-in user.
     */
    #[Route('', name: 'get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/ai-providers/higgsfield/credentials',
        summary: 'Get current Higgsfield credential state for the signed-in user',
        security: [['Bearer' => []]],
        tags: ['AI Providers'],
    )]
    #[OA\Response(
        response: 200,
        description: 'Credential state (masked)',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'has_platform_credentials', type: 'boolean', example: true),
                new OA\Property(property: 'has_user_credentials', type: 'boolean', example: false),
                new OA\Property(property: 'user_api_key_masked', type: 'string', example: ''),
                new OA\Property(property: 'effective_source', type: 'string', enum: ['user', 'platform', 'none'], example: 'platform'),
            ],
        ),
    )]
    public function get(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $userId = (int) $user->getId();
        $resolved = $this->resolver->resolve($userId);

        return $this->json([
            'has_platform_credentials' => $this->resolver->hasPlatformCredentials(),
            'has_user_credentials' => $this->resolver->hasUserCredentials($userId),
            'user_api_key_masked' => $this->resolver->maskedUserApiKey($userId),
            'effective_source' => $resolved['source'] ?? 'none',
        ]);
    }

    /**
     * Set or replace the signed-in user's personal Higgsfield credentials.
     *
     * Both `api_key` and `api_secret` must be provided as a pair. Empty or
     * mask-placeholder values are rejected.
     */
    #[Route('', name: 'put', methods: ['PUT', 'POST'])]
    #[OA\Put(
        path: '/api/v1/ai-providers/higgsfield/credentials',
        summary: 'Save the signed-in user\'s personal Higgsfield API key + secret (encrypted at rest)',
        security: [['Bearer' => []]],
        tags: ['AI Providers'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'api_key', type: 'string', example: 'hf_pub_xxxxxxxxxxxxxxxxxxxx'),
                new OA\Property(property: 'api_secret', type: 'string', example: 'hf_sec_xxxxxxxxxxxxxxxxxxxx'),
            ],
            required: ['api_key', 'api_secret'],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Credentials saved',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'has_user_credentials', type: 'boolean', example: true),
                new OA\Property(property: 'user_api_key_masked', type: 'string', example: 'hf_p****'),
            ],
        ),
    )]
    #[OA\Response(response: 400, description: 'Invalid payload (missing api_key or api_secret)')]
    public function put(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $userId = (int) $user->getId();

        try {
            $decoded = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        $data = is_array($decoded) ? $decoded : [];

        $apiKey = trim((string) ($data['api_key'] ?? ''));
        $apiSecret = trim((string) ($data['api_secret'] ?? ''));

        if ('' === $apiKey || str_contains($apiKey, '*')) {
            return $this->json(['error' => 'api_key is required'], Response::HTTP_BAD_REQUEST);
        }
        if ('' === $apiSecret || str_contains($apiSecret, '*')) {
            return $this->json(['error' => 'api_secret is required'], Response::HTTP_BAD_REQUEST);
        }

        $this->resolver->saveUserCredentials($userId, $apiKey, $apiSecret);

        $this->logger->info('Higgsfield: per-user credentials updated', [
            'user_id' => $userId,
        ]);

        return $this->json([
            'success' => true,
            'has_user_credentials' => true,
            'user_api_key_masked' => $this->resolver->maskedUserApiKey($userId),
        ]);
    }

    /**
     * Clear the signed-in user's personal credentials so they fall back to the
     * platform-wide default again.
     */
    #[Route('', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/ai-providers/higgsfield/credentials',
        summary: 'Drop the signed-in user\'s personal Higgsfield credentials (fall back to platform key)',
        security: [['Bearer' => []]],
        tags: ['AI Providers'],
    )]
    #[OA\Response(
        response: 200,
        description: 'Per-user credentials cleared',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'has_user_credentials', type: 'boolean', example: false),
                new OA\Property(property: 'has_platform_credentials', type: 'boolean', example: true),
            ],
        ),
    )]
    public function delete(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $userId = (int) $user->getId();
        $this->resolver->clearUserCredentials($userId);

        $this->logger->info('Higgsfield: per-user credentials cleared', [
            'user_id' => $userId,
        ]);

        return $this->json([
            'success' => true,
            'has_user_credentials' => false,
            'has_platform_credentials' => $this->resolver->hasPlatformCredentials(),
        ]);
    }

    /**
     * Verify that the currently-effective credential pair can authenticate
     * against the Higgsfield API. Best-effort: we make a cheap GET to a known
     * URL on the platform that requires auth and report the status code.
     *
     * The Higgsfield API does not advertise a dedicated /health endpoint;
     * GET https://platform.higgsfield.ai/ returns 401 without a valid key and
     * 200/2xx with one. A 401/403 means "key is wrong"; anything 2xx means
     * "key is at least syntactically valid + recognised".
     */
    #[Route('/test', name: 'test', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/ai-providers/higgsfield/credentials/test',
        summary: 'Test the currently-effective Higgsfield credentials',
        security: [['Bearer' => []]],
        tags: ['AI Providers'],
    )]
    #[OA\Response(
        response: 200,
        description: 'Test result',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'source', type: 'string', enum: ['user', 'platform', 'none']),
            ],
        ),
    )]
    public function test(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $userId = (int) $user->getId();
        $resolved = $this->resolver->resolve($userId);

        if (null === $resolved) {
            return $this->json([
                'success' => false,
                'message' => 'No credentials configured (neither personal nor platform-wide).',
                'source' => 'none',
            ]);
        }

        try {
            $response = $this->httpClient->request('GET', 'https://platform.higgsfield.ai/', [
                'headers' => [
                    'Authorization' => 'Key '.$resolved['api_key'].':'.$resolved['api_secret'],
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return $this->json([
                    'success' => true,
                    'message' => 'Credentials accepted by Higgsfield.',
                    'source' => $resolved['source'],
                ]);
            }

            if (401 === $status || 403 === $status) {
                return $this->json([
                    'success' => false,
                    'message' => 'Higgsfield rejected the credentials (HTTP '.$status.'). Check the key+secret pair.',
                    'source' => $resolved['source'],
                ]);
            }

            return $this->json([
                'success' => false,
                'message' => 'Unexpected response from Higgsfield (HTTP '.$status.').',
                'source' => $resolved['source'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Higgsfield: credential test failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'message' => 'Could not reach Higgsfield: '.$e->getMessage(),
                'source' => $resolved['source'],
            ]);
        }
    }
}
