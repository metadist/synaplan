<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ModelDiscovery;

use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Service\ProviderModelInventoryInterface;
use App\AI\Service\ProviderModelListing;
use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\ModelDiscovery\ModelDiscoveryService;
use App\Service\ModelDiscovery\ModelDiscoveryStateStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ModelDiscoveryServiceTest extends TestCase
{
    /** @var array<string, array{baselineRecorded: bool, baselineIds: list<string>, seen: array<string, string>}> */
    private array $storedProviders = [];

    private bool $claimResult = true;

    private int $claimCalls = 0;

    public function testDisabledThrowsOnRun(): void
    {
        $service = $this->service(enabled: false);
        $this->expectException(\LogicException::class);
        $service->run();
    }

    public function testNotConfiguredAndNoListingAreSilent(): void
    {
        $listings = [];
        foreach (ProviderKeyCatalog::providerNames() as $provider) {
            $listings[$provider] = ProviderKeyCatalog::listsModels($provider)
                ? ProviderModelListing::notConfigured()
                : ProviderModelListing::noListingEndpoint('n/a');
        }

        $report = $this->service(listings: $listings)->run();

        $this->assertSame([], $report->pending);
        $this->assertSame([], $report->failedProviders);
        $this->assertSame([], $report->baselinesRecorded);
        $this->assertFalse($report->shouldNotify);
    }

    public function testUnreachableProviderIsReported(): void
    {
        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::unreachable('HTTP 500');

        $report = $this->service(listings: $listings)->run();

        $this->assertCount(1, $report->failedProviders);
        $this->assertSame('openai', $report->failedProviders[0]['provider']);
        $this->assertTrue($report->shouldNotify);
        $this->assertStringContainsString('HTTP 500', $report->failedProviders[0]['detail']);
    }

    public function testFirstSuccessfulListingRecordsBaselineAndReportsNone(): void
    {
        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o', 'gpt-5.4']);

        $report = $this->service(listings: $listings)->run();

        $this->assertSame([], $report->pending);
        $this->assertCount(1, $report->baselinesRecorded);
        $this->assertSame('openai', $report->baselinesRecorded[0]['provider']);
        $this->assertSame(2, $report->baselinesRecorded[0]['idCount']);
        $this->assertTrue($report->shouldNotify);
        $this->assertTrue($this->storedProviders['openai']['baselineRecorded']);
        $this->assertSame(['gpt-4o', 'gpt-5.4'], $this->storedProviders['openai']['baselineIds']);
    }

    public function testLaterAddedProviderGetsItsOwnBaseline(): void
    {
        $this->storedProviders = [
            'openai' => [
                'baselineRecorded' => true,
                'baselineIds' => ['gpt-4o'],
                'seen' => ['gpt-4o' => '2026-09-01'],
            ],
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o']);
        $listings['anthropic'] = ProviderModelListing::ok(['claude-sonnet-5']);

        $report = $this->service(
            listings: $listings,
            clock: new MockClock('2026-09-24 12:00:00'),
        )->run();

        $this->assertSame([], $report->pending);
        $this->assertCount(1, $report->baselinesRecorded);
        $this->assertSame('anthropic', $report->baselinesRecorded[0]['provider']);
        $this->assertSame(1, $report->baselinesRecorded[0]['idCount']);
    }

    public function testPendingPersistsAcrossRunsUntilKnown(): void
    {
        $this->storedProviders = [
            'openai' => [
                'baselineRecorded' => true,
                'baselineIds' => ['gpt-4o'],
                'seen' => ['gpt-4o' => '2026-09-01'],
            ],
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o', 'gpt-brand-new']);

        $day1 = $this->service(
            listings: $listings,
            clock: new MockClock('2026-09-10 12:00:00'),
        )->run();

        $this->assertCount(1, $day1->pending);
        $this->assertSame('gpt-brand-new', $day1->pending[0]['id']);
        $this->assertSame('2026-09-10', $day1->pending[0]['firstSeen']);
        $this->assertSame(0, $day1->pending[0]['daysPending']);

        $day2 = $this->service(
            listings: $listings,
            clock: new MockClock('2026-09-13 12:00:00'),
        )->run();

        $this->assertCount(1, $day2->pending);
        $this->assertSame('2026-09-10', $day2->pending[0]['firstSeen']);
        $this->assertSame(3, $day2->pending[0]['daysPending']);

        $known = $this->service(
            listings: $listings,
            models: [$this->model('OpenAI', 'gpt-brand-new', active: false)],
            clock: new MockClock('2026-09-14 12:00:00'),
        )->run();

        $this->assertSame([], $known->pending);
    }

    public function testPendingDisappearsWhenIgnored(): void
    {
        $this->storedProviders = [
            'openai' => [
                'baselineRecorded' => true,
                'baselineIds' => ['gpt-4o'],
                'seen' => ['gpt-4o' => '2026-09-01', 'skip-me' => '2026-09-10'],
            ],
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o', 'skip-me']);

        $report = $this->service(
            listings: $listings,
            ignoreEntries: [
                'openai:skip-me' => ['reason' => 'Deliberately skipped', 'decidedOn' => '2026-09-11'],
            ],
            clock: new MockClock('2026-09-12 12:00:00'),
        )->run();

        $this->assertSame([], $report->pending);
    }

    public function testPendingDisappearsWhenUnlisted(): void
    {
        $this->storedProviders = [
            'openai' => [
                'baselineRecorded' => true,
                'baselineIds' => ['gpt-4o'],
                'seen' => ['gpt-4o' => '2026-09-01', 'ephemeral' => '2026-09-10'],
            ],
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o']);

        $report = $this->service(
            listings: $listings,
            clock: new MockClock('2026-09-12 12:00:00'),
        )->run();

        $this->assertSame([], $report->pending);
        $this->assertArrayNotHasKey('ephemeral', $this->storedProviders['openai']['seen']);
    }

    public function testKnownMatchingIncludesParamsModelAndNormalisation(): void
    {
        $this->storedProviders = [
            'google' => [
                'baselineRecorded' => true,
                'baselineIds' => ['gemini-old'],
                'seen' => ['gemini-old' => '2026-09-01'],
            ],
        ];

        $listings = $this->allNotConfigured();
        // Inventory already strips models/; dated snapshot of a known params.model
        $listings['google'] = ProviderModelListing::ok([
            'gemini-old',
            'gemini-2.5-pro-20250520',
        ]);

        $model = $this->model('Google', 'display-id', active: true);
        $model->setJson(['params' => ['model' => 'models/gemini-2.5-pro']]);

        $report = $this->service(
            listings: $listings,
            models: [$model],
            clock: new MockClock('2026-09-12 12:00:00'),
        )->run();

        $this->assertSame([], $report->pending);
    }

    public function testRetiredInactiveRowsCountAsKnown(): void
    {
        $this->storedProviders = [
            'groq' => [
                'baselineRecorded' => true,
                'baselineIds' => ['kept'],
                'seen' => ['kept' => '2026-09-01'],
            ],
        ];

        $listings = $this->allNotConfigured();
        $listings['groq'] = ProviderModelListing::ok(['kept', 'retired-chat']);

        $report = $this->service(
            listings: $listings,
            models: [$this->model('Groq', 'retired-chat', active: false)],
            clock: new MockClock('2026-09-12 12:00:00'),
        )->run();

        $this->assertSame([], $report->pending);
    }

    public function testObsoleteIgnoreGoneUpstreamAndNowInBmodels(): void
    {
        $this->storedProviders = [
            'openai' => [
                'baselineRecorded' => true,
                'baselineIds' => ['gpt-4o'],
                'seen' => ['gpt-4o' => '2026-09-01'],
            ],
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o', 'still-listed']);

        $report = $this->service(
            listings: $listings,
            models: [$this->model('OpenAI', 'now-offered')],
            ignoreEntries: [
                'openai:gone-id' => ['reason' => 'was skip', 'decidedOn' => '2026-08-01'],
                'openai:now-offered' => ['reason' => 'was skip', 'decidedOn' => '2026-08-01'],
                'openai:still-listed' => ['reason' => 'keep skipping', 'decidedOn' => '2026-08-01'],
            ],
            clock: new MockClock('2026-09-12 12:00:00'),
        )->run();

        $whys = [];
        foreach ($report->obsoleteIgnores as $entry) {
            $whys[$entry['key']] = $entry['why'];
        }
        $this->assertSame('gone_upstream', $whys['openai:gone-id']);
        $this->assertSame('now_in_bmodels', $whys['openai:now-offered']);
        $this->assertArrayNotHasKey('openai:still-listed', $whys);
    }

    public function testClaimNotifyDayDelegatesToStore(): void
    {
        $this->claimResult = false;
        $service = $this->service();
        $this->assertFalse($service->claimNotifyDay());
        $this->assertSame(1, $this->claimCalls);
    }

    /**
     * @param array<string, ProviderModelListing>                          $listings
     * @param list<Model>                                                  $models
     * @param array<string, array{reason: string, decidedOn: string}>|null $ignoreEntries
     */
    private function service(
        bool $enabled = true,
        array $listings = [],
        array $models = [],
        ?array $ignoreEntries = null,
        ?MockClock $clock = null,
    ): ModelDiscoveryService {
        $inventory = $this->createMock(ProviderModelInventoryInterface::class);
        $resolved = [] === $listings ? $this->allNotConfigured() : $listings;
        $inventory->method('fetch')->willReturnCallback(
            static fn (string $provider): ProviderModelListing => $resolved[$provider]
                ?? ProviderModelListing::notConfigured(),
        );

        $modelsRepo = $this->createMock(ModelRepository::class);
        $modelsRepo->method('findAllForDiscovery')->willReturn($models);

        $store = $this->createMock(ModelDiscoveryStateStore::class);
        $store->method('loadProviders')->willReturnCallback(fn (): array => $this->storedProviders);
        $store->method('saveProviders')->willReturnCallback(function (array $providers): void {
            $this->storedProviders = $providers;
        });
        $store->method('claimNotifyDay')->willReturnCallback(function () {
            ++$this->claimCalls;

            return $this->claimResult;
        });

        return new ModelDiscoveryService(
            $inventory,
            $modelsRepo,
            $store,
            $clock ?? new MockClock('2026-09-24 12:00:00'),
            $enabled,
            $ignoreEntries,
        );
    }

    /**
     * @return array<string, ProviderModelListing>
     */
    private function allNotConfigured(): array
    {
        $listings = [];
        foreach (ProviderKeyCatalog::providerNames() as $provider) {
            $listings[$provider] = ProviderModelListing::notConfigured();
        }

        return $listings;
    }

    private function model(string $service, string $providerId, bool $active = true): Model
    {
        $model = new Model();
        $model->setService($service);
        $model->setProviderId($providerId);
        $model->setName($providerId);
        $model->setTag('chat');
        $model->setActive($active ? 1 : 0);
        $model->setSelectable($active ? 1 : 0);
        $model->setJson([]);

        return $model;
    }
}
