<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PromptSeedCommand;
use App\Seed\PromptSeeder;
use App\Seed\SeedResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command is now a thin wrapper around PromptSeeder; the actual
 * seeding + post-seed toggle convergence is covered by PromptSeederTest.
 */
class PromptSeedCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private PromptSeeder&MockObject $seeder;

    protected function setUp(): void
    {
        $this->seeder = $this->createMock(PromptSeeder::class);
        $command = new PromptSeedCommand($this->seeder);

        $application = new Application();
        $application->addCommand($command);

        $this->commandTester = new CommandTester($application->find('app:prompt:seed'));
    }

    public function testCommandDelegatesToSeederAndReportsTheResult(): void
    {
        $this->seeder->expects($this->once())
            ->method('seed')
            ->willReturn(new SeedResult('prompts', inserted: 4, updated: 17));

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('21', $output);
        $this->assertStringContainsString('4 inserted', $output);
        $this->assertStringContainsString('17 updated', $output);
    }
}
