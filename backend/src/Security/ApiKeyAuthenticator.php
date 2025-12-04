<?php

namespace App\Security;

use App\Repository\ApiKeyRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * API Key Authenticator for External Services.
 *
 * Supports:
 * - Header: X-API-Key: your-api-key
 * - Query: ?api_key=your-api-key
 *
 * Used for webhooks (Email, WhatsApp) and other external integrations
 */
class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private ApiKeyRepository $apiKeyRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Check if request contains API key.
     */
    public function supports(Request $request): ?bool
    {
        // Check for X-API-Key header or api_key query parameter
        return $request->headers->has('X-API-Key') || $request->query->has('api_key');
    }

    /**
     * Authenticate using API key.
     */
    public function authenticate(Request $request): Passport
    {
        // Extract API key from header or query
        $apiKey = $request->headers->get('X-API-Key')
                  ?? $request->query->get('api_key');

        if (!$apiKey) {
            throw new AuthenticationException('No API key provided');
        }

        $this->logger->info('API Key authentication attempt', [
            'ip' => $request->getClientIp(),
            'path' => $request->getPathInfo(),
            'key_prefix' => substr($apiKey, 0, 8).'...',
        ]);

        // Find API key in database
        $apiKeyEntity = $this->apiKeyRepository->findActiveByKey($apiKey);

        if (!$apiKeyEntity) {
            $this->logger->warning('Invalid API key used', [
                'ip' => $request->getClientIp(),
                'key_prefix' => substr($apiKey, 0, 8).'...',
            ]);
            throw new AuthenticationException('Invalid or inactive API key');
        }

        // Check if API key is active
        if (!$apiKeyEntity->isActive()) {
            $this->logger->warning('Inactive API key used', [
                'key_id' => $apiKeyEntity->getId(),
                'owner_id' => $apiKeyEntity->getOwnerId(),
            ]);
            throw new AuthenticationException('API key is inactive');
        }

        // Update last used timestamp (async to not slow down request)
        $apiKeyEntity->updateLastUsed();
        $this->apiKeyRepository->save($apiKeyEntity, false); // Don't flush immediately

        $this->logger->info('API Key authentication successful', [
            'key_id' => $apiKeyEntity->getId(),
            'owner_id' => $apiKeyEntity->getOwnerId(),
            'key_name' => $apiKeyEntity->getName(),
            'scopes' => $apiKeyEntity->getScopes(),
        ]);

        // Store API key entity in request attributes for later use
        $request->attributes->set('api_key', $apiKeyEntity);

        // Create passport with user from API key owner
        return new SelfValidatingPassport(
            new UserBadge((string) $apiKeyEntity->getOwnerId())
        );
    }

    /**
     * On successful authentication.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Let the request continue
        return null;
    }

    /**
     * On authentication failure.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'success' => false,
            'error' => 'Authentication failed',
            'message' => $exception->getMessage(),
        ], Response::HTTP_UNAUTHORIZED);
    }
}
