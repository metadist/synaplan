<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Custom;

use App\Entity\CustomTool;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Security\SsrfGuard;
use App\Service\Tool\Custom\HttpToolExecutor;
use App\Service\Tool\Custom\InvalidToolTemplateException;
use App\Service\Tool\Custom\TemplateRenderer;
use App\Service\Tool\ToolsConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpToolExecutorSsrfTest extends TestCase
{
    /**
     * @dataProvider blockedUrls
     */
    public function testBlockedTargetsRefuse(string $url): void
    {
        $executor = $this->executor();
        $tool = new CustomTool(1, 'lookup', 'Lookup');
        $tool->setSpec(['method' => 'GET', 'url' => $url]);
        $this->expectException(InvalidToolTemplateException::class);
        $executor->execute($tool, [], 1);
    }

    /**
     * @return list<list<string>>
     */
    public static function blockedUrls(): array
    {
        return [
            ['http://127.0.0.1/'],
            ['https://169.254.169.254/latest/meta-data'],
            ['https://[::1]/'],
            ['https://localhost/admin'],
        ];
    }

    public function testTryItRedactsCredential(): void
    {
        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->method('reveal')->willReturn('{"name":"Authorization","value":"Bearer super-secret"}');
        $executor = $this->executor($vault);
        $tool = new CustomTool(1, 'lookup', 'Lookup');
        $tool->setCredentialId(9);
        $tool->setSpec(['method' => 'GET', 'url' => 'https://example.com/{{input.id}}']);
        $resolved = $executor->resolve($tool, ['id' => '1'], includeSecret: false);
        $this->assertSame('***', $resolved['headers']['Authorization'] ?? null);
        $this->assertStringNotContainsString('super-secret', json_encode($resolved) ?: '');
    }

    private function executor(?CredentialVaultInterface $vault = null): HttpToolExecutor
    {
        $config = $this->createMock(ToolsConfig::class);
        $config->method('allowPlainHttp')->willReturn(false);
        $config->method('maxResponseBytes')->willReturn(1024);

        return new HttpToolExecutor(
            $this->createMock(HttpClientInterface::class),
            new SsrfGuard(),
            new TemplateRenderer(),
            $config,
            $vault ?? $this->createMock(CredentialVaultInterface::class),
            new NullLogger(),
        );
    }
}
