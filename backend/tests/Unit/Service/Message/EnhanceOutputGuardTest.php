<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\EnhanceOutputGuard;
use PHPUnit\Framework\TestCase;

class EnhanceOutputGuardTest extends TestCase
{
    public function testUnenhanceableTokenIsRefusal(): void
    {
        $this->assertTrue(EnhanceOutputGuard::isRefusalOrNonEnhancement('x', '__UNENHANCEABLE__'));
        $this->assertTrue(EnhanceOutputGuard::isRefusalOrNonEnhancement('x', "  __UNENHANCEABLE__\n"));
    }

    public function testGermanModelRefusalSample(): void
    {
        $input = 'DDJAWjdwj Was geht?';
        $output = <<<'TXT'
Ich kann diesen Text nicht verbessern, da "DDJAWjdwj Was geht?" keine zusammenhängende Aussage ist.

Falls Sie einen Text zur Verbesserung haben, teilen Sie mir bitte den vollständigen Satz mit.
TXT;
        $this->assertTrue(EnhanceOutputGuard::isRefusalOrNonEnhancement($input, $output));
    }

    public function testEnglishMonologueRefusalSample(): void
    {
        $input = 'DDJAWjdwj Was geht?';
        $output = <<<'TXT'
I appreciate you testing my system, but "DDJAWjdwj Was geht?" doesn't contain meaningful text to improve.

If you have actual text you'd like me to refine for grammar, clarity, or completeness, please share it and I'll help.
TXT;
        $this->assertTrue(EnhanceOutputGuard::isRefusalOrNonEnhancement($input, $output));
    }

    public function testNormalShortImprovementIsNotRefusal(): void
    {
        $this->assertFalse(EnhanceOutputGuard::isRefusalOrNonEnhancement('how do i fix this', 'How do I fix this?'));
    }

    public function testEmptyOutputIsRefusal(): void
    {
        $this->assertTrue(EnhanceOutputGuard::isRefusalOrNonEnhancement('hello', ''));
    }
}
