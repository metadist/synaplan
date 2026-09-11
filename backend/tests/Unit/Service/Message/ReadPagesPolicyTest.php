<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\ReadPagesPolicy;
use PHPUnit\Framework\TestCase;

final class ReadPagesPolicyTest extends TestCase
{
    public function testHonorsAnExplicitZeroVote(): void
    {
        $this->assertSame(0, ReadPagesPolicy::pagesToRead(0, false, false));
        $this->assertSame(0, ReadPagesPolicy::pagesToRead(0, false, true));
    }

    public function testHonorsTwoAndThree(): void
    {
        $this->assertSame(2, ReadPagesPolicy::pagesToRead(2, false, false));
        $this->assertSame(3, ReadPagesPolicy::pagesToRead(3, false, false));
    }

    public function testPastedLinksSkipDumpsUnlessTheSorterAsked(): void
    {
        $this->assertSame(0, ReadPagesPolicy::pagesToRead(null, true, false));
        $this->assertSame(0, ReadPagesPolicy::pagesToRead(0, true, true));
        $this->assertSame(3, ReadPagesPolicy::pagesToRead(3, true, false));
    }

    public function testOmittedVoteDefaultsToTwoWhileASearchIsRunning(): void
    {
        $this->assertSame(2, ReadPagesPolicy::pagesToRead(null, false, false));
        $this->assertSame(2, ReadPagesPolicy::pagesToRead(null, false, true));
    }

    public function testClampRoundsOneUpAndCapsAtThree(): void
    {
        $this->assertSame(0, ReadPagesPolicy::clamp(-1));
        $this->assertSame(0, ReadPagesPolicy::clamp(0));
        $this->assertSame(2, ReadPagesPolicy::clamp(1));
        $this->assertSame(2, ReadPagesPolicy::clamp(2));
        $this->assertSame(3, ReadPagesPolicy::clamp(3));
        $this->assertSame(3, ReadPagesPolicy::clamp(8));
    }
}
