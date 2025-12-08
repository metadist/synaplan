<?php

namespace App\Tests\AI\Contract;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Provider\TestProvider;

/**
 * Contract Test für TestProvider.
 */
class TestProviderContractTest extends ChatProviderContractTest
{
    protected function getProvider(): ChatProviderInterface
    {
        return new TestProvider();
    }

    /**
     * TestProvider sollte immer verfügbar sein.
     */
    public function testTestProviderIsAlwaysAvailable(): void
    {
        $provider = $this->getProvider();

        $this->assertTrue($provider->isAvailable());
        $this->assertEquals('test', $provider->getName());
    }

    /**
     * TestProvider sollte alle Capabilities haben.
     */
    public function testTestProviderHasAllCapabilities(): void
    {
        $provider = $this->getProvider();
        $capabilities = $provider->getCapabilities();

        $expectedCapabilities = [
            'chat',
            'vision',
            'embedding',
            'image_generation',
            'speech_to_text',
            'text_to_speech',
            'file_analysis',
        ];

        foreach ($expectedCapabilities as $capability) {
            $this->assertContains($capability, $capabilities,
                "TestProvider should have '$capability' capability");
        }
    }
}
