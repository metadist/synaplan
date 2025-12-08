<?php

namespace App\Service;

use App\Entity\Token;
use App\Entity\User;
use App\Repository\TokenRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token Service for Access/Refresh Token Authentication.
 *
 * Access Token: Short-lived (5 min), stored in HttpOnly cookie
 * Refresh Token: Long-lived (7 days), stored in HttpOnly cookie + DB
 */
class TokenService
{
    // Token lifetimes
    public const ACCESS_TOKEN_TTL = 300;        // 5 minutes
    public const REFRESH_TOKEN_TTL = 604800;    // 7 days

    // Cookie names
    public const ACCESS_COOKIE = 'access_token';
    public const REFRESH_COOKIE = 'refresh_token';

    // Token types for DB
    public const TYPE_ACCESS = 'access';
    public const TYPE_REFRESH = 'refresh';

    public function __construct(
        private TokenRepository $tokenRepository,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        private string $appEnv,
    ) {
    }

    /**
     * Generate Access Token (short-lived, signed).
     */
    public function generateAccessToken(User $user): string
    {
        $payload = [
            'user_id' => $user->getId(),
            'email' => $user->getMail(),
            'roles' => $user->getRoles(),
            'level' => $user->getUserLevel(),
            'exp' => time() + self::ACCESS_TOKEN_TTL,
            'iat' => time(),
            'type' => self::TYPE_ACCESS,
        ];

        return $this->encodeToken($payload);
    }

    /**
     * Generate Refresh Token (long-lived, stored in DB).
     */
    public function generateRefreshToken(User $user, ?string $ipAddress = null): string
    {
        // Revoke any existing refresh tokens for this user (single session)
        // Or keep multiple for multi-device support - for now, keep multiple
        
        $token = $this->tokenRepository->createToken(
            $user,
            self::TYPE_REFRESH,
            self::REFRESH_TOKEN_TTL
        );

        if ($ipAddress) {
            $token->setIpAddress($ipAddress);
            $this->tokenRepository->save($token);
        }

        $this->logger->info('Refresh token generated', [
            'user_id' => $user->getId(),
            'token_id' => $token->getId(),
        ]);

        return $token->getToken();
    }

    /**
     * Validate Access Token and return user data.
     */
    public function validateAccessToken(string $token): ?array
    {
        $payload = $this->decodeToken($token);

        if (!$payload) {
            return null;
        }

        // Check expiration
        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        // Check type
        if (!isset($payload['type']) || $payload['type'] !== self::TYPE_ACCESS) {
            return null;
        }

        return $payload;
    }

    /**
     * Validate Refresh Token and return Token entity.
     */
    public function validateRefreshToken(string $tokenString): ?Token
    {
        $token = $this->tokenRepository->findValidToken($tokenString, self::TYPE_REFRESH);

        if (!$token) {
            $this->logger->warning('Invalid refresh token attempt');
            return null;
        }

        return $token;
    }

    /**
     * Refresh tokens: Validate refresh token, generate new access token.
     */
    public function refreshTokens(string $refreshTokenString): ?array
    {
        $refreshToken = $this->validateRefreshToken($refreshTokenString);

        if (!$refreshToken) {
            return null;
        }

        $user = $refreshToken->getUser();
        if (!$user) {
            return null;
        }

        // Check if user is still active/not banned
        // Add your user status check here if needed

        // Generate new access token
        $accessToken = $this->generateAccessToken($user);

        // Optionally rotate refresh token (more secure but more complex)
        // For now, keep the same refresh token

        $this->logger->info('Tokens refreshed', ['user_id' => $user->getId()]);

        return [
            'access_token' => $accessToken,
            'user' => $user,
        ];
    }

    /**
     * Revoke refresh token (logout).
     */
    public function revokeRefreshToken(string $tokenString): bool
    {
        $token = $this->tokenRepository->findValidToken($tokenString, self::TYPE_REFRESH);

        if ($token) {
            $this->tokenRepository->markAsUsed($token);
            $this->logger->info('Refresh token revoked', [
                'user_id' => $token->getUserId(),
                'token_id' => $token->getId(),
            ]);
            return true;
        }

        return false;
    }

