<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Repository\ConfigRepository;
use App\Service\Media\AnonymousMediaGate;
use App\Service\Media\PublicMediaName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnonymousMediaGateTest extends TestCase
{
    private const UPGRADED_AT = 1795000000;

    public function testAnUnguessableNameIsServedWithoutReadingTheConfig(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->expects($this->never())->method('getValue');

        $this->assertTrue((new AnonymousMediaGate($config))->allows(PublicMediaName::speech('mp3')));
    }

    #[DataProvider('legacyNames')]
    public function testAnOldNameIsServedOnlyWhenItPredatesTheUpgrade(string $filename, bool $expected): void
    {
        $this->assertSame($expected, $this->gate((string) self::UPGRADED_AT)->allows($filename));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function legacyNames(): iterable
    {
        $before = self::UPGRADED_AT - 1;
        $after = self::UPGRADED_AT + 3600;

        yield 'speech before' => ['tts_'.dechex($before).'00000.mp3', true];
        yield 'speech at the moment itself' => ['tts_'.dechex(self::UPGRADED_AT).'00000.mp3', false];
        yield 'speech after' => ['tts_'.dechex($after).'00000.mp3', false];
        yield 'generated before' => ['12_openai_'.$before.'.png', true];
        yield 'generated after' => ['12_openai_'.$after.'.png', false];
        yield 'published copy before' => ['1_document_'.$before.'123.docx', true];
        yield 'published copy after' => ['1_document_'.$after.'123.docx', false];
        yield 'remote source before' => ['ai_i2vsrc_1_'.$before.'_0123456789ab.jpg', true];
        yield 'remote source after' => ['ai_i2vsrc_1_'.$after.'_0123456789ab.jpg', false];
        yield 'a document named like generated media, after' => ['2027_budget_'.$after.'.docx', false];
        yield 'never an outbound name' => ['notes_'.$before.'.docx', false];
        yield 'free speech suffix' => ['tts_reply.mp3', false];
    }

    #[DataProvider('unusableConfigValues')]
    public function testNoOldNameIsServedWithoutAReadableMoment(?string $value): void
    {
        $this->assertFalse($this->gate($value)->allows('12_openai_1736189000.png'));
        $this->assertFalse($this->gate($value)->allows('tts_'.dechex(1736189000).'00000.mp3'));
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function unusableConfigValues(): iterable
    {
        yield 'no row' => [null];
        yield 'empty' => [''];
        yield 'not a number' => ['always'];
        yield 'negative' => ['-1'];
        yield 'float' => ['1795000000.5'];
        yield 'far too long' => ['9999999999999'];
    }

    private function gate(?string $value): AnonymousMediaGate
    {
        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturnMap([
            [0, AnonymousMediaGate::CONFIG_GROUP, AnonymousMediaGate::CONFIG_SETTING, $value],
        ]);

        return new AnonymousMediaGate($config);
    }
}
