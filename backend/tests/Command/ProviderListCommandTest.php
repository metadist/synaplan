<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command only READS (availability snapshot, key status, model counts) —
 * asserting concrete availability values would depend on which keys the
 * current environment happens to hold, so the assertions stay structural:
 * every operator-facing provider is listed, the internal TestProvider is not,
 * and the exit code is clean.
 */
final class ProviderListCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:provider:list'));
    }

    public function testListsProvidersWithAvailabilityAndModelCounts(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();

        foreach (['Provider', 'Credentials', 'Available', 'Models active/DB', 'In catalog'] as $header) {
            self::assertStringContainsString($header, $output);
        }

        // Registered real providers must appear with their display names.
        self::assertStringContainsString('Ollama', $output);
        self::assertStringContainsString('OpenAI', $output);
        self::assertStringContainsString('Groq', $output);
    }

    public function testInternalTestProviderIsNotListed(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringNotContainsString('Test Provider', $tester->getDisplay());
    }

    public function testFreshFlagBypassesTheCachedSnapshot(): void
    {
        $tester = $this->tester();
        $tester->execute(['--fresh' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }
}
