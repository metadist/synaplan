<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/api/v1/auth')]
class OidcRefreshController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
        private JWTTokenManagerInterface $jwtManager,
        private LoggerInterface $logger,
        private string $oidcClientId,
        private string $oidcClientSecret,
        private string $oidcDiscoveryUrl,
        private ?string $googleClientId,
        private ?string $googleClientSecret,
        private ?string $githubClientId,
        private ?string $githubClientSecret,
    ) {
    }

    #[Route('/refresh-token', name: 'auth_refresh_token', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/refresh-token',
        summary: 'Refresh JWT token using OAuth refresh token',
        description: 'Use stored OAuth refresh token (Keycloak/Google/GitHub) to get a new JWT token',
        tags: ['Authentication']
    )]
    #[OA\Response(
        response: 200,
        description: 'New JWT token generated',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'token', type: 'string', example: 'eyJ0eXAiOiJKV1QiLCJhbGc...'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'No refresh token available or refresh failed')]
    public function refreshToken(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $userDetails = $user->getUserDetails() ?? [];
        $userType = $user->getType();

        try {
            // Determine provider and refresh token
            $refreshToken = null;
            $newAccessToken = null;

            if ('OIDC' === $userType && isset($userDetails['oidc_refresh_token'])) {
                $refreshToken = $userDetails['oidc_refresh_token'];
                $newAccessToken = $this->refreshOidcToken($refreshToken, $userDetails);
            } elseif ('GOOGLE' === $userType && isset($userDetails['google_refresh_token'])) {
                $refreshToken = $userDetails['google_refresh_token'];
                $newAccessToken = $this->refreshGoogleToken($refreshToken, $userDetails);
            } elseif ('GITHUB' === $userType && isset($userDetails['github_access_token'])) {
                // GitHub tokens don't expire, just verify it's still valid
                $newAccessToken = $userDetails['github_access_token'];
            } else {
                return $this->json([
                    'error' => 'No refresh token available',
                    'message' => 'This account type does not support token refresh',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Generate new JWT token for our app
            $jwtToken = $this->jwtManager->create($user);

            $this->logger->info('Token refreshed successfully', [
                'user_id' => $user->getId(),
                'type' => $userType,
            ]);

            return $this->json([
                'success' => true,
                'token' => $jwtToken,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Token refresh failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Token refresh failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNAUTHORIZED);
        }
    }

    private function refreshOidcToken(string $refreshToken, array $userDetails): string
    {
        $discoveryEndpoint = rtrim($this->oidcDiscoveryUrl, '/').'/.well-known/openid-configuration';
        $discoveryResponse = $this->httpClient->request('GET', $discoveryEndpoint);
        $discovery = $discoveryResponse->toArray();

        $tokenResponse = $this->httpClient->request('POST', $discovery['token_endpoint'], [
            'body' => [
                'client_id' => $this->oidcClientId,
                'client_secret' => $this->oidcClientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ],
        ]);

        $tokenData = $tokenResponse->toArray();
        $newAccessToken = $tokenData['access_token'] ?? null;
        $newRefreshToken = $tokenData['refresh_token'] ?? $refreshToken;

        if (!$newAccessToken) {
            throw new \Exception('Failed to refresh OIDC token');
        }

        // Update stored refresh token if a new one was issued
        if ($newRefreshToken !== $refreshToken) {
            $userDetails['oidc_refresh_token'] = $newRefreshToken;
            $user = $this->getCurrentUser();
            if ($user) {
                $user->setUserDetails($userDetails);
                $this->em->flush();
            }
        }

        return $newAccessToken;
    }

    private function refreshGoogleToken(string $refreshToken, array $userDetails): string
    {
        if (!$this->googleClientId || !$this->googleClientSecret) {
            throw new \Exception('Google OAuth not configured');
        }

        $tokenResponse = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id' => $this->googleClientId,
                'client_secret' => $this->googleClientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ],
        ]);

        $tokenData = $tokenResponse->toArray();
        $newAccessToken = $tokenData['access_token'] ?? null;

        if (!$newAccessToken) {
            throw new \Exception('Failed to refresh Google token');
        }

        return $newAccessToken;
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
