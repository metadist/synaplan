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

    /**
     * User-facing demo replies must ship a marker (not markdown links).
     * Guests cannot follow /admin/setup; the frontend turns the marker into
     * a button that signs in as the seeded admin first.
     */
    public function testUserFacingDemoReplyUsesSetupMarkerNotMarkdownLinks(): void
    {
        $result = $this->getProvider()->chat(
            [['role' => 'user', 'content' => 'which model are you?']],
            ['model' => 'test-model']
        );

        $this->assertStringContainsString('[[SETUP_CTA]]', $result['content']);
        $this->assertStringNotContainsString('/admin/setup', $result['content']);
        $this->assertStringNotContainsString('](', $result['content']);
        $this->assertStringContainsString('Ollama', $result['content']);
    }

    /**
     * Vision requests deliver multimodal array content (text + image_url
     * parts) — the same shape real providers receive. The mock must answer
     * with a deterministic description instead of crashing on the string
     * assumption (regression: strtolower(array) TypeError escaped as an
     * HTTP 500 on the WhatsApp image webhook).
     */
    public function testChatAcceptsMultimodalVisionContent(): void
    {
        $result = $this->getProvider()->chat(
            [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Describe what you see in this image.'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,AAEC']],
                ],
            ]],
            ['model' => 'test-model']
        );

        $this->assertStringContainsString('Test image analysis', $result['content']);
        $this->assertStringContainsString('1 attached image', $result['content']);
    }
}
