<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Voice;

use App\Entity\Model;
use App\Service\Voice\LiveVoiceMenuPrice;
use PHPUnit\Framework\TestCase;

final class LiveVoiceMenuPriceTest extends TestCase
{
    public function testAPerMinuteModelKeepsItsCatalogPrice(): void
    {
        $model = (new Model())
            ->setPriceIn(0.05)
            ->setInUnit('permin')
            ->setJson(['pricing_mode' => 'per_second']);

        self::assertEqualsWithDelta(0.05, LiveVoiceMenuPrice::perMinute($model), 0.000001);
    }

    public function testAudioTokenModelsUseTheSameSpeechRateAsTheBudget(): void
    {
        $model = (new Model())->setJson([
            'pricing_mode' => 'per_audio_token',
            'audio_price_in_per_1M' => 3.0,
            'audio_price_out_per_1M' => 12.0,
        ]);

        self::assertEqualsWithDelta(0.0225, LiveVoiceMenuPrice::perMinute($model), 0.000001);
    }

    public function testAModelWithoutAVoicePriceIsFree(): void
    {
        $model = (new Model())
            ->setPriceIn(0)
            ->setInUnit('permin')
            ->setJson(['pricing_mode' => 'per_second']);

        self::assertSame(0.0, LiveVoiceMenuPrice::perMinute($model));
    }
}
