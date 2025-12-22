<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\OidcTokenService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class OidcTokenServiceTest extends TestCase
{
    public function testRevokeOidcTokensSucceeds(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        // Mock discovery config
        $discoveryResponse = $this->createMock(ResponseInterface::class);
        $discoveryResponse->method('toArray')->willReturn([
            'issuer' => 'https://keycloak.example.com/realms/test',
            'revocation_endpoint' => 'https://keycloak.example.com/realms/test/protocol/openid-connect/revoke',
            'jwks_uri' => 'https://keycloak.example.com/realms/test/protocol/openid-connect/certs',
        ]);

        // Mock revocation requests
        $revokeResponse = $this->createMock(ResponseInterface::class);
        $revokeResponse->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function ($method, $url) use ($discoveryResponse, $revokeResponse) {
                if (str_contains($url, '.well-known')) {
                    return $discoveryResponse;
                }

                return $revokeResponse;
            });

        $service = new OidcTokenService(
            $httpClient,
            $this->createMock(\App\Repository\UserRepository::class),
            $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
            $this->createMock(Connection::class),
            $logger,
            $this->createMock(\App\Service\JwtValidator::class),
            'test',
            'test-client-id',
            'test-client-secret',
            'https://keycloak.example.com/realms/test'
        );

        $result = $service->revokeOidcTokens('access-token', 'refresh-token', 'keycloak');

        $this->assertTrue($result);
    }

    public function testRevokeOidcTokensReturnsTrue_whenRevocationNotSupported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        // Mock discovery config without revocation_endpoint
        $discoveryResponse = $this->createMock(ResponseInterface::class);
        $discoveryResponse->method('toArray')->willReturn([
            'issuer' => 'https://keycloak.example.com/realms/test',
            'jwks_uri' => 'https://keycloak.example.com/realms/test/protocol/openid-connect/certs',
            // No revocation_endpoint
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($discoveryResponse);

        $service = new OidcTokenService(
            $httpClient,
            $this->createMock(\App\Repository\UserRepository::class),
            $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
            $this->createMock(Connection::class),
            $logger,
            $this->createMock(\App\Service\JwtValidator::class),
            'test',
            'test-client-id',
            'test-client-secret',
            'https://keycloak.example.com/realms/test'
        );

        // Should return true (not an error, just unsupported)
        $result = $service->revokeOidcTokens('access-token', 'refresh-token', 'keycloak');

        $this->assertTrue($result);
    }

    public function testGetEndSessionUrlReturnsUrlWhenSupported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        // Mock discovery config
        $discoveryResponse = $this->createMock(ResponseInterface::class);
        $discoveryResponse->method('toArray')->willReturn([
            'issuer' => 'https://keycloak.example.com/realms/test',
            'end_session_endpoint' => 'https://keycloak.example.com/realms/test/protocol/openid-connect/logout',
            'jwks_uri' => 'https://keycloak.example.com/realms/test/protocol/openid-connect/certs',
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($discoveryResponse);

        $service = new OidcTokenService(
            $httpClient,
            $this->createMock(\App\Repository\UserRepository::class),
            $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
            $this->createMock(Connection::class),
            $logger,
            $this->createMock(\App\Service\JwtValidator::class),
            'test',
            'test-client-id',
            'test-client-secret',
            'https://keycloak.example.com/realms/test'
        );

        $logoutUrl = $service->getEndSessionUrl('https://app.example.com', 'keycloak');

        $this->assertIsString($logoutUrl);
        $this->assertStringContainsString('logout', $logoutUrl);
        $this->assertStringContainsString('post_logout_redirect_uri=', $logoutUrl);
        $this->assertStringContainsString('client_id=test-client-id', $logoutUrl);
    }

    public function testGetEndSessionUrlReturnsNullWhenNotSupported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        // Mock discovery config without end_session_endpoint
        $discoveryResponse = $this->createMock(ResponseInterface::class);
        $discoveryResponse->method('toArray')->willReturn([
            'issuer' => 'https://keycloak.example.com/realms/test',
            'jwks_uri' => 'https://keycloak.example.com/realms/test/protocol/openid-connect/certs',
            // No end_session_endpoint
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($discoveryResponse);

        $service = new OidcTokenService(
            $httpClient,
            $this->createMock(\App\Repository\UserRepository::class),
            $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
            $this->createMock(Connection::class),
            $logger,
            $this->createMock(\App\Service\JwtValidator::class),
            'test',
            'test-client-id',
            'test-client-secret',
            'https://keycloak.example.com/realms/test'
        );

        $logoutUrl = $service->getEndSessionUrl('https://app.example.com', 'keycloak');

        $this->assertNull($logoutUrl);
    }
}
