<?php

namespace App\Tests\AI\Contract;

use App\AI\Interface\ChatProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Abstract Contract Test für ChatProviderInterface.
 *
 * Alle Provider-Implementierungen müssen diese Tests bestehen.
 */
abstract class ChatProviderContractTest extends TestCase
{
    /**
     * Zu testender Provider (von Subklasse implementiert).
     */
    abstract protected function getProvider(): ChatProviderInterface;

    /**
     * Test: chat returns non-empty string.
     */
    public function testChatReturnsString(): void
    {
        $provider = $this->getProvider();

        $result = $provider->chat(
            [['role' => 'user', 'content' => 'Hello, how are you?']],
            ['model' => 'test-model']
        );

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test: Provider Metadata.
     */
    public function testProviderMetadata(): void
    {
        $provider = $this->getProvider();

        // getName
        $name = $provider->getName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);

        // getCapabilities
        $capabilities = $provider->getCapabilities();
        $this->assertIsArray($capabilities);
        $this->assertContains('chat', $capabilities);

        // getDefaultModels
        $models = $provider->getDefaultModels();
        $this->assertIsArray($models);
        $this->assertArrayHasKey('chat', $models);

        // isAvailable
        $available = $provider->isAvailable();
        $this->assertIsBool($available);

        // getStatus
        $status = $provider->getStatus();
        $this->assertIsArray($status);
        $this->assertArrayHasKey('healthy', $status);
    }

    /**
     * Test: Streaming callback is invoked.
     */
    public function testChatStreamInvokesCallback(): void
    {
        $provider = $this->getProvider();
        $chunks = [];

        $provider->chatStream(
            [['role' => 'user', 'content' => 'Count to 3']],
            function ($chunk) use (&$chunks) {
                $chunks[] = $chunk;
            },
            ['model' => 'test-model']
        );

        $this->assertNotEmpty($chunks);
        $this->assertIsArray($chunks);
    }
}
