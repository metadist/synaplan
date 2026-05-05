<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Prompt;

use App\Service\Prompt\LanguageDirectiveBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Locks down the contract of the prompt fragment that replaces our former
 * regex-based response sanitizer.
 *
 * The builder must satisfy two properties for every directive:
 *
 *  1. It tells the model which language to use, in plain prose (no
 *     `**IMPORTANT**` / bracketed-annotation framing). Models routinely
 *     mirror their loudest-formatted instructions back at the user, so
 *     phrasing the directive as ordinary text is itself part of the
 *     mitigation.
 *
 *  2. It includes an explicit anti-echo clause that names the failure
 *     modes we observed in production ("[Reply in X]", "[Language: X]",
 *     "Note: ...") so the model is far less likely to emit them.
 */
class LanguageDirectiveBuilderTest extends TestCase
{
    public function testAutoDirectiveAsksForLanguageMatchingAndForbidsMetaCommentary(): void
    {
        $directive = LanguageDirectiveBuilder::buildAutoDirective();

        $this->assertStringContainsString('same language the user writes in', $directive);
        $this->assertAntiEchoClausePresent($directive);
        $this->assertDoesNotContainBracketedAnnotation($directive);
    }

    public function testForLanguageResolvesIsoCodeToFullName(): void
    {
        $directive = LanguageDirectiveBuilder::buildForLanguage('de');

        $this->assertStringContainsString('German', $directive);
        $this->assertStringNotContainsString("'de'", $directive);
        $this->assertAntiEchoClausePresent($directive);
    }

    public function testForLanguageFallsBackToRawValueForUnknownCode(): void
    {
        // Mirrors the previous inline behaviour: never throw on an unknown
        // language code, even if the upstream classifier ever invents one.
        $directive = LanguageDirectiveBuilder::buildForLanguage('xx');

        $this->assertStringContainsString('xx', $directive);
        $this->assertAntiEchoClausePresent($directive);
    }

    public function testWidgetPreambleStartsInApplicationLanguageButYieldsToVisitor(): void
    {
        $preamble = LanguageDirectiveBuilder::buildWidgetPreamble('de');

        $this->assertStringContainsString('begin in German', $preamble);
        $this->assertStringContainsString('switch to whichever language', $preamble);
        $this->assertAntiEchoClausePresent($preamble);
    }

    public function testWidgetPreambleFallsBackToEnglishForUnknownLanguage(): void
    {
        $preamble = LanguageDirectiveBuilder::buildWidgetPreamble('zz');

        $this->assertStringContainsString('English', $preamble);
    }

    public function testNoneOfTheDirectivesUseTheLeakProneFormatting(): void
    {
        // The directive used to read `**IMPORTANT: ... [foo]**`. Models echo
        // bold-bracketed instructions back at the user disproportionately
        // often. Guard against accidentally re-introducing that framing.
        foreach ([
            LanguageDirectiveBuilder::buildAutoDirective(),
            LanguageDirectiveBuilder::buildForLanguage('en'),
            LanguageDirectiveBuilder::buildWidgetPreamble('en'),
        ] as $fragment) {
            $this->assertStringNotContainsString('**IMPORTANT', $fragment);
            $this->assertStringNotContainsString('**CRITICAL', $fragment);
        }
    }

    private function assertAntiEchoClausePresent(string $fragment): void
    {
        $this->assertStringContainsString('Do not acknowledge this language instruction', $fragment);
        $this->assertStringContainsString('[Reply in X]', $fragment);
        $this->assertStringContainsString('[Language: X]', $fragment);
    }

    private function assertDoesNotContainBracketedAnnotation(string $fragment): void
    {
        // The directive itself must not include the very pattern it tells
        // the model not to emit (otherwise a literal mirror-copy is exactly
        // what the leak looks like). We exempt the example tokens above
        // because they are *inside quotes* in the anti-echo clause.
        $exampleBytes = ['"[Reply in X]"', '"[Language: X]"', '"Note: responding in X"'];
        $stripped = str_replace($exampleBytes, '', $fragment);
        $this->assertDoesNotMatchRegularExpression('/\[\s*(?:Reply|Respond|Language|Lang)\b/i', $stripped);
    }
}
