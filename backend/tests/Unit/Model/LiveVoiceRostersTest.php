<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\LiveVoiceRosters;
use App\Model\ModelCatalog;
use PHPUnit\Framework\TestCase;

final class LiveVoiceRostersTest extends TestCase
{
    public function testRostersKeepTheDocumentedDefaultFirstAndStayUnique(): void
    {
        self::assertSame('marin', LiveVoiceRosters::GPT_LIVE[0]);
        self::assertCount(13, LiveVoiceRosters::GPT_LIVE);
        self::assertContains('quartz', LiveVoiceRosters::GPT_LIVE);
        self::assertNotContains('alloy', LiveVoiceRosters::GPT_LIVE);

        self::assertSame('marin', LiveVoiceRosters::REALTIME[0]);
        self::assertCount(10, LiveVoiceRosters::REALTIME);
        self::assertContains('cedar', LiveVoiceRosters::REALTIME);
        self::assertNotContains('quartz', LiveVoiceRosters::REALTIME);

        self::assertSame('Puck', LiveVoiceRosters::GEMINI[0]);
        self::assertCount(30, LiveVoiceRosters::GEMINI);
        self::assertContains('Aoede', LiveVoiceRosters::GEMINI);

        self::assertSame('eve', LiveVoiceRosters::GROK[0]);
        self::assertCount(28, LiveVoiceRosters::GROK);
        self::assertContains('carina', LiveVoiceRosters::GROK);

        foreach ([
            LiveVoiceRosters::GPT_LIVE,
            LiveVoiceRosters::REALTIME,
            LiveVoiceRosters::GEMINI,
            LiveVoiceRosters::GROK,
        ] as $roster) {
            self::assertSame($roster, array_values(array_unique($roster)));
        }
    }

    public function testLiveCatalogRowsUseTheRostersAndCompatibleStaysOpen(): void
    {
        self::assertSame(LiveVoiceRosters::GPT_LIVE, $this->voices('openai:gpt-live-1:voice2voice'));
        self::assertSame(LiveVoiceRosters::REALTIME, $this->voices('openai:gpt-realtime-2.1:voice2voice'));
        self::assertSame(LiveVoiceRosters::REALTIME, $this->voices('openai:gpt-realtime-2.1-mini:voice2voice'));
        self::assertSame(LiveVoiceRosters::GEMINI, $this->voices('google:gemini-3.8-live:voice2voice'));
        self::assertSame(LiveVoiceRosters::GEMINI, $this->voices('google:gemini-3.8-live-extended-thinking:voice2voice'));
        self::assertSame(LiveVoiceRosters::GROK, $this->voices('xai:grok-voice-3.0:voice2voice'));

        $compatible = ModelCatalog::find('compatible:qwen3-omni:voice2voice')[0] ?? null;
        self::assertNotNull($compatible);
        self::assertArrayNotHasKey('voices', $compatible['json']);
    }

    /**
     * @return list<string>
     */
    private function voices(string $key): array
    {
        $model = ModelCatalog::find($key)[0] ?? null;
        self::assertNotNull($model);
        $voices = $model['json']['voices'] ?? null;
        self::assertIsArray($voices);

        return array_values($voices);
    }
}
