<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Update;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Service\Update\ReleaseManifest;
use App\Service\Update\UpdateConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * BCONFIG seeder defaults are bootstrap-only: an install that upgraded into this
 * feature has NO rows in the UPDATES group until `app:seed` runs again. Every
 * read must therefore be safe with a missing row — and with a blank one, which
 * is how the seeder ships the result fields.
 */
final class UpdateConfigTest extends TestCase
{
    public function testEveryReadHasASafeFallbackWhenNoRowExists(): void
    {
        $config = $this->configWith([]);

        self::assertTrue($config->isCheckEnabled());
        self::assertSame(UpdateConfig::DEFAULT_MANIFEST_URL, $config->manifestUrl());
        self::assertNull($config->latestVersion());
        self::assertNull($config->latestNotesUrl());
        self::assertSame(ReleaseManifest::SEVERITY_NORMAL, $config->latestSeverity());
        self::assertNull($config->latestReleasedAt());
        self::assertNull($config->lastCheckedAt());
        self::assertNull($config->lastError());
        self::assertNull($config->dismissedVersion());
    }

    /**
     * The seeder writes the result fields as empty strings, so "present but
     * blank" has to behave exactly like "missing".
     */
    public function testBlankRowsBehaveLikeMissingRows(): void
    {
        $config = $this->configWith([
            UpdateConfig::KEY_LATEST_VERSION => '',
            UpdateConfig::KEY_LATEST_NOTES_URL => '   ',
            UpdateConfig::KEY_LATEST_SEVERITY => '',
            UpdateConfig::KEY_LATEST_RELEASED_AT => '',
            UpdateConfig::KEY_LAST_CHECKED_AT => '',
            UpdateConfig::KEY_LAST_ERROR => '',
            UpdateConfig::KEY_DISMISSED_VERSION => '',
            UpdateConfig::KEY_CHECK_ENABLED => '',
            UpdateConfig::KEY_MANIFEST_URL => '',
        ]);

        self::assertTrue($config->isCheckEnabled());
        self::assertSame(UpdateConfig::DEFAULT_MANIFEST_URL, $config->manifestUrl());
        self::assertNull($config->latestVersion());
        self::assertNull($config->latestNotesUrl());
        self::assertSame(ReleaseManifest::SEVERITY_NORMAL, $config->latestSeverity());
        self::assertNull($config->latestReleasedAt());
        self::assertNull($config->lastCheckedAt());
        self::assertNull($config->lastError());
        self::assertNull($config->dismissedVersion());
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function checkEnabledProvider(): iterable
    {
        yield 'seeder 1' => ['1', true];
        yield 'seeder 0' => ['0', false];
        yield 'admin true' => ['true', true];
        yield 'admin false' => ['false', false];
        yield 'garbage falls back to the default' => ['nonsense', true];
    }

    #[DataProvider('checkEnabledProvider')]
    public function testCheckEnabledAcceptsBothConventions(string $stored, bool $expected): void
    {
        $config = $this->configWith([UpdateConfig::KEY_CHECK_ENABLED => $stored]);

        self::assertSame($expected, $config->isCheckEnabled());
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function manifestUrlProvider(): iterable
    {
        yield 'a fork repoints the check' => [
            'https://fork.example.com/versions.json',
            'https://fork.example.com/versions.json',
        ];
        yield 'plain http is allowed for an internal mirror' => [
            'http://mirror.internal/versions.json',
            'http://mirror.internal/versions.json',
        ];
        yield 'file scheme is rejected' => ['file:///etc/passwd', null];
        yield 'not a url at all' => ['definitely not a url', null];
    }

    #[DataProvider('manifestUrlProvider')]
    public function testManifestUrlOnlyAcceptsHttpUrls(string $stored, ?string $expected): void
    {
        $config = $this->configWith([UpdateConfig::KEY_MANIFEST_URL => $stored]);

        self::assertSame($expected, $config->manifestUrl());
    }

    public function testAnUnusableStoredNotesUrlIsNotHandedToTheUi(): void
    {
        $config = $this->configWith([UpdateConfig::KEY_LATEST_NOTES_URL => 'javascript:alert(1)']);

        self::assertNull($config->latestNotesUrl());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function severityProvider(): iterable
    {
        yield 'unknown severity' => ['catastrophic', ReleaseManifest::SEVERITY_NORMAL];
        yield 'normal' => [ReleaseManifest::SEVERITY_NORMAL, ReleaseManifest::SEVERITY_NORMAL];
        yield 'security' => [ReleaseManifest::SEVERITY_SECURITY, ReleaseManifest::SEVERITY_SECURITY];
    }

    #[DataProvider('severityProvider')]
    public function testStoredSeverityIsNormalisedToAKnownValue(string $stored, string $expected): void
    {
        $config = $this->configWith([UpdateConfig::KEY_LATEST_SEVERITY => $stored]);

        self::assertSame($expected, $config->latestSeverity());
    }

    public function testRecordedErrorsAreCapped(): void
    {
        $written = [];
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getValue')->willReturn(null);
        $configRepository
            ->method('setValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $setting, string $value) use (&$written): Config {
                $written[$setting] = $value;

                return new Config();
            });

        (new UpdateConfig($configRepository))->recordFailedCheck(str_repeat('x', 5000), '2026-08-10T09:00:00+00:00');

        self::assertArrayHasKey(UpdateConfig::KEY_LAST_ERROR, $written);
        self::assertSame(500, mb_strlen($written[UpdateConfig::KEY_LAST_ERROR]));
        self::assertSame('2026-08-10T09:00:00+00:00', $written[UpdateConfig::KEY_LAST_CHECKED_AT]);
    }

    /**
     * @param array<string, string> $rows
     */
    private function configWith(array $rows): UpdateConfig
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository
            ->method('getValue')
            ->willReturnCallback(static function (int $ownerId, string $group, string $setting) use ($rows): ?string {
                self::assertSame(UpdateConfig::OWNER_ID, $ownerId);
                self::assertSame(UpdateConfig::CONFIG_GROUP, $group);

                return $rows[$setting] ?? null;
            });

        return new UpdateConfig($configRepository);
    }
}
