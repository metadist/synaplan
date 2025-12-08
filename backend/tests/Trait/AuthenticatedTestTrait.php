<?php

declare(strict_types=1);

namespace App\Tests\Trait;

use App\Entity\User;
use App\Service\TokenService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Trait for authenticated test requests.
 * 
 * Provides helper methods to authenticate test requests using
 * the new cookie-based token system.
 */
trait AuthenticatedTestTrait
{
    /**
     * Generate access token and set it as cookie on the client.
     */
    protected function authenticateClient(KernelBrowser $client, User $user): string
    {
        /** @var TokenService $tokenService */
        $tokenService = static::getContainer()->get(TokenService::class);
        
        $accessToken = $tokenService->generateAccessToken($user);
        
        // Set the access token as a cookie on the client
        $client->getCookieJar()->set(new Cookie(
            TokenService::ACCESS_COOKIE,
            $accessToken,
            (string) (time() + TokenService::ACCESS_TOKEN_TTL),
            '/',
            'localhost'
        ));
        
        return $accessToken;
    }

    /**
     * Make an authenticated request with the access token in Authorization header.
     * Useful for tests that need to explicitly pass the token.
     */
    protected function makeAuthenticatedRequest(
        KernelBrowser $client,
        string $method,
        string $uri,
        string $accessToken,
        array $parameters = [],
        array $files = [],
        array $server = [],
        ?string $content = null
    ): void {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;
        
        if ($content !== null && !isset($server['CONTENT_TYPE'])) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        
        $client->request($method, $uri, $parameters, $files, $server, $content);
    }
}

