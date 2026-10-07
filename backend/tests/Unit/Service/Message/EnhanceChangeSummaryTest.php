<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\EnhanceChangeSummary;
use PHPUnit\Framework\TestCase;

final class EnhanceChangeSummaryTest extends TestCase
{
    public function testIdenticalTextIsUnchanged(): void
    {
        self::assertSame(
            EnhanceChangeSummary::UNCHANGED,
            EnhanceChangeSummary::code('Write a poem about cats.', 'Write a poem about cats.'),
        );
    }

    public function testCaseAndPunctuationOnlyIsCapitalized(): void
    {
        self::assertSame(
            EnhanceChangeSummary::CAPITALIZED,
            EnhanceChangeSummary::code('write a poem about cats', 'Write a poem about cats.'),
        );
    }

    public function testARealRewriteIsRewritten(): void
    {
        self::assertSame(
            EnhanceChangeSummary::REWRITTEN,
            EnhanceChangeSummary::code(
                'write a poem about cats',
                'Write a four-line poem about a cat watching rain.',
            ),
        );
    }
}
