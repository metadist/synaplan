<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Summary;

use App\Service\Summary\SummaryRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SummaryRequestTest extends TestCase
{
    #[DataProvider('sources')]
    public function testTheSourceIsATextOrAFileExactlyOnce(mixed $payload, ?string $error): void
    {
        if (null !== $error) {
            $this->expectExceptionObject(new \InvalidArgumentException($error));
        }

        $request = SummaryRequest::fromPayload($payload);

        self::assertTrue(null !== $request->text xor null !== $request->fileId);
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function sources(): iterable
    {
        yield 'a text' => [['text' => '  A document. '], null];
        yield 'a file id' => [['fileId' => 42], null];
        yield 'a file id as digits' => [['fileId' => '42'], null];
        yield 'nothing' => [[], 'Either text or fileId is required'];
        yield 'a blank text' => [['text' => '   '], 'Either text or fileId is required'];
        yield 'not json' => [null, 'Either text or fileId is required'];
        yield 'both' => [['text' => 'A document.', 'fileId' => 1], 'Provide either text or fileId, not both'];
    }

    public function testTheOptionsHaveDefaultsAndAreCheckedOnRequest(): void
    {
        $defaults = SummaryRequest::fromPayload(['text' => 'A document.']);
        self::assertSame(['A document.', 'abstractive', 'medium', 'en', ['main-ideas', 'key-facts'], null], [$defaults->text, $defaults->summaryType, $defaults->length, $defaults->outputLanguage, $defaults->focusAreas, $defaults->optionsError()]);

        self::assertSame('Invalid summary type. Must be: abstractive, extractive, bullet-points, translation', SummaryRequest::fromPayload(['text' => 'x', 'summaryType' => 'poem'])->optionsError());
        self::assertSame('Invalid length. Must be: short, medium, long, custom', SummaryRequest::fromPayload(['text' => 'x', 'length' => 7])->optionsError());
    }

    public function testTheMessageCarriesTheOptionsAndTheDocument(): void
    {
        $message = SummaryRequest::fromPayload(['text' => 'x', 'length' => 'custom', 'customLength' => 80, 'outputLanguage' => 'de', 'focusAreas' => ['numbers-dates']])->message('The document.');

        self::assertSame("Configuration:\n- Summary Type: abstractive\n- Target Length: 80 words\n- Output Language: de\n- Focus Areas: numbers-dates\n\nDocument to summarize:\n\nThe document.", $message);
        self::assertStringContainsString('- Target Length: 50-150 words', SummaryRequest::fromPayload(['text' => 'x', 'length' => 'short'])->message('d'));
        self::assertStringContainsString('- Target Length: 200-500 words', SummaryRequest::fromPayload(['text' => 'x'])->message('d'));
    }

    public function testATranslationAsksForTheWholeDocumentInTheTargetLanguage(): void
    {
        $request = SummaryRequest::fromPayload(['text' => 'x', 'summaryType' => 'translation', 'outputLanguage' => 'fr']);

        self::assertNull($request->optionsError());
        self::assertSame(SummaryRequest::TRANSLATION_PROMPT, $request->systemPrompt('summary prompt'));
        self::assertSame(0.2, $request->temperature());
        self::assertSame("Target language: fr\n\nDocument to translate:\n\nThe document.", $request->message('The document.'));
    }

    public function testASummaryKeepsItsPromptAndTemperature(): void
    {
        $request = SummaryRequest::fromPayload(['text' => 'x']);

        self::assertSame('summary prompt', $request->systemPrompt('summary prompt'));
        self::assertSame(0.5, $request->temperature());
    }
}
