<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchOutcome;
use PHPUnit\Framework\TestCase;

final class ResearchOutcomeTest extends TestCase
{
    public function testEveryCodeIsAPlainSentence(): void
    {
        $samples = [
            ResearchOutcome::COMPLETE => ['statements' => 35, 'supported' => 33],
            ResearchOutcome::COMPLETE_PARTIAL => ['open' => 2, 'points' => 5],
            ResearchOutcome::COMPLETE_SNIPPETS_ONLY => [],
            ResearchOutcome::STOPPED_BY_USER => [],
            ResearchOutcome::STOPPED_BUDGET => ['covered' => 3, 'points' => 5],
            ResearchOutcome::STOPPED_WAITING => [],
            ResearchOutcome::FAILED_NO_SOURCES => [],
            ResearchOutcome::FAILED_ROLE_UNAVAILABLE => ['role' => 'reader'],
            ResearchOutcome::FAILED_MODEL_ERROR => ['role' => 'checker'],
            ResearchOutcome::FAILED_INTERRUPTED => [],
            ResearchOutcome::STOPPED_BY_ADMIN => [],
        ];

        foreach ($samples as $code => $params) {
            $text = (new ResearchOutcome($code, $params))->text();
            self::assertNotSame('', $text);
            self::assertStringNotContainsString('HTTP', $text);
            self::assertStringNotContainsString('Exception', $text);
            self::assertStringNotContainsString('stack', $text);
            self::assertDoesNotMatchRegularExpression('/\b[45]\d\d\b/', $text);
        }
    }

    public function testStopDoesNotPretendTheWorkWasFree(): void
    {
        $text = (new ResearchOutcome(ResearchOutcome::STOPPED_BY_USER))->text();

        self::assertStringContainsString('No new work was started', $text);
        self::assertStringContainsString('may still count', $text);
        self::assertStringNotContainsString('Nothing was charged', $text);
    }

    public function testAMissingRoleDoesNotLeakIntoTheSentence(): void
    {
        $text = (new ResearchOutcome(ResearchOutcome::FAILED_ROLE_UNAVAILABLE, ['role' => "reader\nException"]))->text();

        self::assertStringContainsString('The selected model', $text);
        self::assertStringContainsString('Nothing was charged.', $text);
        self::assertStringNotContainsString('Exception', $text);
    }

    public function testTheReadingRoleIsNamedInPlainWords(): void
    {
        $text = (new ResearchOutcome(ResearchOutcome::FAILED_ROLE_UNAVAILABLE, ['role' => 'reader']))->text();

        self::assertSame(
            'The reading model of this project is not available, and no other model in this project can stand in. Nothing was charged.',
            $text,
        );
    }
}
