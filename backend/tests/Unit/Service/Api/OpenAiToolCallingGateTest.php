<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Api;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\ToolCallingChatProviderInterface;
use App\AI\Service\ProviderRegistry;
use App\Entity\Model;
use App\Service\Api\OpenAiToolCallingGate;
use PHPUnit\Framework\TestCase;

final class OpenAiToolCallingGateTest extends TestCase
{
    public function testRejectsWhenCatalogFlagIsMissing(): void
    {
        $registry = $this->createMock(ProviderRegistry::class);
        $registry->expects(self::never())->method('getChatProvider');

        $model = $this->createMock(Model::class);
        $model->expects(self::any())->method('hasFeature')->with('tool_use')->willReturn(false);

        $gate = new OpenAiToolCallingGate($registry);
        self::assertFalse($gate->allows($model));
    }

    public function testRejectsWhenProviderDoesNotImplementMarker(): void
    {
        $provider = $this->createMock(ChatProviderInterface::class);
        $registry = $this->createMock(ProviderRegistry::class);
        $registry->expects(self::any())->method('getChatProvider')->with('openai')->willReturn($provider);

        $model = $this->capableModel('OpenAI', 'gpt-4o');

        $gate = new OpenAiToolCallingGate($registry);
        self::assertFalse($gate->allows($model));
    }

    public function testRejectsWhenSupportsToolCallingIsFalse(): void
    {
        $provider = $this->createMock(ToolCallingChatProviderInterface::class);
        $provider->expects(self::any())->method('supportsToolCalling')->with('gpt-4o')->willReturn(false);
        $registry = $this->createMock(ProviderRegistry::class);
        $registry->method('getChatProvider')->willReturn($provider);

        $gate = new OpenAiToolCallingGate($registry);
        self::assertFalse($gate->allows($this->capableModel('OpenAI', 'gpt-4o')));
    }

    public function testAllowsWhenBothGatesPass(): void
    {
        $provider = $this->createMock(ToolCallingChatProviderInterface::class);
        $provider->expects(self::any())->method('supportsToolCalling')->with('test-model')->willReturn(true);
        $registry = $this->createMock(ProviderRegistry::class);
        $registry->expects(self::any())->method('getChatProvider')->with('test')->willReturn($provider);

        $gate = new OpenAiToolCallingGate($registry);
        self::assertTrue($gate->allows($this->capableModel('test', 'test-model')));
    }

    public function testMissingProviderIsAClosedGate(): void
    {
        $registry = $this->createMock(ProviderRegistry::class);
        $registry->method('getChatProvider')->willThrowException(new ProviderException('gone', 'ollama'));

        $gate = new OpenAiToolCallingGate($registry);
        self::assertFalse($gate->allows($this->capableModel('Ollama', 'llama3.1:8b')));
    }

    private function capableModel(string $service, string $providerId): Model
    {
        $model = $this->createMock(Model::class);
        $model->expects(self::any())->method('hasFeature')->with('tool_use')->willReturn(true);
        $model->method('getService')->willReturn($service);
        $model->method('getProviderId')->willReturn($providerId);

        return $model;
    }
}
