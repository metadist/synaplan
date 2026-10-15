<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Model;

use App\Service\Model\PickerCapabilities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PickerCapabilitiesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function tags(): iterable
    {
        yield 'chat also sorts and analyses, but is not a memory model' => ['CHAT', [], ['CHAT', 'SORT', 'ANALYZE']];
        yield 'memory tag' => ['MEM', [], ['MEM']];
        yield 'embedding alias' => ['EMBEDDING', [], ['VECTORIZE']];
        yield 'vision alias' => ['VISION', [], ['PIC2TEXT']];
        yield 'image without editing' => ['TEXT2PIC', [], ['TEXT2PIC']];
        yield 'image with editing' => ['IMAGE', ['pic2pic'], ['TEXT2PIC', 'PIC2PIC']];
        yield 'text to video' => ['TEXT2VID', [], ['TEXT2VID']];
        yield 'image to video stays out of text to video' => ['VIDEO', ['image2video'], ['IMG2VID']];
        yield 'transcription alias' => ['TRANSCRIPTION', [], ['SOUND2TEXT']];
        yield 'speech' => ['TTS', [], ['TEXT2SOUND']];
        yield 'live voice has its own picker' => ['VOICE2VOICE', [], ['VOICE_LIVE']];
    }

    /**
     * @param list<string> $features
     * @param list<string> $expected
     */
    #[DataProvider('tags')]
    public function testATagLandsInItsPickers(string $tag, array $features, array $expected): void
    {
        self::assertSame($expected, PickerCapabilities::forTag($tag, $features));
    }

    public function testAnUnknownTagIsOfferedEverywhere(): void
    {
        self::assertSame(PickerCapabilities::ALL, PickerCapabilities::forTag('SOMETHING_NEW'));
    }

    public function testGroupingKeepsEveryPickerAndTheRowOrder(): void
    {
        $first = ['id' => 1, 'tag' => 'CHAT', 'features' => []];
        $second = ['id' => 2, 'tag' => 'CHAT', 'features' => []];
        $image = ['id' => 3, 'tag' => 'TEXT2PIC', 'features' => ['pic2pic']];

        $grouped = PickerCapabilities::group([$first, $image, $second]);

        self::assertSame(PickerCapabilities::ALL, array_keys($grouped));
        self::assertSame([$first, $second], $grouped['CHAT']);
        self::assertSame([$first, $second], $grouped['SORT']);
        self::assertSame([$image], $grouped['PIC2PIC']);
        self::assertSame([], $grouped['MEM']);
    }
}
