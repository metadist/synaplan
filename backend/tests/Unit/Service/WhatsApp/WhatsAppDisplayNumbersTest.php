<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\WhatsApp;

use App\Service\WhatsApp\WhatsAppDisplayNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhatsAppDisplayNumbersTest extends TestCase
{
    public function testEmptyRawYieldsNoNumbers(): void
    {
        self::assertSame([], (new WhatsAppDisplayNumbers(''))->list());
        self::assertSame([], (new WhatsAppDisplayNumbers('   '))->list());
    }

    public function testOneE164Number(): void
    {
        self::assertSame(
            [
                [
                    'e164' => '+4915116038214',
                    'wa_me' => 'https://wa.me/4915116038214',
                ],
            ],
            (new WhatsAppDisplayNumbers('+4915116038214'))->list(),
        );
    }

    public function testTwoNumbersAndDropsGarbage(): void
    {
        $raw = '+4915116038214, not-a-number, +1 628 225 3244, +12';

        self::assertSame(
            [
                [
                    'e164' => '+4915116038214',
                    'wa_me' => 'https://wa.me/4915116038214',
                ],
                [
                    'e164' => '+16282253244',
                    'wa_me' => 'https://wa.me/16282253244',
                ],
            ],
            (new WhatsAppDisplayNumbers($raw))->list(),
        );
    }

    public function testDuplicatesAreDropped(): void
    {
        self::assertCount(1, (new WhatsAppDisplayNumbers('+4915116038214,+49 151 16038214'))->list());
    }

    /**
     * @return list<array{0: string}>
     */
    public static function garbageProvider(): array
    {
        return [
            ['abc'],
            ['+'],
            ['123'],
            ['+1234567890123456'],
        ];
    }

    #[DataProvider('garbageProvider')]
    public function testGarbageIsIgnored(string $raw): void
    {
        self::assertSame([], (new WhatsAppDisplayNumbers($raw))->list());
    }
}
