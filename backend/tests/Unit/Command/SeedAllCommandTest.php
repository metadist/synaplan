<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SeedAllCommand;
use App\Seed\SeederInterface;
use App\Seed\SeedResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The entrypoint keeps the container running when app:seed fails, so an abort
 * after one broken step would leave every later step unapplied without anyone
 * noticing. Model retirements run right after the model catalog, which makes
 * them the first casualty.
 */
final class SeedAllCommandTest extends TestCase
{
    private const STEPS = ['models', 'model-retirements', 'prompts', 'module-gates'];

    /** @var list<string> labels of the steps that ran, in order */
    private array $ran = [];

    /**
     * @param array<string, \Throwable> $failing step label => what its seed() throws
     */
    private function command(array $failing = [], ?LoggerInterface $logger = null): SeedAllCommand
    {
        $seeders = [];
        foreach (self::STEPS as $label) {
            $seeder = $this->createStub(SeederInterface::class);
            $seeder->method('label')->willReturn($label);
            $seeder->method('description')->willReturn('BCONFIG');
            $seeder->method('seed')->willReturnCallback(function () use ($label, $failing): SeedResult {
                $this->ran[] = $label;
                if (isset($failing[$label])) {
                    throw $failing[$label];
                }

                return new SeedResult($label, 0, skipped: 1);
            });
            $seeders[] = $seeder;
        }

        return new SeedAllCommand($seeders, $logger ?? new NullLogger());
    }

    public function testEveryStepRunsInOrderAndTheCommandSucceeds(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('All seed steps completed.', $tester->getDisplay());
        self::assertSame(self::STEPS, $this->ran);
    }

    public function testAFailingStepDoesNotStopTheOthersButFailsTheCommand(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Seed step failed', self::callback(static fn (array $context): bool => 'models' === $context['step']));

        $tester = new CommandTester($this->command(
            ['models' => new \RuntimeException('Deadlock found when trying to get lock')],
            $logger,
        ));

        self::assertSame(Command::FAILURE, $tester->execute([]));

        self::assertContains('model-retirements', $this->ran, 'Retirements must still be applied');
        self::assertContains('module-gates', $this->ran, 'Later steps must still run');

        // SymfonyStyle wraps error blocks at the terminal width.
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Deadlock found when trying to get lock', $display);
        self::assertStringContainsString('1 of 4 seed steps failed: models.', $display);
        self::assertStringNotContainsString('All seed steps completed.', $display);
    }

    public function testHelpListsEveryStepWithWhatItWrites(): void
    {
        $help = $this->command()->getHelp();

        self::assertStringContainsString('   1. models', $help);
        self::assertStringContainsString('   4. module-gates', $help);
        self::assertSame(4, substr_count($help, 'BCONFIG'));
    }
}
