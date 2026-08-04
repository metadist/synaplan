<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File\Presentation;

use App\Service\File\Presentation\PptxRequestDirectiveResolver;
use App\Service\File\Presentation\PptxTheme;
use App\Service\File\Presentation\SlideMarkdownParser;
use App\Service\File\Presentation\SlideTransitionKind;
use PHPUnit\Framework\TestCase;

final class PptxRequestDirectiveResolverTest extends TestCase
{
    private SlideMarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SlideMarkdownParser();
    }

    public function testAppliesExplicitOptionsWhenModelOmittedDirective(): void
    {
        $content = "# Renewable Energy\n\nOverview";
        $request = 'Create a presentation with an Ocean theme and Fade transitions.';

        $resolved = PptxRequestDirectiveResolver::apply($content, $request);
        $deck = $this->parser->parse($resolved);

        self::assertStringStartsWith('{{PPTX:theme=ocean, transition=fade}}', $resolved);
        self::assertSame(PptxTheme::Ocean, $deck->theme);
        self::assertSame(SlideTransitionKind::Fade, $deck->transition);
    }

    public function testUserRequestOverridesConflictingModelOptions(): void
    {
        $content = "{{PPTX:theme=forest, transition=wipe}}\n# Title";

        $resolved = PptxRequestDirectiveResolver::apply(
            $content,
            'Use the Midnight theme with a Zoom transition.',
        );
        $deck = $this->parser->parse($resolved);

        self::assertSame(1, substr_count($resolved, '{{PPTX:'));
        self::assertSame(PptxTheme::Midnight, $deck->theme);
        self::assertSame(SlideTransitionKind::Zoom, $deck->transition);
    }

    public function testUnspecifiedExistingOptionIsPreserved(): void
    {
        $content = "{{PPTX:theme=forest, transition=wipe}}\n# Title";
        $deck = $this->parser->parse(PptxRequestDirectiveResolver::apply($content, 'Use Fade transitions.'));

        self::assertSame(PptxTheme::Forest, $deck->theme);
        self::assertSame(SlideTransitionKind::Fade, $deck->transition);
    }

    public function testUnrelatedWordsDoNotSelectOptions(): void
    {
        $content = "# Title\n\nA pushy plan for foresting coastal land.";

        self::assertSame(
            $content,
            PptxRequestDirectiveResolver::apply($content, 'Explain the pushy foresting proposal.'),
        );
    }
}
