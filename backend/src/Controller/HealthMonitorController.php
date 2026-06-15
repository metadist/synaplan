<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\TokenService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Production health probe for external monitoring (Uptime Robot etc.).
 *
 * Single endpoint: does the auth stack work?
 * Protected by standard API-Key authentication (X-API-Key header).
 * Internally exercises: DB (implicit via API-Key lookup), email-verified, TokenService.
 *
 * Response body: only STATUS:OK or STATUS:ERROR — no details leaked.
 * Diagnostics are logged server-side only.
 */
final class HealthMonitorController extends AbstractController
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/health/probe', name: 'api_health_probe', methods: ['GET'])]
    public function probe(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            $this->logger->error('Health probe: no authenticated user');

            return self::status('STATUS:ERROR', Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->isEmailVerified()) {
            $this->logger->error('Health probe: email not verified', ['user_id' => $user->getId()]);

            return self::status('STATUS:ERROR', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $accessToken = $this->tokenService->generateAccessToken($user);
        } catch (\Throwable $e) {
            $this->logger->error('Health probe: token generation failed', ['error' => $e->getMessage()]);

            return self::status('STATUS:ERROR', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ('' === $accessToken) {
            $this->logger->error('Health probe: empty access token');

            return self::status('STATUS:ERROR', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return self::status('STATUS:OK', Response::HTTP_OK);
    }

    private static function status(string $status, int $httpCode): JsonResponse
    {
        return new JsonResponse(['status' => $status], $httpCode);
    }
}
