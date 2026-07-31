<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\AI\Credential\ChatReadinessService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command is the ONLY automated path that may repoint the global default
 * models (it runs at container start). Its guards therefore have to hold:
 * bad input must not reach the write, and `--auto` must exit cleanly whether or
 * not it changes anything.
 *
 * The policy itself (never override a keyless or working default) is covered by
 * ProviderDefaultsServiceTest — asserting a concrete outcome here would depend
 * on which provider keys the current environment happens to have.
 */
final class ApplyProviderDefaultsCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:provider:apply-defaults'));
    }

    public function testAutoModeExitsSuccessfullyAndReportsWhatItDid(): void
    {
        $tester = $this->tester();
        $tester->execute(['--auto' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertMatchesRegularExpression(
            '/(left unchanged|Default chat provider set to)/',
            $output,
            'auto mode must state whether it changed the default'
        );
    }

    public function testProviderNameAndAutoAreMutuallyExclusive(): void
    {
        $tester = $this->tester();
        $tester->execute(['provider' => 'groq', '--auto' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('not both', $tester->getDisplay());
    }

    public function testMissingArgumentIsRejected(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--auto', $tester->getDisplay());
    }

    public function testUnknownProviderIsRejectedBeforeAnyWrite(): void
    {
        $tester = $this->tester();
        $tester->execute(['provider' => 'definitely-not-a-provider']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('No recommended defaults', $tester->getDisplay());
    }

    /**
     * Making a provider without a key the default would break chat — the command
     * refuses unless the operator insists with --force.
     */
    public function testProviderWithoutAKeyNeedsForce(): void
    {
        $tester = $this->tester();

        // Which providers hold a key depends on the environment, so ask instead
        // of assuming — and never run the write path just to find out.
        $readiness = self::getContainer()->get(ChatReadinessService::class);
        $availability = $readiness->providerAvailability(fresh: true);
        $keyless = null;
        foreach (['xai', 'trustedtokens', 'huggingface', 'mistral'] as $candidate) {
            if (!($availability[$candidate] ?? false)) {
                $keyless = $candidate;
                break;
            }
        }
        if (null === $keyless) {
            self::markTestSkipped('every candidate provider has a key in this environment');
        }

        $tester->execute(['provider' => $keyless]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('no usable key', $tester->getDisplay());
    }
}
