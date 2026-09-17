<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\AI\Credential\ProviderDefaultsService;
use App\AI\Service\ModelAvailabilityChecker;
use App\AI\Service\ModelProbeResult;
use App\AI\Service\ProviderModelInventoryInterface;
use App\AI\Service\ProviderModelListing;
use App\Command\CheckModelAvailabilityCommand;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\DiscordNotificationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CheckModelAvailabilityCommandTest extends TestCase
{
    /**
     * A BID the real catalog does not carry, so the draft entry exercises the
     * "no sibling to suggest" branch without depending on catalog data.
     */
    private const OFFERED_BID = 99999;

    /** @var list<string> */
    private array $webhookCalls = [];

    public function testReportsSuccessWhenEverythingIsStillServed(): void
    {
        $tester = $this->tester(ProviderModelListing::notConfigured(), ModelProbeResult::Alive);
        $tester->execute(['--fail-on-drift' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No provider could be checked', $tester->getDisplay());
    }

    public function testConfirmedFindingFailsOnlyWhenAskedTo(): void
    {
        $tester = $this->tester(ProviderModelListing::ok(['unrelated']), ModelProbeResult::Gone);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), 'Without --fail-on-drift the command only reports.');
        $this->assertStringContainsString('no longer served by their provider', $tester->getDisplay());
    }

    public function testConfirmedFindingExitsWithTheDriftCode(): void
    {
        $tester = $this->tester(ProviderModelListing::ok(['unrelated']), ModelProbeResult::Gone);
        $tester->execute(['--fail-on-drift' => true]);

        $this->assertSame(2, $tester->getStatusCode());
    }

    /**
     * An unconfirmed finding is an unanswered question, not a retirement, so it
     * must neither fail the run nor raise an alert.
     */
    public function testUnconfirmedFindingsNeitherFailNorNotify(): void
    {
        $tester = $this->tester(ProviderModelListing::ok(['unrelated']), ModelProbeResult::Inconclusive);
        $tester->execute(['--fail-on-drift' => true, '--notify' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('no usable answer', $tester->getDisplay());
        $this->assertSame([], $this->webhookCalls, 'Unconfirmed findings must not reach Discord.');
    }

    public function testNotifyIsOptIn(): void
    {
        $tester = $this->tester(ProviderModelListing::ok(['unrelated']), ModelProbeResult::Gone);
        $tester->execute([]);
        $this->assertSame([], $this->webhookCalls);

        $tester = $this->tester(ProviderModelListing::ok(['unrelated']), ModelProbeResult::Gone);
        $tester->execute(['--notify' => true]);
        $this->assertCount(1, $this->webhookCalls);
    }

    public function testFindingNamesTheModelAndItsScopes(): void
    {
        $tester = $this->tester(ProviderModelListing::ok(['unrelated']), ModelProbeResult::Gone);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('retired-test-model', $display);
        $this->assertStringContainsString(ModelAvailabilityChecker::SCOPE_DATABASE, $display);
        $this->assertStringContainsString('docs/PRICING_MAINTENANCE.md', $display, 'The report must point at the retirement procedure.');
    }

    /**
     * Whoever reads this report is about to write the registry entry, so the
     * report writes it for them — keyed by the BID, dated today, with the
     * provider named in the reason.
     */
    public function testConfirmedFindingPrintsThePasteReadyRegistryEntry(): void
    {
        $tester = $this->tester(ProviderModelListing::ok(['unrelated']), ModelProbeResult::Gone);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('ModelCatalog::RETIREMENTS', $display);
        $this->assertStringContainsString(self::OFFERED_BID.' => [', $display);
        $this->assertStringContainsString("'providerId' => 'retired-test-model',", $display);
        $this->assertStringContainsString("'retiredOn' => '2026-09-17',", $display);
        $this->assertStringContainsString("'reason' => 'Groq no longer serves retired-test-model.',", $display);
    }

    /**
     * A BID the catalog does not carry has no sibling to suggest, and guessing
     * one would be worse than saying so: `null` is the entry that forbids a
     * substitution.
     */
    public function testTheDraftRecordsNoSuccessorWhenTheCatalogOffersNoSibling(): void
    {
        $tester = $this->tester(ProviderModelListing::ok(['unrelated']), ModelProbeResult::Gone);
        $tester->execute([]);

        $this->assertStringContainsString("'successor' => null,", $tester->getDisplay());
    }

    /**
     * Nothing is gone, so there is nothing to draft — the report must not invite
     * a retirement it did not confirm.
     */
    public function testNoDraftWithoutAConfirmedFinding(): void
    {
        $tester = $this->tester(ProviderModelListing::ok(['unrelated']), ModelProbeResult::Inconclusive);
        $tester->execute([]);

        $this->assertStringNotContainsString('ModelCatalog::RETIREMENTS', $tester->getDisplay());
    }

    private function tester(ProviderModelListing $listing, ModelProbeResult $verdict): CommandTester
    {
        $this->webhookCalls = [];

        $inventory = $this->createStub(ProviderModelInventoryInterface::class);
        $inventory->method('fetch')->willReturnCallback(
            static fn (string $provider): ProviderModelListing => 'groq' === $provider
                ? $listing
                : ProviderModelListing::notConfigured(),
        );
        $inventory->method('probe')->willReturnCallback(
            static fn (string $provider, string $modelId): ModelProbeResult => 'retired-test-model' === $modelId
                ? $verdict
                : ModelProbeResult::Alive,
        );

        $modelRepository = $this->createStub(ModelRepository::class);
        $modelRepository->method('findAllActive')->willReturn([$this->model()]);

        $checker = new ModelAvailabilityChecker(
            $inventory,
            $modelRepository,
            new ProviderDefaultsService($this->createStub(ConfigRepository::class), new ArrayAdapter(), new NullLogger()),
        );

        $webhookClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->webhookCalls[] = $url;

            return new MockResponse('', ['http_code' => 204]);
        });

        $command = new CheckModelAvailabilityCommand(
            $checker,
            new DiscordNotificationService(
                $webhookClient,
                new NullLogger(),
                $this->createStub(UserRepository::class),
                'https://discord.test/webhook',
            ),
            new MockClock('2026-09-17'),
        );

        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find('app:models:check-availability'));
    }

    private function model(): Model
    {
        $model = new Model();
        $model->setService('Groq');
        $model->setProviderId('retired-test-model');
        $model->setName('Retired Test Model');
        $model->setTag('chat');
        $model->setActive(1);

        // The BID is what a registry entry is keyed by, and Doctrine owns it.
        $id = new \ReflectionProperty(Model::class, 'id');
        $id->setValue($model, self::OFFERED_BID);

        return $model;
    }
}
