<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Repository\ConfigRepository;
use App\Service\File\CloudFilesConfig;
use PHPUnit\Framework\TestCase;

final class CloudFilesConfigTest extends TestCase
{
    public function testBundledGlyphsStayWhenTheOverrideIsEmpty(): void
    {
        $config = new CloudFilesConfig($this->createStub(ConfigRepository::class), '', '  ', '');

        self::assertSame(['bird' => null, 'bird_eyes' => null], $config->glyphs());
    }

    public function testAWhiteLabelInstallCanReplaceBothGlyphs(): void
    {
        $config = new CloudFilesConfig(
            $this->createStub(ConfigRepository::class),
            '',
            ' https://cdn.example/bird.svg ',
            'https://cdn.example/eyes.svg',
        );

        self::assertSame([
            'bird' => 'https://cdn.example/bird.svg',
            'bird_eyes' => 'https://cdn.example/eyes.svg',
        ], $config->glyphs());
    }
}
