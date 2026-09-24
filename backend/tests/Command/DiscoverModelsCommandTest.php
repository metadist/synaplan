<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\DiscoverModelsCommand;
use App\Service\ModelDiscovery\ModelDiscoveryMatcher;
use App\Service\ModelDiscovery\ModelDiscoveryUnavailableException;
use App\Service\ModelDiscovery\OpenRouterModelSource;
use App\Service\ModelDiscovery\UpstreamModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DiscoverModelsCommandTest extends TestCase
{
    public function testExitZeroWhenNothingNew(): void
    {
        $tester = $this->tester($this->sourceReturning([
            new UpstreamModel(
                openRouterId: 'openai/gpt-6-astra',
                vendor: 'openai',
                created: new \DateTimeImmutable('2026-09-20T00:00:00Z'),
                priceInPer1M: 10.0,
                priceOutPer1M: 50.0,
                cacheReadPer1M: 1.0,
            ),
        ]));

        $tester->execute(['--fail-on-new' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('New upstream models (0)', $tester->getDisplay());
        $this->assertStringContainsString('Model discovery complete:', $tester->getDisplay());
    }

    public function testExitOneWhenSourceUnavailable(): void
    {
        $source = $this->createMock(OpenRouterModelSource::class);
        $source->method('fetch')->willThrowException(
            new ModelDiscoveryUnavailableException('OpenRouter model list returned HTTP 500'),
        );

        $tester = $this->tester($source);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('could not run', $tester->getDisplay());
    }

    public function testExitTwoOnlyWithFailOnNew(): void
    {
        $source = $this->sourceReturning([
            new UpstreamModel(
                openRouterId: 'anthropic/claude-opus-5.5',
                vendor: 'anthropic',
                created: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                priceInPer1M: 4.0,
                priceOutPer1M: 20.0,
                cacheReadPer1M: 0.2,
            ),
        ]);

        $without = $this->tester($source);
        $without->execute([]);
        $this->assertSame(Command::SUCCESS, $without->getStatusCode());
        $this->assertStringContainsString('anthropic/claude-opus-5.5', $without->getDisplay());

        $with = $this->tester($source);
        $with->execute(['--fail-on-new' => true]);
        $this->assertSame(2, $with->getStatusCode());
    }

    /**
     * @param list<UpstreamModel> $models
     */
    private function sourceReturning(array $models): OpenRouterModelSource
    {
        $source = $this->createMock(OpenRouterModelSource::class);
        $source->method('fetch')->willReturn($models);

        return $source;
    }

    private function tester(OpenRouterModelSource $source): CommandTester
    {
        $application = new Application();
        $application->addCommand(new DiscoverModelsCommand($source, new ModelDiscoveryMatcher()));

        return new CommandTester($application->find('app:models:discover'));
    }
}
