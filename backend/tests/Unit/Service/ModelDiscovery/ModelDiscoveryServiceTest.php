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
    /**
     * @var array<string, array{
     *     baselineRecorded: bool,
     *     baselineAnnounced: bool,
     *     baselineIds: list<string>,
     *     seen: array<string, string>,
     *     announced: array<string, string>,
     *     failingSince: string|null,
     *     failureAnnounced: bool
     * }>
     */
    private array $storedProviders = [];

    private bool $claimResult = true;

    private int $claimCalls = 0;

    private int $releaseCalls = 0;

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

    public function testUnreachableProviderIsReportedAsNewFailure(): void
    {
        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::unreachable('HTTP 500');

        $report = $this->service(listings: $listings)->run();

        $this->assertCount(1, $report->failedProviders);
        $this->assertSame('openai', $report->failedProviders[0]['provider']);
        $this->assertTrue($report->failedProviders[0]['isNew']);
        $this->assertTrue($report->shouldNotify);
        $this->assertSame('2026-09-24', $this->storedProviders['openai']['failingSince']);
        $this->assertFalse($this->storedProviders['openai']['failureAnnounced']);
    }

    public function testAnnouncedFailureIsSilentUntilMonday(): void
    {
        $this->storedProviders = [
            'openai' => $this->providerState(
                failingSince: '2026-09-20',
                failureAnnounced: true,
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::unreachable('HTTP 500');

        $tuesday = $this->service(
            listings: $listings,
            clock: new MockClock('2026-09-22 12:00:00'), // Tuesday
        )->run();

        $this->assertFalse($tuesday->failedProviders[0]['isNew']);
        $this->assertFalse($tuesday->shouldNotify);

        $monday = $this->service(
            listings: $listings,
            clock: new MockClock('2026-09-21 12:00:00'), // Monday
        )->run();

        $this->assertTrue($monday->shouldNotify);
        $this->assertTrue($monday->isMondayReminder);
    }

    public function testFailureClearedAfterRecovery(): void
    {
        $this->storedProviders = [
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01'],
                failingSince: '2026-09-20',
                failureAnnounced: true,
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o']);

        $report = $this->service(listings: $listings)->run();

        $this->assertSame([], $report->failedProviders);
        $this->assertNull($this->storedProviders['openai']['failingSince']);
        $this->assertFalse($this->storedProviders['openai']['failureAnnounced']);
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
        $this->assertFalse($this->storedProviders['openai']['baselineAnnounced']);
        $this->assertSame(['gpt-4o', 'gpt-5.4'], $this->storedProviders['openai']['baselineIds']);
    }

    public function testUnannouncedOlderBaselineIsReportedAgain(): void
    {
        $this->storedProviders = [
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: false,
                baselineIds: ['gpt-4o', 'gpt-5'],
                seen: ['gpt-4o' => '2026-09-01', 'gpt-5' => '2026-09-01'],
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o', 'gpt-5']);

        $report = $this->service(listings: $listings)->run();

        $this->assertSame([], $report->pending);
        $this->assertCount(1, $report->baselinesRecorded);
        $this->assertTrue($report->shouldNotify);
    }

    public function testAnnouncedBaselineIsNotReported(): void
    {
        $this->storedProviders = [
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01'],
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o']);

        $report = $this->service(listings: $listings)->run();

        $this->assertSame([], $report->baselinesRecorded);
        $this->assertFalse($report->shouldNotify);
    }

    public function testLaterAddedProviderGetsItsOwnBaseline(): void
    {
        $this->storedProviders = [
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01'],
            ),
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
    }

    public function testNewVersusOpenPendingSplit(): void
    {
        $this->storedProviders = [
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01', 'gpt-brand-new' => '2026-09-10'],
                announced: ['gpt-brand-new' => '2026-09-10'],
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o', 'gpt-brand-new', 'gpt-even-newer']);

        $report = $this->service(
            listings: $listings,
            clock: new MockClock('2026-09-12 12:00:00'), // Friday
        )->run();

        $this->assertCount(2, $report->pending);
        $this->assertCount(1, $report->newPending);
        $this->assertSame('gpt-even-newer', $report->newPending[0]['id']);
        $this->assertCount(1, $report->openPending);
        $this->assertSame('gpt-brand-new', $report->openPending[0]['id']);
        $this->assertTrue($report->shouldNotify); // new pending
        $this->assertFalse($report->isMondayReminder);
    }

    public function testTuesdayWithOnlyOpenPendingDoesNotNotify(): void
    {
        $this->storedProviders = [
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01', 'gpt-brand-new' => '2026-09-10'],
                announced: ['gpt-brand-new' => '2026-09-10'],
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o', 'gpt-brand-new']);

        $report = $this->service(
            listings: $listings,
            clock: new MockClock('2026-09-22 12:00:00'), // Tuesday
        )->run();

        $this->assertCount(1, $report->openPending);
        $this->assertSame([], $report->newPending);
        $this->assertFalse($report->shouldNotify);
        $this->assertFalse($report->isMondayReminder);
    }

    public function testMondayWithOnlyOpenPendingNotifiesAsReminder(): void
    {
        $this->storedProviders = [
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01', 'gpt-brand-new' => '2026-09-10'],
                announced: ['gpt-brand-new' => '2026-09-10'],
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o', 'gpt-brand-new']);

        $report = $this->service(
            listings: $listings,
            clock: new MockClock('2026-09-21 12:00:00'), // Monday
        )->run();

        $this->assertTrue($report->shouldNotify);
        $this->assertTrue($report->isMondayReminder);
        $this->assertSame([], $report->newPending);
        $this->assertCount(1, $report->openPending);
    }

    public function testPendingPersistsAcrossRunsUntilKnown(): void
    {
        $this->storedProviders = [
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01'],
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o', 'gpt-brand-new']);

        $day1 = $this->service(
            listings: $listings,
            clock: new MockClock('2026-09-10 12:00:00'),
        )->run();

        $this->assertCount(1, $day1->pending);
        $this->assertCount(1, $day1->newPending);
        $this->assertSame('gpt-brand-new', $day1->pending[0]['id']);
        $this->assertArrayHasKey('label', $day1->pending[0]);

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

    public function testAnnouncedCleanupWhenIdResolves(): void
    {
        $this->storedProviders = [
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01', 'gpt-brand-new' => '2026-09-10'],
                announced: ['gpt-brand-new' => '2026-09-10'],
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o', 'gpt-brand-new']);

        $this->service(
            listings: $listings,
            models: [$this->model('OpenAI', 'gpt-brand-new')],
            clock: new MockClock('2026-09-12 12:00:00'),
        )->run();

        $this->assertArrayNotHasKey('gpt-brand-new', $this->storedProviders['openai']['announced']);
    }

    public function testMarkDiscoveriesAnnouncedSetsAnnouncedAndFailures(): void
    {
        $this->storedProviders = [
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01', 'gpt-brand-new' => '2026-09-10'],
                failingSince: '2026-09-10',
                failureAnnounced: false,
            ),
            'anthropic' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: false,
                baselineIds: ['claude-old'],
                seen: ['claude-old' => '2026-09-01'],
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o', 'gpt-brand-new']);

        $service = $this->service(
            listings: $listings,
            clock: new MockClock('2026-09-12 12:00:00'),
        );
        $report = $service->run();
        // Simulate a concurrent failure entry on the report for marking
        $reportWithFailure = new \App\Service\ModelDiscovery\ModelDiscoveryReport(
            providers: $report->providers,
            pending: $report->pending,
            newPending: $report->newPending,
            openPending: $report->openPending,
            failedProviders: [[
                'provider' => 'openai',
                'detail' => 'HTTP 500',
                'failingSince' => '2026-09-10',
                'isNew' => true,
            ]],
            baselinesRecorded: [['provider' => 'anthropic', 'idCount' => 1]],
            obsoleteIgnores: [],
            silencedByClass: [],
            shouldNotify: true,
            isMondayReminder: false,
        );

        $service->markDiscoveriesAnnounced($reportWithFailure);

        $this->assertSame('2026-09-12', $this->storedProviders['openai']['announced']['gpt-brand-new']);
        $this->assertTrue($this->storedProviders['openai']['failureAnnounced']);
        $this->assertTrue($this->storedProviders['anthropic']['baselineAnnounced']);
    }

    public function testClassRulesSkipAndCount(): void
    {
        $this->storedProviders = [
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01'],
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok([
            'gpt-4o',
            'ft:personal-tune',
            'text-moderation-latest',
            'gpt-4o-realtime-preview',
            'gpt-truly-new',
        ]);

        $report = $this->service(
            listings: $listings,
            clock: new MockClock('2026-09-12 12:00:00'),
        )->run();

        $this->assertCount(1, $report->pending);
        $this->assertSame('gpt-truly-new', $report->pending[0]['id']);
        $this->assertSame(3, $report->silencedByClass['openai']);
    }

    public function testPendingDisappearsWhenIgnored(): void
    {
        $this->storedProviders = [
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01', 'skip-me' => '2026-09-10'],
            ),
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
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01', 'ephemeral' => '2026-09-10'],
                announced: ['ephemeral' => '2026-09-10'],
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['openai'] = ProviderModelListing::ok(['gpt-4o']);

        $report = $this->service(
            listings: $listings,
            clock: new MockClock('2026-09-12 12:00:00'),
        )->run();

        $this->assertSame([], $report->pending);
        $this->assertArrayNotHasKey('ephemeral', $this->storedProviders['openai']['seen']);
        $this->assertArrayNotHasKey('ephemeral', $this->storedProviders['openai']['announced']);
    }

    public function testKnownMatchingIncludesParamsModelAndNormalisation(): void
    {
        $this->storedProviders = [
            'google' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gemini-old'],
                seen: ['gemini-old' => '2026-09-01'],
            ),
        ];

        $listings = $this->allNotConfigured();
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

    public function testNewDateSnapshotOfPinnedCatalogStaysPending(): void
    {
        $this->storedProviders = [
            'anthropic' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['claude-old'],
                seen: ['claude-old' => '2026-09-01'],
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['anthropic'] = ProviderModelListing::ok([
            'claude-old',
            'claude-haiku-4-5-20260301',
        ]);

        $report = $this->service(
            listings: $listings,
            models: [$this->model('Anthropic', 'claude-haiku-4-5-20251001')],
            clock: new MockClock('2026-09-12 12:00:00'),
        )->run();

        $this->assertCount(1, $report->pending);
        $this->assertSame('claude-haiku-4-5-20260301', $report->pending[0]['id']);
    }

    public function testUndatedListingMatchesDatePinnedCatalog(): void
    {
        $this->storedProviders = [
            'anthropic' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['claude-old'],
                seen: ['claude-old' => '2026-09-01'],
            ),
        ];

        $listings = $this->allNotConfigured();
        $listings['anthropic'] = ProviderModelListing::ok([
            'claude-old',
            'claude-haiku-4-5',
        ]);

        $report = $this->service(
            listings: $listings,
            models: [$this->model('Anthropic', 'claude-haiku-4-5-20251001')],
            clock: new MockClock('2026-09-12 12:00:00'),
        )->run();

        $this->assertSame([], $report->pending);
    }

    public function testRetiredInactiveRowsCountAsKnown(): void
    {
        $this->storedProviders = [
            'groq' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['kept'],
                seen: ['kept' => '2026-09-01'],
            ),
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
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: true,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01'],
            ),
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

    public function testReleaseNotifyDayDelegatesToStore(): void
    {
        $this->service()->releaseNotifyDay();
        $this->assertSame(1, $this->releaseCalls);
    }

    public function testMarkBaselinesAnnouncedSetsFlag(): void
    {
        $state = [
            'openai' => $this->providerState(
                baselineRecorded: true,
                baselineAnnounced: false,
                baselineIds: ['gpt-4o'],
                seen: ['gpt-4o' => '2026-09-01'],
            ),
        ];
        $saved = null;

        $inventory = $this->createMock(ProviderModelInventoryInterface::class);
        $modelsRepo = $this->createMock(ModelRepository::class);
        $store = $this->createMock(ModelDiscoveryStateStore::class);
        $store->method('loadProviders')->willReturnCallback(static fn (): array => $state);
        $store->method('saveProviders')->willReturnCallback(static function (array $providers) use (&$saved): void {
            $saved = $providers;
        });

        $service = new ModelDiscoveryService(
            $inventory,
            $modelsRepo,
            $store,
            new MockClock('2026-09-24 12:00:00'),
            true,
        );
        $service->markBaselinesAnnounced(['openai']);

        $this->assertNotNull($saved);
        $this->assertTrue($saved['openai']['baselineAnnounced']);
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
        $store->method('releaseNotifyDay')->willReturnCallback(function (): void {
            ++$this->releaseCalls;
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
     * @param list<string>          $baselineIds
     * @param array<string, string> $seen
     * @param array<string, string> $announced
     *
     * @return array{
     *     baselineRecorded: bool,
     *     baselineAnnounced: bool,
     *     baselineIds: list<string>,
     *     seen: array<string, string>,
     *     announced: array<string, string>,
     *     failingSince: string|null,
     *     failureAnnounced: bool
     * }
     */
    private function providerState(
        bool $baselineRecorded = false,
        bool $baselineAnnounced = false,
        array $baselineIds = [],
        array $seen = [],
        array $announced = [],
        ?string $failingSince = null,
        bool $failureAnnounced = false,
    ): array {
        return [
            'baselineRecorded' => $baselineRecorded,
            'baselineAnnounced' => $baselineAnnounced,
            'baselineIds' => $baselineIds,
            'seen' => $seen,
            'announced' => $announced,
            'failingSince' => $failingSince,
            'failureAnnounced' => $failureAnnounced,
        ];
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
