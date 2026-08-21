<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Service;

use App\AI\Credential\ProviderDefaultsService;
use App\AI\Service\ModelAvailabilityChecker;
use App\AI\Service\ModelProbeResult;
use App\AI\Service\ProviderModelInventoryInterface;
use App\AI\Service\ProviderModelListing;
use App\Entity\Model;
use App\Model\ModelCatalog;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The rules that make this detector trustworthy rather than noisy.
 *
 * Every case here mirrors a real provider behaviour observed while building the
 * check: listings that omit live models, listings that cannot be obtained, and
 * capabilities a provider serves through a separate product surface.
 *
 * The checker always reads the real {@see ModelCatalog}, so assertions target
 * individual models instead of counting findings — a catalog edit must not be
 * able to break an unrelated test.
 */
final class ModelAvailabilityCheckerTest extends TestCase
{
    private const TEST_PROVIDER = 'groq';

    /**
     * The false alarm a listing-only check produces. Gemini serves
     * `imagen-4.0-generate-001` but omits it from `models.list` and answers 200
     * when asked directly, so absence from a listing is not evidence by itself.
     */
    public function testModelMissingFromListingButAliveOnProbeIsNotReported(): void
    {
        $report = $this->report(
            activeModels: [$this->model('Groq', 'served-elsewhere', 'text2pic')],
            listing: ProviderModelListing::ok(['unrelated-model']),
            verdicts: ['served-elsewhere' => ModelProbeResult::Alive],
        );

        $this->assertNull($this->finding($report, 'served-elsewhere'));
    }

    public function testModelTheProviderReportsAsUnknownIsConfirmed(): void
    {
        $report = $this->report(
            activeModels: [$this->model('Groq', 'retired-chat-model', 'chat')],
            listing: ProviderModelListing::ok(['unrelated-model']),
            verdicts: ['retired-chat-model' => ModelProbeResult::Gone],
        );

        $finding = $this->finding($report, 'retired-chat-model');
        $this->assertNotNull($finding);
        $this->assertTrue($finding['confirmed']);
        $this->assertSame([ModelAvailabilityChecker::SCOPE_DATABASE], $finding['scopes']);
    }

    /**
     * A rate limit or outage says nothing about the model, so it stays visible
     * to a human without counting as a confirmed retirement.
     */
    public function testInconclusiveProbeIsReportedButNotConfirmed(): void
    {
        $report = $this->report(
            activeModels: [$this->model('Groq', 'rate-limited-model', 'chat')],
            listing: ProviderModelListing::ok(['unrelated-model']),
            verdicts: ['rate-limited-model' => ModelProbeResult::Inconclusive],
        );

        $finding = $this->finding($report, 'rate-limited-model');
        $this->assertNotNull($finding);
        $this->assertFalse($finding['confirmed']);
    }

    /**
     * The most damaging possible bug: reading "we could not ask" as "the
     * provider serves nothing" would report every model of that provider as
     * discontinued in a single run.
     */
    public function testUnreachableProviderNeverProducesFindings(): void
    {
        $inventory = $this->createMock(ProviderModelInventoryInterface::class);
        $inventory->method('fetch')->willReturn(ProviderModelListing::unreachable('HTTP 500'));
        $inventory->expects($this->never())->method('probe');

        $report = $this->reportWith($inventory, [
            $this->model('Groq', 'retired-chat-model', 'chat'),
            $this->model('Groq', 'another-retired-model', 'chat'),
        ]);

        $this->assertSame([], $report['findings']);
        $this->assertSame(ProviderModelListing::STATUS_UNREACHABLE, $report['providers'][self::TEST_PROVIDER]['status']);
    }

    public function testProviderWithoutConfiguredKeyProducesNoFindings(): void
    {
        $inventory = $this->createMock(ProviderModelInventoryInterface::class);
        $inventory->method('fetch')->willReturn(ProviderModelListing::notConfigured());
        $inventory->expects($this->never())->method('probe');

        $report = $this->reportWith($inventory, [$this->model('Groq', 'retired-chat-model', 'chat')]);

        $this->assertSame([], $report['findings']);
    }

    /**
     * A dead model that `app:provider:apply-defaults --auto` assigns unattended
     * is the urgent case, so it must be marked and sorted ahead of the rest.
     */
    public function testRecommendedProviderDefaultIsFlaggedAndRankedFirst(): void
    {
        $chatBid = $this->providerDefaults()->getRecommendedDefaults(self::TEST_PROVIDER)['CHAT'];
        $recommendedProviderId = $this->catalogProviderId($chatBid);

        $report = $this->report(
            activeModels: [$this->model('Groq', 'ordinary-retired-model', 'chat')],
            listing: ProviderModelListing::ok(['unrelated-model']),
            verdicts: [
                'ordinary-retired-model' => ModelProbeResult::Gone,
                $recommendedProviderId => ModelProbeResult::Gone,
            ],
        );

        $finding = $this->finding($report, $recommendedProviderId);
        $this->assertNotNull($finding);
        $this->assertTrue($finding['recommended']);
        $this->assertContains($chatBid, $finding['bids']);
        $this->assertSame($recommendedProviderId, $report['findings'][0]['providerId'], 'A provider default must be reported first.');
    }

