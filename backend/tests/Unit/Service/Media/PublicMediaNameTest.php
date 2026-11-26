<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\Media\PublicMediaName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicMediaNameTest extends TestCase
{
    public function testSpeechNameCarriesA128BitToken(): void
    {
        $name = PublicMediaName::speech('mp3');

        $this->assertMatchesRegularExpression('/^tts_[0-9a-f]{32}\.mp3$/', $name);
        $this->assertTrue(PublicMediaName::isUnguessable($name));
        $this->assertNull(PublicMediaName::legacyCreatedAt($name));
    }

    public function testGeneratedNameCarriesA128BitToken(): void
    {
        $name = PublicMediaName::generated(4711, 'gpt-image-1', 'PNG');

        $this->assertMatchesRegularExpression('/^4711_gptimage_[0-9a-f]{32}\.png$/', $name);
        $this->assertTrue(PublicMediaName::isUnguessable($name));
        $this->assertNull(PublicMediaName::legacyCreatedAt($name));
    }

    public function testRemoteSourceNameCarriesA128BitToken(): void
    {
        $name = PublicMediaName::remoteSource(7, 'jpg');

        $this->assertMatchesRegularExpression('/^ai_i2vsrc_7_[0-9a-f]{32}\.jpg$/', $name);
        $this->assertTrue(PublicMediaName::isUnguessable($name));
        $this->assertNull(PublicMediaName::legacyCreatedAt($name));
    }

    public function testTwoNamesForTheSameInputDiffer(): void
    {
        $this->assertNotSame(PublicMediaName::speech('mp3'), PublicMediaName::speech('mp3'));
        $this->assertNotSame(
            PublicMediaName::generated(1, 'openai', 'png'),
            PublicMediaName::generated(1, 'openai', 'png'),
        );
    }

    public function testALabelWithoutLettersAndABadExtensionFallBack(): void
    {
        $name = PublicMediaName::generated(1, '123', '../php');

        $this->assertMatchesRegularExpression('/^1_file_[0-9a-f]{32}\.bin$/', $name);
        $this->assertTrue(PublicMediaName::isUnguessable($name));
    }

    #[DataProvider('guessableNames')]
    public function testANameWithoutAFullTokenIsNotUnguessable(string $filename): void
    {
        $this->assertFalse(PublicMediaName::isUnguessable($filename));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function guessableNames(): iterable
    {
        yield 'old speech name' => ['tts_6927a1b2c3d4e.mp3'];
        yield 'old generated name' => ['123_google_1736189000.mp4'];
        yield 'old published copy' => ['1_document_1736189000847.docx'];
        yield 'old remote source' => ['ai_i2vsrc_1_1736189000_0123456789ab.jpg'];
        yield 'token one digit short' => ['tts_'.str_repeat('a', 31).'.mp3'];
        yield 'token with an upper-case digit' => ['tts_'.str_repeat('A', 32).'.mp3'];
        yield 'token of digits only is a clock value' => ['1_openai_'.str_repeat('1', 31).'.png'];
        yield 'path in front' => ['x/tts_'.str_repeat('a', 32).'.mp3'];
        yield 'second extension' => ['tts_'.str_repeat('a', 32).'.mp3.php'];
        yield 'human filename' => ['meeting.ics'];
        yield 'empty' => [''];
    }

    #[DataProvider('legacyNames')]
    public function testLegacyCreatedAtReadsTheTimeFromAnOldName(string $filename, ?int $expected): void
    {
        $this->assertSame($expected, PublicMediaName::legacyCreatedAt($filename));
    }

    /**
     * @return iterable<string, array{0: string, 1: int|null}>
     */
    public static function legacyNames(): iterable
    {
        yield 'speech from uniqid' => ['tts_'.dechex(1736189000).'c3d4e.mp3', 1736189000];
        yield 'test provider speech' => ['tts_test_'.dechex(1736189000).'c3d4e.mp3', 1736189000];
        yield 'generated media' => ['123_google_1736189000.mp4', 1736189000];
        yield 'generated media, upper-case provider' => ['123_Google_1736189000.mp4', 1736189000];
        yield 'published copy with three more digits' => ['1_document_1736189000847.docx', 1736189000];
        yield 'remote source' => ['ai_i2vsrc_1_1736189000_0123456789ab.jpg', 1736189000];
        yield 'speech with a free suffix' => ['tts_reply.mp3', null];
        yield 'speech with a short suffix' => ['tts_6927a1b2.mp3', null];
        yield 'generated with a short number' => ['1_openai_17361890.png', null];
        yield 'remote source without a time' => ['ai_i2vsrc_1_0123456789ab.jpg', null];
        yield 'generated document' => ['notes_1736189000.docx', null];
        yield 'human filename' => ['meeting.ics', null];
    }
}
