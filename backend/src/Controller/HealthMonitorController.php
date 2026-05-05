<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\TokenService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Production health probe for external monitoring (Uptime Robot etc.).
 *
 * Single endpoint: does the auth stack work?
 * Protected by a dedicated monitor token via header or query param.
 * Internally exercises: DB, PasswordHasher, email-verified, TokenService.
 *
 * Response body: only STATUS:OK or STATUS:ERROR — no details leaked.
 * Diagnostics are logged server-side only.
 */
final class HealthMonitorController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TokenService $tokenService,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(HEALTH_MONITOR_TOKEN)%')]
        private readonly string $monitorToken,
        #[Autowire('%env(HEALTH_MONITOR_USER_EMAIL)%')]
        private readonly string $monitorEmail,
        #[Autowire('%env(HEALTH_MONITOR_USER_PASSWORD)%')]
        private readonly string $monitorPassword,
    ) {
    }

    #[Route('/api/health/login', name: 'api_health_login', methods: ['GET'])]
    public function login(Request $request): JsonResponse
    {
        if (!$this->authorized($request)) {
            return self::status('STATUS:ERROR', Response::HTTP_UNAUTHORIZED);
        }

        $email = trim($this->monitorEmail);
        if ('' === $email || '' === $this->monitorPassword) {
            $this->logger->error('Health login: monitor user not configured');

            return self::status('STATUS:ERROR', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $user = $this->userRepository->findOneBy(['mail' => $email]);
        } catch (\Throwable $e) {
            $this->logger->error('Health login: DB lookup failed', ['error' => $e->getMessage()]);

            return self::status('STATUS:ERROR', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (null === $user || null === $user->getPw()) {
            $this->logger->error('Health login: monitor user missing or has no password');

            return self::status('STATUS:ERROR', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!$this->passwordHasher->isPasswordValid($user, $this->monitorPassword)) {
            $this->logger->error('Health login: password hash mismatch');

            return self::status('STATUS:ERROR', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!$user->isEmailVerified()) {
            $this->logger->error('Health login: email not verified');

            return self::status('STATUS:ERROR', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            // JWT-only, no DB write — generateRefreshToken() would write,
            // but we intentionally only test access token generation here.
            $accessToken = $this->tokenService->generateAccessToken($user);
        } catch (\Throwable $e) {
            $this->logger->error('Health login: token generation failed', ['error' => $e->getMessage()]);

            return self::status('STATUS:ERROR', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ('' === $accessToken) {
            $this->logger->error('Health login: empty access token');

            return self::status('STATUS:ERROR', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return self::status('STATUS:OK', Response::HTTP_OK);
    }

    private function authorized(Request $request): bool
    {
        $expected = trim($this->monitorToken);
        if ('' === $expected) {
            return false;
        }

        $candidate = (string) $request->headers->get('X-Health-Monitor-Token', '');
        if ('' !== $candidate && hash_equals($expected, $candidate)) {
            return true;
        }

        $candidate = (string) $request->query->get('monitor', '');
        if ('' !== $candidate && hash_equals($expected, $candidate)) {
            $this->logger->warning('Health login: token passed via query param — prefer X-Health-Monitor-Token header');

            return true;
        }

        return false;
    }

    private static function status(string $status, int $httpCode): JsonResponse
    {
        return new JsonResponse(['status' => $status], $httpCode);
    }
}
