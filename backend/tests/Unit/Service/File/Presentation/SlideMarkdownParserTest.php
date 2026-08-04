<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File\Presentation;

use App\Service\File\Presentation\PptxTheme;
use App\Service\File\Presentation\SlideBullet;
use App\Service\File\Presentation\SlideBulletMarker;
use App\Service\File\Presentation\SlideMarkdownParser;
use App\Service\File\Presentation\SlideTransitionKind;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1397: a generated presentation used to be one flat text box per
 * section. The parser is what turns the model's markdown into real slide parts,
 * so these tests pin the contract the renderer relies on.
 */
class SlideMarkdownParserTest extends TestCase
{
    private SlideMarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SlideMarkdownParser();
    }

    public function testWithoutDirectiveTheDeckKeepsTheNeutralDefaults(): void
    {
        $deck = $this->parser->parse("# Title\n\n- one");

        $this->assertSame(PptxTheme::Default, $deck->theme);
        $this->assertSame(SlideTransitionKind::None, $deck->transition, 'Transitions must stay opt-in');
    }

    public function testDirectiveSelectsThemeAndTransition(): void
    {
        $deck = $this->parser->parse("{{PPTX:theme=ocean, transition=fade}}\n# Title\n\n- one");

        $this->assertSame(PptxTheme::Ocean, $deck->theme);
        $this->assertSame(SlideTransitionKind::Fade, $deck->transition);
        $this->assertSame('Title', $deck->slides[0]->title, 'The directive must not become slide content');
    }

    public function testDirectiveAcceptsAnimationAsAnAliasForTransition(): void
    {
        $deck = $this->parser->parse("{{PPTX:animation=dissolve}}\n# Title");

        $this->assertSame(SlideTransitionKind::Dissolve, $deck->transition);
    }

    public function testUnknownDirectiveValuesFallBackInsteadOfFailing(): void
    {
        $deck = $this->parser->parse("{{PPTX:theme=neon; transition=explode; layout=weird}}\n# Title");

        $this->assertSame(PptxTheme::Default, $deck->theme);
        $this->assertSame(SlideTransitionKind::None, $deck->transition);
    }

    public function testHeadingsAndHorizontalRulesStartNewSlides(): void
    {
        $deck = $this->parser->parse(<<<'MD'
            # Cover

            ## Second

            - a

            ---

            Third slide without a heading
            MD);

        $this->assertCount(3, $deck->slides);
        $this->assertSame('Cover', $deck->slides[0]->title);
        $this->assertSame('Second', $deck->slides[1]->title);
        $this->assertNull($deck->slides[2]->title);
        $this->assertSame('Third slide without a heading', $deck->slides[2]->bullets[0]->text());
    }

    public function testLevelThreeHeadingStaysInsideTheSlideAsSubHeading(): void
    {
        $deck = $this->parser->parse("# Slide\n\n### Detail\n\n- a");

        $this->assertCount(1, $deck->slides);
        $this->assertTrue($deck->slides[0]->bullets[0]->heading);
        $this->assertSame('Detail', $deck->slides[0]->bullets[0]->text());
        $this->assertSame(SlideBulletMarker::None, $deck->slides[0]->bullets[0]->marker);
    }

    public function testIndentedBulletsBecomeConsecutiveLevels(): void
    {
        $deck = $this->parser->parse(<<<'MD'
            ## Levels

            - top
                - nested
                    - deeper
            - top again
            MD);

        $levels = array_map(
            static fn (SlideBullet $bullet): int => $bullet->level,
            $deck->slides[0]->bullets,
        );
        $this->assertSame([0, 1, 2, 0], $levels, 'Four-space indents must map to consecutive levels');
    }

    public function testDeepIndentIsClampedToTheDeepestRenderableLevel(): void
    {
        $deck = $this->parser->parse("## L\n\n- a\n  - b\n    - c\n      - d\n        - e");

        $bullets = $deck->slides[0]->bullets;
        $this->assertSame(SlideBullet::MAX_LEVEL, $bullets[count($bullets) - 1]->level);
    }

    public function testNumberedListsKeepTheirMarker(): void
    {
        $deck = $this->parser->parse("## Steps\n\n1. first\n2. second");

        $markers = array_map(
            static fn (SlideBullet $bullet): SlideBulletMarker => $bullet->marker,
            $deck->slides[0]->bullets,
        );
        $this->assertSame([SlideBulletMarker::Number, SlideBulletMarker::Number], $markers);
        $this->assertSame('first', $deck->slides[0]->bullets[0]->text());
    }

    public function testInlineMarkdownBecomesStyledRunsInsteadOfBeingStripped(): void
    {
        $deck = $this->parser->parse("## Facts\n\n- **Born:** 2019 in *Munich* with `tuna`");

        $runs = $deck->slides[0]->bullets[0]->runs;
        $this->assertSame('Born:', $runs[0]->text);
        $this->assertTrue($runs[0]->bold);
        $this->assertSame('Munich', $runs[2]->text);
        $this->assertTrue($runs[2]->italic);
        $this->assertSame('tuna', $runs[4]->text);
        $this->assertTrue($runs[4]->monospace);
        $this->assertStringNotContainsString('*', $deck->slides[0]->bullets[0]->text());
    }

    public function testNestedEmphasisCombinesBothStyles(): void
    {
        $deck = $this->parser->parse('## T'."\n\n".'- ***loud***');

        $run = $deck->slides[0]->bullets[0]->runs[0];
        $this->assertSame('loud', $run->text);
        $this->assertTrue($run->bold);
        $this->assertTrue($run->italic);
    }

    public function testUnderscoresInsideWordsAreNotEmphasis(): void
    {
        $deck = $this->parser->parse("## T\n\n- see file_name_here");

        $this->assertSame('see file_name_here', $deck->slides[0]->bullets[0]->text());
    }

    public function testLinksAreReducedToTheirLabel(): void
    {
        $deck = $this->parser->parse("## T\n\n- see [the docs](https://example.com/a)");

        $this->assertSame('see the docs', $deck->slides[0]->bullets[0]->text());
    }

    public function testImageMarkersBecomeSlideImageReferences(): void
    {
        $deck = $this->parser->parse("## Photo\n\n{{IMAGE:file:42}}\n\n- caption");

        $this->assertSame(['file:42'], $deck->slides[0]->imageReferences);
        $this->assertSame('caption', $deck->slides[0]->bullets[0]->text());
        $this->assertStringNotContainsString('{{IMAGE:', $deck->slides[0]->bullets[0]->text());
    }

    public function testMarkdownTableBecomesATable(): void
    {
        $deck = $this->parser->parse(<<<'MD'
            ## Numbers

            | Year | Weight |
            | --- | --- |
            | 2019 | 0.9 kg |
            | 2020 | 3.4 kg |
            MD);

        $table = $deck->slides[0]->tables[0];
        $this->assertSame(['Year', 'Weight'], $table->headers);
        $this->assertSame([['2019', '0.9 kg'], ['2020', '3.4 kg']], $table->rows);
        $this->assertSame([], $deck->slides[0]->bullets, 'Table rows must not leak into the bullet list');
    }

    public function testConsecutiveProseLinesFormOneParagraph(): void
    {
        $deck = $this->parser->parse("## Story\n\nA long sentence\nthat the model wrapped.\n\nA second one.");

        $this->assertCount(2, $deck->slides[0]->bullets);
        $this->assertSame('A long sentence that the model wrapped.', $deck->slides[0]->bullets[0]->text());
    }

    public function testFirstSlideWithOnlyATitleAndStandfirstBecomesACover(): void
    {
        $deck = $this->parser->parse("# The Life of Cat\n\nA short portrait.\n\n## Chapter\n\n- a");

        $this->assertTrue($deck->slides[0]->titleSlide);
        $this->assertFalse($deck->slides[1]->titleSlide);
    }

    public function testFirstSlideWithBulletsKeepsTheContentLayout(): void
    {
        $deck = $this->parser->parse("# Agenda\n\n- one\n- two");

        $this->assertFalse($deck->slides[0]->titleSlide);
    }

    public function testEmptyContentStillYieldsOneSlide(): void
    {
        $deck = $this->parser->parse("   \n\n  ");

        $this->assertCount(1, $deck->slides);
        $this->assertTrue($deck->slides[0]->isEmpty());
    }

    public function testFencedCodeKeepsItsLinesAsMonospaceText(): void
    {
        $deck = $this->parser->parse("## Code\n\n```php\n\$a = 1;\n```");

        $bullet = $deck->slides[0]->bullets[0];
        $this->assertSame('$a = 1;', $bullet->text());
        $this->assertTrue($bullet->runs[0]->monospace);
    }
}
