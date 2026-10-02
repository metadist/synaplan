<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Stt;

use App\Service\Stt\SpeechLanguageContext;
use App\Service\Stt\TranscriptionLanguageDecider;
use PHPUnit\Framework\TestCase;

final class TranscriptionLanguageDeciderTest extends TestCase
{
    public function testLanguageOutsideTheExpectedSetUsesTheSecondTranscript(): void
    {
        $calls = [];
        $result = (new TranscriptionLanguageDecider())->transcribe(
            function (?string $hint) use (&$calls): array {
                $calls[] = $hint;
                if (null === $hint) {
                    return ['text' => 'garbage', 'language' => 'russian', 'duration' => 1.0];
                }

                return ['text' => 'Wie geht es dir?', 'language' => 'german', 'duration' => 1.0];
            },
            ['de', 'en'],
            'de',
        );

        self::assertSame([null, 'de'], $calls);
        self::assertSame('Wie geht es dir?', $result['text']);
        self::assertSame('de', $result['language']);
        self::assertSame('ru', $result['first_detected_language']);
    }

    public function testLanguageInsideTheExpectedSetIsOneCall(): void
    {
        $calls = 0;
        $result = (new TranscriptionLanguageDecider())->transcribe(
            function (?string $hint) use (&$calls): array {
                ++$calls;
                self::assertNull($hint);

                return ['text' => 'Hello', 'language' => 'en', 'duration' => 1.0];
            },
            ['de', 'en'],
            'de',
        );

        self::assertSame(1, $calls);
        self::assertSame('Hello', $result['text']);
        self::assertArrayNotHasKey('retried_with_language', $result);
    }

    public function testFailedRetryKeepsTheFirstTranscript(): void
    {
        $result = (new TranscriptionLanguageDecider())->transcribe(
            function (?string $hint): array {
                if (null !== $hint) {
                    throw new \RuntimeException('provider down');
                }

                return ['text' => 'first', 'language' => 'ru', 'duration' => 1.0];
            },
            ['de'],
            'de',
        );

        self::assertSame('first', $result['text']);
        self::assertSame('ru', $result['language']);
    }

    public function testUnknownDetectionRetriesWhenAHintExists(): void
    {
        $calls = [];
        $result = (new TranscriptionLanguageDecider())->transcribe(
            function (?string $hint) use (&$calls): array {
                $calls[] = $hint;
                if (null === $hint) {
                    return ['text' => '', 'language' => 'unknown', 'duration' => 1.0];
                }

                return ['text' => 'Hallo', 'language' => 'de', 'duration' => 1.0];
            },
            ['de', 'en'],
            'de',
        );

        self::assertSame([null, 'de'], $calls);
        self::assertSame('Hallo', $result['text']);
        self::assertSame('de', $result['language']);
    }

    public function testAccountLanguageHintsAndEnglishStaysExpected(): void
    {
        $speech = SpeechLanguageContext::fromSignals(
            ['channel_language' => 'de'],
            null,
            null,
            ['ru', 'de'],
        );

        self::assertSame(['de', 'en'], $speech['expected']);
        self::assertSame('de', $speech['hint']);
    }

    public function testExplicitApiLanguageIsTheRetryHint(): void
    {
        $speech = SpeechLanguageContext::fromSignals(
            ['channel_language' => 'de'],
            'fr',
            'de',
            [],
        );

        self::assertContains('fr', $speech['expected']);
        self::assertContains('en', $speech['expected']);
        self::assertSame('fr', $speech['hint']);
    }
}
