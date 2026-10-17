<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Classification;

use App\Entity\Message;
use App\Service\Message\Classification\ConfidentLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfidentLanguageTest extends TestCase
{
    #[DataProvider('texts')]
    public function testADistinctiveWordNamesTheLanguage(string $text, ?string $language): void
    {
        self::assertSame($language, ConfidentLanguage::of($this->message('NN'), $text));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function texts(): iterable
    {
        yield 'German, punctuation hugging the word' => ['wer bist du?', 'de'];
        yield 'English' => ['how are you today', 'en'];
        yield 'French' => ['bonjour, comment ça va', 'fr'];
        yield 'Spanish' => ['hola, gracias', 'es'];
        yield 'Italian' => ['ciao, grazie mille', 'it'];
        yield 'no signal' => ['ok', null];
    }

    public function testWithoutASignalThePinnedLanguageAnswersButNeverAnUnknownOne(): void
    {
        self::assertSame(
            ['de', null, null],
            [
                ConfidentLanguage::of($this->message('DE'), 'ok'),
                ConfidentLanguage::of($this->message('NN'), 'ok'),
                ConfidentLanguage::of($this->message('deu'), 'ok'),
            ],
        );
    }

    private function message(string $language): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getLanguage')->willReturn($language);

        return $message;
    }
}
