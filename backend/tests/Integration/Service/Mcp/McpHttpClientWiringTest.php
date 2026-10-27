<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Mcp;

use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpOAuthConsentService;
use App\Service\Mcp\McpOAuthDiscovery;
use App\Service\Mcp\McpOAuthRegistration;
use App\Service\Mcp\McpOAuthTokenProvider;
use App\Service\OAuth\OAuthClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;

/**
 * Every outbound MCP request checks the resolved address when it connects,
 * not only when the address was saved.
 */
final class McpHttpClientWiringTest extends KernelTestCase
{
    public function testMcpServicesUseThePrivateNetworkGuard(): void
    {
        $container = static::getContainer();

        foreach ([McpClient::class, McpOAuthDiscovery::class, McpOAuthRegistration::class] as $id) {
            self::assertInstanceOf(NoPrivateNetworkHttpClient::class, $this->property($container->get($id), 'httpClient'), $id);
        }

        foreach ([McpOAuthConsentService::class, McpOAuthTokenProvider::class] as $id) {
            $oauth = $this->property($container->get($id), 'oauth');
            self::assertInstanceOf(OAuthClient::class, $oauth, $id);
            self::assertInstanceOf(NoPrivateNetworkHttpClient::class, $this->property($oauth, 'httpClient'), $id);
        }
    }

    private function property(object $service, string $name): mixed
    {
        return (new \ReflectionProperty($service, $name))->getValue($service);
    }
}
