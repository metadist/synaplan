<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AdminUpdatesController;
use App\DTO\AdminUpdateDismissRequest;
use App\DTO\AdminUpdateSettingsRequest;
use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Service\Update\ReleaseManifest;
use App\Service\Update\UpdateConfig;
use App\Service\Update\UpdateManifestClient;
use App\Service\Update\UpdatePlatformGuide;
use App\Service\Update\UpdateStatusService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The /status payload is a published contract the admin UI is built against, so
 * its exact key set is asserted here rather than described in a comment.
 */
final class AdminUpdatesControllerTest extends TestCase
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
                return $this->rows[$setting] ?? null;
            });
        $this->configRepository
            ->method('setValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $setting, string $value): Config {
                $this->rows[$setting] = $value;

                return new Config();
            });
    }

    public function testStatusReturnsTheStoredValuesAndTheSelfHostGuide(): void
    {
        $this->rows = [
            UpdateConfig::KEY_LATEST_VERSION => '4.0.13',
            UpdateConfig::KEY_LATEST_NOTES_URL => 'https://github.com/metadist/synaplan/releases/tag/v4.0.13',
            UpdateConfig::KEY_LATEST_SEVERITY => ReleaseManifest::SEVERITY_SECURITY,
            UpdateConfig::KEY_LATEST_RELEASED_AT => '2026-08-10T09:00:00Z',
            UpdateConfig::KEY_LAST_CHECKED_AT => '2026-08-10T09:05:00+00:00',
        ];

        $payload = $this->decode($this->controller()->getStatus()->getContent());

        self::assertSame([
            'currentVersion' => '4.0.12',
            'latestVersion' => '4.0.13',
            'updateAvailable' => true,
            'notesUrl' => 'https://github.com/metadist/synaplan/releases/tag/v4.0.13',
            'severity' => ReleaseManifest::SEVERITY_SECURITY,
            'releasedAt' => '2026-08-10T09:00:00Z',
            'lastCheckedAt' => '2026-08-10T09:05:00+00:00',
            'lastError' => null,
            'dismissedVersion' => null,
            'checkEnabled' => true,
            'platform' => UpdatePlatformGuide::PLATFORM_SELFHOST,
            'guideUrl' => UpdatePlatformGuide::GUIDE_URL_SELFHOST,
        ], $payload);
    }

    public function testStatusOnAFreshInstallReportsNoUpdate(): void
    {
        $payload = $this->decode($this->controller()->getStatus()->getContent());

        self::assertFalse($payload['updateAvailable']);
        self::assertNull($payload['latestVersion']);
        self::assertNull($payload['lastCheckedAt']);
        self::assertTrue($payload['checkEnabled']);
    }

    public function testStatusLinksTheElestioGuideOnElestio(): void
    {
        $payload = $this->decode($this->controller(platform: 'elestio')->getStatus()->getContent());

        self::assertSame(UpdatePlatformGuide::PLATFORM_ELESTIO, $payload['platform']);
        self::assertSame(UpdatePlatformGuide::GUIDE_URL_ELESTIO, $payload['guideUrl']);
    }

    public function testCheckRefreshesAndReturnsTheSamePayloadShape(): void
    {
        $controller = $this->controller(new MockResponse((string) json_encode([
            'schema' => 1,
            'stable' => ['version' => '4.0.13', 'notesUrl' => 'https://example.com/notes'],
        ])));

        $payload = $this->decode($controller->check()->getContent());

        self::assertSame('4.0.13', $payload['latestVersion']);
        self::assertTrue($payload['updateAvailable']);
        self::assertNotNull($payload['lastCheckedAt']);
        self::assertSame(UpdatePlatformGuide::GUIDE_URL_SELFHOST, $payload['guideUrl']);
        self::assertSame('4.0.13', $this->rows[UpdateConfig::KEY_LATEST_VERSION]);
    }

    public function testDismissStoresTheAcknowledgedVersion(): void
    {
        $dto = new AdminUpdateDismissRequest();
        $dto->version = '4.0.13';

        $payload = $this->decode($this->controller()->dismiss($dto)->getContent());

        self::assertTrue($payload['success']);
        self::assertSame('4.0.13', $payload['dismissedVersion']);
        self::assertSame('4.0.13', $this->rows[UpdateConfig::KEY_DISMISSED_VERSION]);
    }

    public function testSettingsTogglesTheMasterSwitch(): void
    {
        $dto = new AdminUpdateSettingsRequest();
        $dto->checkEnabled = false;

        $payload = $this->decode($this->controller()->updateSettings($dto)->getContent());

        self::assertTrue($payload['success']);
        self::assertFalse($payload['checkEnabled']);
        self::assertSame('0', $this->rows[UpdateConfig::KEY_CHECK_ENABLED]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string|false $json): array
    {
        self::assertIsString($json);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function controller(?MockResponse $response = null, string $platform = 'selfhost'): AdminUpdatesController
    {
        $httpClient = null === $response ? new MockHttpClient() : new MockHttpClient($response);

        $controller = new AdminUpdatesController(
            new UpdateStatusService(
                new UpdateConfig($this->configRepository),
                new UpdateManifestClient($httpClient, new ArrayAdapter(), new NullLogger()),
                new NullLogger(),
                '4.0.12',
            ),
            new UpdatePlatformGuide($platform),
        );

        $container = new Container();
        $container->set('serializer', new class {
            public function serialize(mixed $data, string $format): string
            {
                return json_encode($data, \JSON_THROW_ON_ERROR);
            }
        });
        $controller->setContainer($container);

        return $controller;
    }
}
