<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Prompt;

use App\Service\Prompt\UserUtteranceLanguage;
use PHPUnit\Framework\TestCase;

final class UserUtteranceLanguageTest extends TestCase
{
    public function testGermanBeatsAnEnglishUiFallback(): void
    {
        self::assertSame('de', UserUtteranceLanguage::detect(
            'stelle mir einen termin für eine bahnfahrt morgen in meinen kalender',
            'en',
        ));
    }

    public function testEnglishStaysEnglish(): void
    {
        self::assertSame('en', UserUtteranceLanguage::detect(
            'please add a meeting to my calendar tomorrow at 10',
            'de',
        ));
    }
}
