<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\DigestBackfillCommand;
use App\Service\Digest\MessageDigestRunner;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class DigestBackfillCommandTest extends TestCase
{
    private MessageDigestRunner&MockObject $runner;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->runner = $this->createMock(MessageDigestRunner::class);
        $this->tester = new CommandTester(
            new DigestBackfillCommand($this->runner, new LockFactory(new InMemoryStore())),
        );
    }

    public function testDryRunListsEveryProposedTitle(): void
    {
        $this->runner->expects(self::once())
            ->method('backfill')
            ->with(7, self::anything(), true, 3)
            ->willReturn($this->summary([
                ['user_id' => 7, 'title' => 'invoice INV-2044 to Nordwerk AG, 1,280.00 EUR', 'message_id' => 58],
            ]));

        $exitCode = $this->tester->execute(['--user' => '7', '--max-batches' => '3', '--dry-run' => true]);

        self::assertSame(0, $exitCode);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Proposed title', $display);
        self::assertStringContainsString('invoice INV-2044 to Nordwerk AG, 1,280.00 EUR', $display);
        self::assertStringContainsString('58', $display);
    }

    public function testDryRunSaysSoWhenTheModelPickedNothing(): void
    {
        $this->runner->expects(self::once())->method('backfill')->willReturn($this->summary([]));

        $this->tester->execute(['--user' => '7', '--dry-run' => true]);

        self::assertStringContainsString('the model picked no messages', $this->tester->getDisplay());
    }

    public function testRealRunPrintsOnlyTheSummary(): void
    {
        $this->runner->expects(self::once())->method('backfill')->willReturn($this->summary([]));

        $this->tester->execute(['--user' => '7']);

        $display = $this->tester->getDisplay();
        self::assertStringNotContainsString('Proposed title', $display);
        self::assertStringNotContainsString('picked no messages', $display);
        self::assertStringContainsString('Digest backfill', $display);
    }

    /**
     * @param list<array{user_id: int, title: string, message_id: int}> $proposals
     *
     * @return array{users: int, skipped_users: int, batches: int, created: int, scanned: int, failed_batches: int, aborted: bool, abort_reason: ?string, skipped_budget: int, proposals: list<array{user_id: int, title: string, message_id: int}>}
     */
    private function summary(array $proposals): array
    {
        return [
            'users' => 1,
            'skipped_users' => 0,
            'batches' => 1,
            'created' => 0,
            'scanned' => 5,
            'failed_batches' => 0,
            'aborted' => false,
            'abort_reason' => null,
            'skipped_budget' => 0,
            'proposals' => $proposals,
        ];
    }
}
