<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Update;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Service\Update\ReleaseManifest;
use App\Service\Update\UpdateConfig;
use App\Service\Update\UpdateManifestClient;
use App\Service\Update\UpdateStatusService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Detection must be conservative: it may only claim an update when the
 * comparison is unambiguous, and it may never change anything but the stored
 * result fields.
 *
 * The BCONFIG rows are backed by an in-memory store so a test can start from a
 * completely EMPTY group — which is exactly the state of an install that
 * upgraded into this feature before `app:seed` ran again.
 */
final class UpdateStatusServiceTest extends TestCase
{
    /** @var array<string, string> */
    private array $rows = [];

    private ConfigRepository&MockObject $configRepository;

    protected function setUp(): void
    {
        $this->rows = [];
        $this->configRepository = $this->createMock(ConfigRepository::class);

        $this->configRepository
            ->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $setting): ?string {
                self::assertSame(UpdateConfig::OWNER_ID, $ownerId);
                self::assertSame(UpdateConfig::CONFIG_GROUP, $group);

                return $this->rows[$setting] ?? null;
            });

        $this->configRepository
            ->method('setValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $setting, string $value): Config {
                $this->rows[$setting] = $value;

                return new Config();
            });
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function comparisonProvider(): iterable
    {
        yield 'newer patch' => ['4.0.12', '4.0.13', true];
        yield 'newer minor' => ['4.0.13', '4.1.0', true];
        yield 'equal' => ['4.0.13', '4.0.13', false];
        yield 'older published' => ['4.0.14', '4.0.13', false];
        yield 'dev checkout' => ['dev', '4.0.13', false];
        yield 'unparseable current' => ['nightly-2026-08-05', '4.0.13', false];
        yield 'release beats own rc' => ['4.1.0-rc.1', '4.1.0', true];
        yield 'rc does not beat release' => ['4.1.0', '4.1.0-rc.1', false];
        yield 'later rc beats earlier rc' => ['4.1.0-rc.1', '4.1.0-rc.2', true];
    }

    #[DataProvider('comparisonProvider')]
    public function testUpdateAvailabilityFollowsVersionOrder(string $current, string $published, bool $expected): void
    {
        $service = $this->service($current, $this->manifest($published));

        $status = $service->refresh();

        self::assertSame($expected, $status['updateAvailable']);
        self::assertSame($current, $status['currentVersion']);
        self::assertSame($published, $status['latestVersion']);
        self::assertNull($status['lastError']);
    }

    public function testYankedVersionIsNeverOffered(): void
    {
        $service = $this->service('4.0.12', $this->manifest('4.0.13', yanked: ['4.0.13']));

        $status = $service->refresh();

        self::assertFalse($status['updateAvailable']);
        self::assertNull($status['latestVersion']);
        // A withdrawn release is a normal outcome, not a failure.
        self::assertNull($status['lastError']);
        self::assertNotNull($status['lastCheckedAt']);
    }

    public function testYankedVersionIsNotEvenStored(): void
    {
        $this->rows[UpdateConfig::KEY_LATEST_VERSION] = '4.0.13';

        $this->service('4.0.12', $this->manifest('4.0.13', yanked: ['4.0.13']))->refresh();

        self::assertSame('', $this->rows[UpdateConfig::KEY_LATEST_VERSION]);
    }

    /**
     * @return iterable<string, array{0: MockResponse}>
     */
    public static function unusableManifestProvider(): iterable
    {
        yield 'empty' => [new MockResponse('')];
        yield 'malformed' => [new MockResponse('{"schema": 1, "stable":')];
        yield 'wrong schema' => [new MockResponse('{"schema": 99, "stable": {"version": "9.9.9"}}')];
        yield 'server error' => [new MockResponse('boom', ['http_code' => 500])];
        yield 'transport failure' => [new MockResponse('', ['error' => 'Connection refused'])];
    }

    #[DataProvider('unusableManifestProvider')]
    public function testUnusableManifestDegradesToNoUpdateAndRecordsTheError(MockResponse $response): void
    {
        $service = $this->service('4.0.12', $response);

        $status = $service->refresh();

        self::assertFalse($status['updateAvailable']);
        self::assertNull($status['latestVersion']);
        self::assertNotNull($status['lastError']);
        self::assertNotNull($status['lastCheckedAt']);
    }

    /**
     * A transient outage must not retract a notice that was already correct.
     */
    public function testAFailedCheckKeepsThePreviouslyKnownRelease(): void
    {
        $this->rows[UpdateConfig::KEY_LATEST_VERSION] = '4.0.13';

        $status = $this->service('4.0.12', new MockResponse('', ['error' => 'Connection refused']))->refresh();

        self::assertSame('4.0.13', $status['latestVersion']);
        self::assertTrue($status['updateAvailable']);
        self::assertNotNull($status['lastError']);
    }

    public function testASuccessfulCheckClearsAPreviousError(): void
    {
        $this->rows[UpdateConfig::KEY_LAST_ERROR] = 'Connection refused';

        $status = $this->service('4.0.12', $this->manifest('4.0.13'))->refresh();

        self::assertNull($status['lastError']);
    }

    /**
     * The master switch is the operator's guarantee that the installation makes
     * no outbound request at all — asserted on the HTTP client itself, not on a
     * stubbed manifest client.
     */
    public function testDisabledCheckMakesNoOutboundRequest(): void
    {
        $this->rows[UpdateConfig::KEY_CHECK_ENABLED] = '0';
        $httpClient = new MockHttpClient($this->manifest('4.0.13'));
        $service = $this->serviceWith('4.0.12', $httpClient);

        $status = $service->refresh(force: true);

        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertFalse($status['checkEnabled']);
        self::assertFalse($status['updateAvailable']);
        self::assertNull($status['lastCheckedAt']);
        self::assertArrayNotHasKey(UpdateConfig::KEY_LAST_CHECKED_AT, $this->rows);
    }

    public function testAnUnusableManifestUrlMakesNoOutboundRequest(): void
    {
        $this->rows[UpdateConfig::KEY_MANIFEST_URL] = 'file:///etc/passwd';
        $httpClient = new MockHttpClient($this->manifest('4.0.13'));

        $status = $this->serviceWith('4.0.12', $httpClient)->refresh();

        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertNotNull($status['lastError']);
    }

    /**
     * Reading must never trigger a check: the admin UI has to work offline.
     */
    public function testReadingTheStatusMakesNoOutboundRequest(): void
    {
        $httpClient = new MockHttpClient($this->manifest('4.0.13'));

        $status = $this->serviceWith('4.0.12', $httpClient)->getStatus();

        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertNull($status['latestVersion']);
        self::assertFalse($status['updateAvailable']);
    }

    /**
     * Every field falls back safely when the UPDATES group has no rows at all.
     */
    public function testEmptyConfigGroupYieldsSafeDefaults(): void
    {
        $status = $this->serviceWith('4.0.12', new MockHttpClient())->getStatus();

        self::assertSame([], $this->rows);
        self::assertSame('4.0.12', $status['currentVersion']);
        self::assertNull($status['latestVersion']);
        self::assertFalse($status['updateAvailable']);
        self::assertNull($status['notesUrl']);
        self::assertSame(ReleaseManifest::SEVERITY_NORMAL, $status['severity']);
        self::assertNull($status['releasedAt']);
        self::assertNull($status['lastCheckedAt']);
        self::assertNull($status['lastError']);
        self::assertNull($status['dismissedVersion']);
        self::assertTrue($status['checkEnabled']);
    }

    public function testMissingAppVersionReportsDev(): void
    {
        $service = new UpdateStatusService(
            new UpdateConfig($this->configRepository),
            new UpdateManifestClient(new MockHttpClient(), new ArrayAdapter(), new NullLogger()),
            new NullLogger(),
            '',
        );

        self::assertSame('dev', $service->getStatus()['currentVersion']);
    }

    public function testDismissStoresTheAcknowledgedVersion(): void
    {
        $service = $this->serviceWith('4.0.12', new MockHttpClient());

        $service->dismiss('  4.0.13  ');

        self::assertSame('4.0.13', $this->rows[UpdateConfig::KEY_DISMISSED_VERSION]);
        self::assertSame('4.0.13', $service->getStatus()['dismissedVersion']);
    }

    public function testTheMasterSwitchIsPersistedInTheSeederConvention(): void
    {
        $service = $this->serviceWith('4.0.12', new MockHttpClient());

        $service->setCheckEnabled(false);
        self::assertSame('0', $this->rows[UpdateConfig::KEY_CHECK_ENABLED]);
        self::assertFalse($service->getStatus()['checkEnabled']);

        $service->setCheckEnabled(true);
        self::assertSame('1', $this->rows[UpdateConfig::KEY_CHECK_ENABLED]);
        self::assertTrue($service->getStatus()['checkEnabled']);
    }

    public function testSecuritySeverityIsCarriedThroughToTheStatus(): void
    {
        $status = $this->service('4.0.12', $this->manifest('4.0.13', severity: 'security'))->refresh();

        self::assertSame(ReleaseManifest::SEVERITY_SECURITY, $status['severity']);
        self::assertSame('https://example.com/notes', $status['notesUrl']);
        self::assertSame('2026-08-10T09:00:00Z', $status['releasedAt']);
    }

    /**
     * @param list<string> $yanked
     */
    private function manifest(string $version, string $severity = 'normal', array $yanked = []): MockResponse
    {
        return new MockResponse((string) json_encode([
            'schema' => 1,
            'stable' => [
                'version' => $version,
                'releasedAt' => '2026-08-10T09:00:00Z',
                'notesUrl' => 'https://example.com/notes',
                'severity' => $severity,
            ],
            'yanked' => $yanked,
        ]));
    }

    private function service(string $currentVersion, MockResponse $response): UpdateStatusService
    {
        return $this->serviceWith($currentVersion, new MockHttpClient($response));
    }

    private function serviceWith(string $currentVersion, MockHttpClient $httpClient): UpdateStatusService
    {
        // No MANIFEST_URL row on purpose: the missing-row fallback to the
        // built-in default URL is part of what these tests cover, and
        // MockHttpClient answers whatever URL it is given.
        return new UpdateStatusService(
            new UpdateConfig($this->configRepository),
            new UpdateManifestClient($httpClient, new ArrayAdapter(), new NullLogger()),
            new NullLogger(),
            $currentVersion,
        );
    }
}
