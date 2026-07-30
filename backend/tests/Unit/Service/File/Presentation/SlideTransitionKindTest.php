<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File\Presentation;

use App\Service\File\Presentation\SlideTransitionKind;
use PhpOffice\PhpPresentation\Slide\Transition;
use PHPUnit\Framework\TestCase;

class SlideTransitionKindTest extends TestCase
{
    public function testUnknownOrMissingNameMeansNoTransition(): void
    {
        $this->assertSame(SlideTransitionKind::None, SlideTransitionKind::fromName(null));
        $this->assertSame(SlideTransitionKind::None, SlideTransitionKind::fromName('explode'));
        $this->assertNull(SlideTransitionKind::None->toTransition());
    }

    public function testNameIsMatchedCaseInsensitivelyAndTrimmed(): void
    {
        $this->assertSame(SlideTransitionKind::Fade, SlideTransitionKind::fromName(' Fade '));
        $this->assertSame(SlideTransitionKind::Zoom, SlideTransitionKind::fromName('ZOOM'));
    }

    public function testNamesListsEveryKind(): void
    {
        $this->assertSame(
            ['none', 'fade', 'push', 'wipe', 'dissolve', 'zoom'],
            SlideTransitionKind::names(),
        );
    }

    /**
     * A generated deck must not start playing by itself: the transition advances
     * on click, never on a timer.
     */
    public function testEveryTransitionAdvancesOnClickOnly(): void
    {
        foreach (SlideTransitionKind::cases() as $kind) {
            if (SlideTransitionKind::None === $kind) {
                continue;
            }

            $transition = $kind->toTransition();
            $this->assertInstanceOf(Transition::class, $transition, $kind->value.' must produce a transition');
            $this->assertTrue($transition->hasManualTrigger());
            $this->assertFalse($transition->hasTimeTrigger());
            $this->assertSame(Transition::SPEED_MEDIUM, $transition->getSpeed());
            $this->assertNotNull($transition->getTransitionType());
        }
    }

    public function testFadeMapsToTheOoxmlFadeTransition(): void
    {
        $this->assertSame(
            Transition::TRANSITION_FADE,
            SlideTransitionKind::Fade->toTransition()?->getTransitionType(),
        );
    }
}
