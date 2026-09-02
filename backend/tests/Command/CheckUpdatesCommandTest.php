<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CheckUpdatesCommand;
use App\Entity\Config;
use App\Message\SyncPlatformDocsMessage;
use App\Repository\ConfigRepository;
use App\Service\Update\UpdateConfig;
use App\Service\Update\UpdateManifestClient;
use App\Service\Update\UpdateStatusService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The scheduler runs this once a day, unattended. An unreachable manifest is an
 * EXPECTED outcome and must stay a quiet success — only a genuinely unexpected
 * error may exit non-zero.
 */
final class CheckUpdatesCommandTest extends TestCase
{
    /** @var array<string, string> */
    private array $rows = [];

    private ConfigRepository&MockObject $configRepository;

    protected function setUp(): void
    {
        $this->rows = [];
        $this->configRepository = $this->createMock(ConfigRepository::class);
    }

    public function testReportsAnAvailableUpdate(): void
    {
        $this->stubConfigRepository();
        $tester = $this->testerFor('4.0.12', new MockResponse((string) json_encode([
            'schema' => 1,
            'stable' => ['version' => '4.0.13', 'severity' => 'security'],
        ])));

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Update available: 4.0.13', $tester->getDisplay());
        self::assertStringContainsString('severity security', $tester->getDisplay());
        self::assertSame('4.0.13', $this->rows[UpdateConfig::KEY_LATEST_VERSION]);
    }

    public function testReportsThatNoUpdateIsAvailable(): void
    {
        $this->stubConfigRepository();
        $tester = $this->testerFor('4.0.13', new MockResponse((string) json_encode([
            'schema' => 1,
            'stable' => ['version' => '4.0.13'],
        ])));

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No update available', $tester->getDisplay());
    }

    public function testANetworkFailureIsAQuietSuccess(): void
    {
        $this->stubConfigRepository();
        $tester = $this->testerFor('4.0.12', new MockResponse('', ['error' => 'Connection refused']));

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('could not complete', $tester->getDisplay());
        self::assertStringNotContainsString('Fatal', $tester->getDisplay());
        self::assertArrayHasKey(UpdateConfig::KEY_LAST_ERROR, $this->rows);
    }

    public function testADisabledCheckSaysSoAndFetchesNothing(): void
    {
        $this->stubConfigRepository();
        $this->rows[UpdateConfig::KEY_CHECK_ENABLED] = '0';
        $httpClient = new MockHttpClient(new MockResponse('{"schema": 1, "stable": {"version": "4.0.13"}}'));
        $tester = $this->testerWith('4.0.12', $httpClient);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('disabled', $tester->getDisplay());
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testForceBypassesTheCachedManifest(): void
    {
        $this->stubConfigRepository();
        $httpClient = new MockHttpClient([
            new MockResponse('{"schema": 1, "stable": {"version": "4.0.13"}}'),
            new MockResponse('{"schema": 1, "stable": {"version": "4.0.14"}}'),
        ]);
        $tester = $this->testerWith('4.0.12', $httpClient);

        $tester->execute([]);
        $tester->execute([]);
        self::assertSame(1, $httpClient->getRequestsCount());

        $tester->execute(['--force' => true]);

        self::assertSame(2, $httpClient->getRequestsCount());
        self::assertSame('4.0.14', $this->rows[UpdateConfig::KEY_LATEST_VERSION]);
    }

    public function testDispatchesDocsSyncWhenPublishedVersionChanges(): void
    {
        $this->stubConfigRepository();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(SyncPlatformDocsMessage::class))
            ->willReturn(new Envelope(new SyncPlatformDocsMessage()));

        $tester = $this->testerWith('4.0.12', new MockHttpClient(new MockResponse((string) json_encode([
            'schema' => 1,
            'stable' => ['version' => '4.0.13', 'severity' => 'security'],
        ]))), $bus);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testDoesNotDispatchDocsSyncWhenVersionIsUnchanged(): void
    {
        $this->stubConfigRepository();
        $this->rows[UpdateConfig::KEY_LATEST_VERSION] = '4.0.13';
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $tester = $this->testerWith('4.0.13', new MockHttpClient(new MockResponse((string) json_encode([
            'schema' => 1,
            'stable' => ['version' => '4.0.13'],
        ]))), $bus);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testAnUnexpectedErrorExitsNonZero(): void
    {
        $this->configRepository
            ->method('getValue')
            ->willThrowException(new \RuntimeException('MySQL server has gone away'));

        $tester = $this->testerFor('4.0.12', new MockResponse('{"schema": 1, "stable": {"version": "4.0.13"}}'));

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('MySQL server has gone away', $tester->getDisplay());
    }

    private function stubConfigRepository(): void
    {
        $this->configRepository
            ->method('getValue')
            ->willReturnCallback(fn (int $ownerId, string $group, string $setting): ?string => $this->rows[$setting] ?? null);

        $this->configRepository
            ->method('setValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $setting, string $value): Config {
                $this->rows[$setting] = $value;

                return new Config();
            });
    }

    private function testerFor(string $currentVersion, MockResponse $response): CommandTester
    {
        return $this->testerWith($currentVersion, new MockHttpClient($response));
    }

    private function testerWith(
        string $currentVersion,
        MockHttpClient $httpClient,
        ?MessageBusInterface $messageBus = null,
    ): CommandTester {
        $service = new UpdateStatusService(
            new UpdateConfig($this->configRepository),
            new UpdateManifestClient($httpClient, new ArrayAdapter(), new NullLogger()),
            new NullLogger(),
            $currentVersion,
        );

        $application = new Application();
        $application->addCommand(new CheckUpdatesCommand($service, $messageBus));

        return new CommandTester($application->find('app:updates:check'));
    }
}