    /**
     * Revoke all refresh tokens for a user (force logout everywhere).
     */
    public function revokeAllUserTokens(User $user): int
    {
        $tokens = $this->tokenRepository->createQueryBuilder('t')
            ->where('t.userId = :userId')
            ->andWhere('t.type = :type')
            ->andWhere('t.used = false')
            ->setParameter('userId', $user->getId())
            ->setParameter('type', self::TYPE_REFRESH)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($tokens as $token) {
            $this->tokenRepository->markAsUsed($token);
            $count++;
        }

        $this->logger->info('All user tokens revoked', [
            'user_id' => $user->getId(),
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Create HttpOnly cookie for Access Token.
     */
    public function createAccessCookie(string $token): Cookie
    {
        return $this->createSecureCookie(
            self::ACCESS_COOKIE,
            $token,
            time() + self::ACCESS_TOKEN_TTL
        );
    }

    /**
     * Create HttpOnly cookie for Refresh Token.
     */
    public function createRefreshCookie(string $token): Cookie
    {
        return $this->createSecureCookie(
            self::REFRESH_COOKIE,
            $token,
            time() + self::REFRESH_TOKEN_TTL
        );
    }

    /**
     * Create cookie to clear Access Token.
     */
    public function createClearAccessCookie(): Cookie
    {
        return $this->createSecureCookie(self::ACCESS_COOKIE, '', 1);
    }

    /**
     * Create cookie to clear Refresh Token.
     */
    public function createClearRefreshCookie(): Cookie
    {
        return $this->createSecureCookie(self::REFRESH_COOKIE, '', 1);
    }

    /**
     * Add auth cookies to response.
     */
    public function addAuthCookies(Response $response, string $accessToken, string $refreshToken): Response
    {
        $response->headers->setCookie($this->createAccessCookie($accessToken));
        $response->headers->setCookie($this->createRefreshCookie($refreshToken));

        return $response;
    }

    /**
     * Clear auth cookies from response.
     */
    public function clearAuthCookies(Response $response): Response
    {
        $response->headers->setCookie($this->createClearAccessCookie());
        $response->headers->setCookie($this->createClearRefreshCookie());

        return $response;
    }

    /**
     * Get user from access token payload.
     */
    public function getUserFromPayload(array $payload): ?User
    {
        if (!isset($payload['user_id'])) {
            return null;
        }

        return $this->userRepository->find($payload['user_id']);
    }

    /**
     * Create a secure HttpOnly cookie.
     */
    private function createSecureCookie(string $name, string $value, int $expire): Cookie
    {
        $isProduction = 'prod' === $this->appEnv;

        return Cookie::create($name)
            ->withValue($value)
            ->withExpires($expire)
            ->withPath('/')
            ->withSecure($isProduction)  // HTTPS only in production
            ->withHttpOnly(true)         // Not accessible via JavaScript
            ->withSameSite($isProduction ? Cookie::SAMESITE_STRICT : Cookie::SAMESITE_LAX);
    }

    /**
     * Encode token payload (simple HMAC-based, not JWT).
     */
    private function encodeToken(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = $this->sign($json);

        return base64_encode($json) . '.' . $signature;
    }

    /**
     * Decode and verify token.
     */
    private function decodeToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;
        $json = base64_decode($encodedPayload, true);

        if (!$json) {
            return null;
        }

        // Verify signature
        if (!hash_equals($this->sign($json), $signature)) {
            $this->logger->warning('Invalid token signature');
            return null;
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Sign data with app secret.
     */
    private function sign(string $data): string
    {
        $secret = $_ENV['APP_SECRET'] ?? 'default_secret_change_me';
        return hash_hmac('sha256', $data, $secret);
    }
}

