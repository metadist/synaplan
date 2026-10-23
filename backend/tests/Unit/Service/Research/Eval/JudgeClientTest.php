<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Eval;

use App\AI\Service\AiFacade;
use App\Service\Research\Eval\JudgeClient;
use PHPUnit\Framework\TestCase;

final class JudgeClientTest extends TestCase
{
    public function testTheFactPromptNamesTheClaimAndNoArm(): void
    {
        $client = new JudgeClient($this->createStub(AiFacade::class), dirname(__DIR__, 5));
        $prompt = $client->factPrompt('The Act is 2024/1689.', 'A report.');

        $this->assertStringContainsString('The Act is 2024/1689.', $prompt);
        $this->assertStringContainsString('A report.', $prompt);
        $this->assertStringNotContainsString('b0', $prompt);
        $this->assertStringNotContainsString('{assertion}', $prompt);
    }

    public function testAJudgeWordIsKeptAndAnythingElseIsUncertain(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects($this->exactly(2))->method('chat')->willReturnOnConsecutiveCalls(
            ['content' => 'present'],
            ['content' => 'I think maybe'],
        );
        $client = new JudgeClient($ai, dirname(__DIR__, 5));

        $present = $client->judgeFact('Claim', 'Report', ['openai:example:chat'], 4);
        $other = $client->judgeFact('Claim', 'Report', ['openai:example:chat'], 4);

        $this->assertSame('present', $present[0]['verdict']);
        $this->assertSame('uncertain', $other[0]['verdict']);
    }
}
