<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Eval;

use App\Service\Eval\SummaryEvalScorer;
use PHPUnit\Framework\TestCase;

class SummaryEvalScorerTest extends TestCase
{
    private SummaryEvalScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new SummaryEvalScorer();
    }

    private function compliantEnglishSummary(): string
    {
        return "## Topic\nThe office rent increase from Meridian Properties.\n"
            ."## User position / goal\nThe user wants to verify the calculation and keep the office.\n"
            ."## Decisions & constraints\n- The day-pass budget was cut to cover the increase and that decision is final.\n"
            ."## Open questions\n- The exact lease expiry date is still unknown and should be checked from the contract.";
    }

    public function testCompliantSummaryPasses(): void
    {
        $score = $this->scorer->score(
            $this->compliantEnglishSummary(),
            4000,
            ['Meridian', 're:day-?pass'],
            ['lawsuit'],
            'en',
        );

        self::assertTrue($score->sizeOk);
        self::assertSame([], $score->missingRequired);
        self::assertSame([], $score->forbiddenHits);
        self::assertTrue($score->structureOk);
        self::assertTrue($score->languageOk);
        self::assertSame('en', $score->detectedLanguage);
        self::assertTrue($score->passed());
        self::assertSame('', $score->problems());
    }

    public function testOversizedSummaryFailsSizeOnly(): void
    {
        $summary = "## Topic\n".str_repeat('word and the with that from this are ', 20);

        $score = $this->scorer->score($summary, 100, [], [], null);

        self::assertFalse($score->sizeOk);
        self::assertFalse($score->passed());
        self::assertStringContainsString('over cap', $score->problems());
    }

    public function testEmptySummaryFails(): void
    {
        $score = $this->scorer->score('   ', 4000, ['anything'], [], null);

        self::assertFalse($score->sizeOk);
        self::assertFalse($score->structureOk);
        self::assertSame(['anything'], $score->missingRequired);
        self::assertFalse($score->passed());
    }

    public function testMissingRequiredSubstringIsReported(): void
    {
        $score = $this->scorer->score($this->compliantEnglishSummary(), 4000, ['Meridian', 'Unmentioned GmbH'], [], null);

        self::assertSame(['Unmentioned GmbH'], $score->missingRequired);
        self::assertFalse($score->passed());
    }

    public function testRequiredSubstringIsCaseInsensitive(): void
    {
        $score = $this->scorer->score($this->compliantEnglishSummary(), 4000, ['meridian PROPERTIES'], [], null);

        self::assertSame([], $score->missingRequired);
    }

    public function testRegexProbesMatchAlternatives(): void
    {
        $summary = "## Topic\nThe rent rises to 1,620 euros in September.";

        $score = $this->scorer->score($summary, 4000, ['re:1[.,\s]?620', 're:September|09-01'], [], null);

        self::assertSame([], $score->missingRequired);
    }

    public function testForbiddenProbeHitFailsTheCase(): void
    {
        $summary = "## Topic\nThe newsletter will be sent via Mailchimp every Tuesday.";

        $score = $this->scorer->score($summary, 4000, [], ['re:Mailchimp|SendGrid'], null);

        self::assertSame(['re:Mailchimp|SendGrid'], $score->forbiddenHits);
        self::assertFalse($score->passed());
        self::assertStringContainsString('forbidden', $score->problems());
    }

    public function testPreambleBeforeFirstHeadingFailsStructure(): void
    {
        $summary = "Here is the summary you asked for:\n## Topic\nSomething.";

        $score = $this->scorer->score($summary, 4000, [], [], null);

        self::assertFalse($score->structureOk);
        self::assertFalse($score->passed());
    }

    /**
     * The prompt mandates `## <heading>` sections — a lone `# Title` first
     * line is NOT compliant even when a `## ` section follows later.
     */
    public function testSingleHashFirstLineFailsStructure(): void
    {
        $summary = "# Summary\n## Topic\nSomething.";

        $score = $this->scorer->score($summary, 4000, [], [], null);

        self::assertFalse($score->structureOk);
        self::assertFalse($score->passed());
    }

    public function testEmptySummaryIsReportedAsEmptyNotOverCap(): void
    {
        $score = $this->scorer->score('', 4000, [], [], null);

        self::assertStringContainsString('empty summary', $score->problems());
        self::assertStringNotContainsString('over cap', $score->problems());
    }

    public function testGermanSummaryDetectedAndMatchesExpectation(): void
    {
        $summary = "## Topic\nDie Miete für das Büro wird erhöht und der Umbau ist entschieden.\n"
            ."## Decisions & constraints\n- Die Tischlerei Brandt liefert die Möbel und das Budget wird nicht überschritten.";

        $score = $this->scorer->score($summary, 4000, [], [], 'de');

        self::assertSame('de', $score->detectedLanguage);
        self::assertTrue($score->languageOk);
    }

    public function testLanguageMismatchFails(): void
    {
        $score = $this->scorer->score($this->compliantEnglishSummary(), 4000, [], [], 'de');

        self::assertSame('en', $score->detectedLanguage);
        self::assertFalse($score->languageOk);
        self::assertFalse($score->passed());
        self::assertStringContainsString('language', $score->problems());
    }

    public function testCyrillicSummaryDetectedAsRussian(): void
    {
        $summary = "## Topic\nРезервное копирование сервера восстановлено, ротация архивов настроена.\n"
            ."## Open questions\n- Выбор облачного провайдера ещё не решён.";

        $score = $this->scorer->score($summary, 4000, ['re:резервн'], [], 'ru');

        self::assertSame('ru', $score->detectedLanguage);
        self::assertTrue($score->languageOk);
        self::assertTrue($score->passed());
    }

    public function testUndetectableLanguageIsNeutralNotAFailure(): void
    {
        // Too short for a confident stopword verdict — must not fail the case.
        $summary = "## Topic\nRent: 1620 EUR.";

        $score = $this->scorer->score($summary, 4000, [], [], 'de');

        self::assertNull($score->detectedLanguage);
        self::assertNull($score->languageOk);
        self::assertTrue($score->passed());
    }
}
