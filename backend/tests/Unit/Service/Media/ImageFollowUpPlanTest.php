<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\Media\ImageFollowUpPlan;
use PHPUnit\Framework\TestCase;

final class ImageFollowUpPlanTest extends TestCase
{
    public function testGermanSceneFollowUpIsACreativeImageEdit(): void
    {
        self::assertTrue(ImageFollowUpPlan::isCreativeImageFollowUp(
            'schon super. lass es genau so aber mach den zweiten (alten, sitzenden) vogel auch fliegend. und dann "SYNAPLAN" in capital buchstaben oben rechts in die ecke. beide vögel fliegend wie der neu generierte und den hintergrund eher blass und verschwommen wie im rechten unteren teil des bilds.',
        ));
    }

    public function testPixelExactStampStaysFileWork(): void
    {
        self::assertFalse(ImageFollowUpPlan::isCreativeImageFollowUp(
            'Stempel unten rechts INTERNAL drauf. Das vorhandene Bild, keins neu malen.',
        ));
    }

    public function testAdjustPlanRewritesCodeRunToImageGeneration(): void
    {
        $out = ImageFollowUpPlan::adjustPlan(
            [
                'version' => 1,
                'language' => 'en',
                'reply_node' => 'n1',
                'tasks' => [[
                    'id' => 'n1',
                    'capability' => 'code_run',
                    'params' => ['script' => 'print("stamp")', 'image' => 'python'],
                ]],
            ],
            'lass es genau so aber mach den zweiten vogel auch fliegend und schreib SYNAPLAN oben rechts',
        );

        self::assertSame('image_generation', $out['tasks'][0]['capability']);
        self::assertSame('reference_images', $out['tasks'][0]['params']['input_mode']);
        self::assertArrayNotHasKey('script', $out['tasks'][0]['params']);
        self::assertStringContainsString('fliegend', $out['tasks'][0]['inputs']['prompt']);
    }

    public function testAdjustPlanLeavesAStampScriptAlone(): void
    {
        $payload = [
            'tasks' => [[
                'id' => 'n1',
                'capability' => 'code_run',
                'params' => ['script' => 'stamp()', 'image' => 'python'],
            ]],
        ];

        $out = ImageFollowUpPlan::adjustPlan(
            $payload,
            'Stempel unten rechts INTERNAL drauf. Das vorhandene Bild, keins neu malen.',
        );

        self::assertSame('code_run', $out['tasks'][0]['capability']);
    }
}
