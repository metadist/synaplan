<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\TtsScriptGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TtsScriptGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function echoes(): iterable
    {
        // The reported bug: no speakable payload, the extractor handed the
        // instruction straight back and the MP3 read the user's question.
        yield 'kinderlied request returned verbatim' => [
            'Bring mir mit einem Kinderlied die persischen Zahlen 0 bis 10 bei.',
            'Bring mir mit einem Kinderlied die persischen Zahlen 0 bis 10 bei.',
        ];
        yield 'kinderlied request minus the first word' => [
            'Mit einem Kinderlied die persischen Zahlen 0 bis 10 bei',
            'Bring mir mit einem Kinderlied die persischen Zahlen 0 bis 10 bei.',
        ];
        yield 'english teach request with punctuation and casing changed' => [
            'teach me the first ten persian numbers in a childrens song',
            "Teach me the first ten Persian numbers in a children's song!",
        ];
        yield 'empty script' => ['   ', 'Sing me a song about the sea'];
        yield 'request wrapped in a leading Please' => [
            'Please teach me the first ten Persian numbers with a song',
            'teach me the first ten Persian numbers with a song',
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legitimateScripts(): iterable
    {
        yield 'colon-delimited payload extracted' => [
            'Guten Morgen zusammen.',
            'Lies mir bitte folgenden Text als Sprachnachricht vor: Guten Morgen zusammen.',
        ];
        yield 'quoted payload extracted' => [
            'Good morning!',
            "Read this aloud: 'Good morning!'",
        ];
        yield 'long colon payload that is most of the message' => [
            'The quick brown fox jumps over the lazy dog and keeps running through the forest',
            'Read aloud: The quick brown fox jumps over the lazy dog and keeps running through the forest',
        ];
        yield 'short greeting extracted from a longer instruction' => [
            'Hallo',
            'Erstelle eine Audio wo du Hallo sagst',
        ];
        yield 'one word from a short message' => ['Hallo Welt', 'Sage Hallo Welt'];
        yield 'content written for the request' => [
            'Sefr ist die Null, so fängt es an. Yek ist eins, das kann jeder Mann. Do ist zwei, se ist drei, chahar ist vier, sing mit dabei!',
            'Bring mir mit einem Kinderlied die persischen Zahlen 0 bis 10 bei.',
        ];
        yield 'poem written for a read-aloud request' => [
            'Leise fällt der Regen nieder, tropft aufs Dach und singt uns Lieder.',
            'Lies mir ein kurzes Gedicht über den Regen vor',
        ];
        yield 'empty request never counts as echo' => ['Hello there', ''];
    }

    #[DataProvider('echoes')]
    public function testDetectsScriptsThatRepeatTheRequest(string $script, string $userText): void
    {
        self::assertTrue(TtsScriptGuard::echoesInstruction($script, $userText));
    }

    #[DataProvider('legitimateScripts')]
    public function testAcceptsExtractedOrWrittenScripts(string $script, string $userText): void
    {
        self::assertFalse(TtsScriptGuard::echoesInstruction($script, $userText));
    }
}
