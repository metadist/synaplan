<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SeedAllCommand;
use App\Seed\ModelRetirementSeeder;
use App\Seed\ModelSeeder;
use App\Seed\ModuleGateSeeder;
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
    /** @var list<class-string> seeders in the order they ran */
    private array $ran = [];

    /**
     * Builds the command with one double per seeder, read from the constructor
     * so a newly added step is covered without touching this test.
     *
     * @param array<class-string, \Throwable> $failing seeder class => what its seed() throws
     */
    private function command(array $failing = [], ?LoggerInterface $logger = null): SeedAllCommand
    {
        $class = new \ReflectionClass(SeedAllCommand::class);
        $constructor = $class->getConstructor();
        self::assertNotNull($constructor);

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            /** @var class-string $dependency */
            $dependency = $type->getName();

            if (LoggerInterface::class === $dependency) {
                $arguments[] = $logger ?? new NullLogger();
                continue;
            }

            $seeder = $this->createStub($dependency);
            $seeder->method('seed')->willReturnCallback(function () use ($dependency, $failing): SeedResult {
                $this->ran[] = $dependency;
                if (isset($failing[$dependency])) {
                    throw $failing[$dependency];
                }

                return new SeedResult($dependency, 0, skipped: 1);
            });
            $arguments[] = $seeder;
        }

        return $class->newInstanceArgs($arguments);
    }

    public function testEveryStepRunsAndTheCommandSucceeds(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('All seed steps completed.', $tester->getDisplay());
        self::assertContains(ModelRetirementSeeder::class, $this->ran);
        self::assertContains(ModuleGateSeeder::class, $this->ran);
    }

    public function testAFailingStepDoesNotStopTheOthersButFailsTheCommand(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Seed step failed', self::callback(static fn (array $context): bool => 'models' === $context['step']));

        $tester = new CommandTester($this->command(
            [ModelSeeder::class => new \RuntimeException('Deadlock found when trying to get lock')],
            $logger,
        ));

        self::assertSame(Command::FAILURE, $tester->execute([]));

        self::assertContains(ModelRetirementSeeder::class, $this->ran, 'Retirements must still be applied');
        self::assertContains(ModuleGateSeeder::class, $this->ran, 'Later steps must still run');

        // SymfonyStyle wraps error blocks at the terminal width.
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Deadlock found when trying to get lock', $display);
        self::assertStringContainsString('seed steps failed: models.', $display);
        self::assertStringNotContainsString('All seed steps completed.', $display);
    }
}
