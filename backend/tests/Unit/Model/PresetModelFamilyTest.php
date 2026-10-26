<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ModelCatalog;
use App\Seed\DefaultModelConfigSeeder;
use App\Service\Model\PickerCapabilities;
use PHPUnit\Framework\TestCase;

final class PresetModelFamilyTest extends TestCase
{
    public function testReadAndVerifyAreChatSlotsWithoutAProductionBinding(): void
    {
        $this->assertSame('chat', ModelCatalog::CAPABILITY_TAGS['READ']);
        $this->assertSame('chat', ModelCatalog::CAPABILITY_TAGS['VERIFY']);
        $this->assertNotContains('READ', PickerCapabilities::ALL);
        $this->assertNotContains('VERIFY', PickerCapabilities::ALL);
        $this->assertArrayNotHasKey('READ', DefaultModelConfigSeeder::getRecommendedDefaults());
        $this->assertArrayNotHasKey('VERIFY', DefaultModelConfigSeeder::getRecommendedDefaults());

        $testDefaults = (new \ReflectionClass(DefaultModelConfigSeeder::class))->getConstant('TEST_DEFAULTS');
        $this->assertIsArray($testDefaults);
        $settings = array_column($testDefaults, 'setting');
        $this->assertContains('READ', $settings);
        $this->assertContains('VERIFY', $settings);
    }

    public function testEveryPresetCandidateHasAFamilyAndAJurisdiction(): void
    {
        $expected = [
            'groq:openai/gpt-oss-120b:chat' => ['openai', 'US'],
            'cerebras:gpt-oss-120b:chat' => ['openai', 'US'],
            'openai:gpt-6-luna:chat' => ['openai', 'US'],
            'openai:gpt-6.1-sol:chat' => ['openai', 'US'],
            'trustedtokens:zai-org/glm-5.3-flash:chat' => ['zhipu', 'DE'],
            'trustedtokens:openai/gpt-oss-120b:chat' => ['openai', 'DE'],
            'trustedtokens:qwen/qwen3.6-35b-a3b-fp8:chat' => ['alibaba', 'DE'],
            'trustedtokens:zai-org/glm-5.3:chat' => ['zhipu', 'DE'],
            'google:gemini-3.8-flash:chat' => ['google', 'US'],
            'google:gemini-3.1-pro-preview:chat' => ['google', 'US'],
            'mistral:mistral-large-4:chat' => ['mistral', 'EU'],
            'meta:muse-spark-1.3:chat' => ['meta', 'US'],
            'meta:muse-wonder-1.0:chat' => ['meta', 'US'],
            'anthropic:claude-sonnet-5:chat' => ['anthropic', 'US'],
            'anthropic:claude-sonnet-5-5:chat' => ['anthropic', 'US'],
            'anthropic:claude-opus-5-5:chat' => ['anthropic', 'US'],
            'xai:grok-4.7:chat' => ['xai', 'US'],
            'ollama:gpt-oss-120b:chat' => ['openai', 'local'],
            'ollama:gpt-oss-20b:chat' => ['openai', 'local'],
            'ollama:qwen3.5-35b:chat' => ['alibaba', 'local'],
            'triton:mistral-7b-instruct-v0.3:chat' => ['mistral', 'local'],
            'triton:gpt-oss-20b:chat' => ['openai', 'local'],
        ];

        foreach ($expected as $key => [$family, $jurisdiction]) {
            $rows = ModelCatalog::find($key);
            $this->assertCount(1, $rows, $key);
            $meta = $rows[0]['json']['meta'] ?? [];
            $this->assertSame($family, $meta['family'] ?? null, $key);
            $this->assertSame($jurisdiction, $meta['jurisdiction'] ?? null, $key);
        }
    }
}