    /**
     * The database and the catalog answer different questions: an operator who
     * already deactivated a row locally must still learn that new installs keep
     * getting it.
     */
    public function testCatalogEntryIsCheckedEvenWhenTheDatabaseHasNoSuchRow(): void
    {
        $catalogProviderId = $this->firstCatalogProviderId();

        $report = $this->report(
            activeModels: [],
            listing: ProviderModelListing::ok(['unrelated-model']),
            verdicts: [$catalogProviderId => ModelProbeResult::Gone],
        );

        $finding = $this->finding($report, $catalogProviderId);
        $this->assertNotNull($finding);
        $this->assertSame([ModelAvailabilityChecker::SCOPE_CATALOG], $finding['scopes']);
    }

    /**
     * A listing that parses but is wildly incomplete must not turn into hundreds
     * of probe requests. Models past the ceiling stay visible as unconfirmed
     * rather than being dropped.
     */
    public function testProbesAreCappedPerProvider(): void
    {
        $activeModels = [];
        for ($i = 0; $i < 40; ++$i) {
            $activeModels[] = $this->model('Groq', sprintf('bulk-model-%02d', $i), 'chat');
        }

        $probed = 0;
        $inventory = $this->createStub(ProviderModelInventoryInterface::class);
        $inventory->method('fetch')->willReturnCallback(
            static fn (string $provider): ProviderModelListing => self::TEST_PROVIDER === $provider
                ? ProviderModelListing::ok(['unrelated-model'])
                : ProviderModelListing::notConfigured(),
        );
        $inventory->method('probe')->willReturnCallback(
            static function () use (&$probed): ModelProbeResult {
                ++$probed;

                return ModelProbeResult::Gone;
            },
        );

        $report = $this->reportWith($inventory, $activeModels);

        $this->assertLessThanOrEqual(25, $probed, 'The per-provider probe ceiling must hold.');
        $unconfirmed = array_filter($report['findings'], static fn (array $f): bool => !$f['confirmed']);
        $this->assertNotSame([], $unconfirmed, 'Models beyond the ceiling stay reported, just unconfirmed.');
    }

    /**
     * @param list<Model>                     $activeModels
     * @param array<string, ModelProbeResult> $verdicts
     *
     * @return array{providers: array<string, array{status: string, detail: string|null, servedCount: int, matchedCount: int, offeredCount: int}>, findings: list<array{provider: string, providerId: string, name: string, tag: string, bids: list<int>, scopes: list<string>, recommended: bool, confirmed: bool}>}
     */
    private function report(array $activeModels, ProviderModelListing $listing, array $verdicts): array
    {
        $inventory = $this->createStub(ProviderModelInventoryInterface::class);
        $inventory->method('fetch')->willReturnCallback(
            static fn (string $provider): ProviderModelListing => self::TEST_PROVIDER === $provider
                ? $listing
                : ProviderModelListing::notConfigured(),
        );
        // Everything not named by the test is alive, so unrelated catalog rows of
        // the same provider cannot leak into the assertions.
        $inventory->method('probe')->willReturnCallback(
            static fn (string $provider, string $modelId): ModelProbeResult => $verdicts[$modelId] ?? ModelProbeResult::Alive,
        );

        return $this->reportWith($inventory, $activeModels);
    }

    /**
     * @param list<Model> $activeModels
     *
     * @return array{providers: array<string, array{status: string, detail: string|null, servedCount: int, matchedCount: int, offeredCount: int}>, findings: list<array{provider: string, providerId: string, name: string, tag: string, bids: list<int>, scopes: list<string>, recommended: bool, confirmed: bool}>}
     */
    private function reportWith(ProviderModelInventoryInterface $inventory, array $activeModels): array
    {
        $modelRepository = $this->createStub(ModelRepository::class);
        $modelRepository->method('findAllActive')->willReturn($activeModels);

        return (new ModelAvailabilityChecker($inventory, $modelRepository, $this->providerDefaults()))->run();
    }

    /**
     * @param array{providers: array<string, array{status: string, detail: string|null, servedCount: int, matchedCount: int, offeredCount: int}>, findings: list<array{provider: string, providerId: string, name: string, tag: string, bids: list<int>, scopes: list<string>, recommended: bool, confirmed: bool}>} $report
     *
     * @return array{provider: string, providerId: string, name: string, tag: string, bids: list<int>, scopes: list<string>, recommended: bool, confirmed: bool}|null
     */
    private function finding(array $report, string $providerId): ?array
    {
        foreach ($report['findings'] as $finding) {
            if ($finding['providerId'] === $providerId) {
                return $finding;
            }
        }

        return null;
    }

    private function providerDefaults(): ProviderDefaultsService
    {
        return new ProviderDefaultsService(
            $this->createStub(ConfigRepository::class),
            new ArrayAdapter(),
            new NullLogger(),
        );
    }

    private function catalogProviderId(int $bid): string
    {
        foreach (ModelCatalog::all() as $row) {
            if ((int) $row['id'] === $bid) {
                return (string) $row['providerId'];
            }
        }

        self::fail(sprintf('ModelCatalog has no entry with BID %d.', $bid));
    }

    private function firstCatalogProviderId(): string
    {
        foreach (ModelCatalog::all() as $row) {
            if (self::TEST_PROVIDER === ModelCatalog::normalizeProvider((string) $row['service']) && 1 === (int) $row['active']) {
                return (string) $row['providerId'];
            }
        }

        self::fail(sprintf('ModelCatalog has no active %s entry.', self::TEST_PROVIDER));
    }

    private function model(string $service, string $providerId, string $tag): Model
    {
        $model = new Model();
        $model->setService($service);
        $model->setProviderId($providerId);
        $model->setName($providerId);
        $model->setTag($tag);
        $model->setActive(1);

        return $model;
    }
}
