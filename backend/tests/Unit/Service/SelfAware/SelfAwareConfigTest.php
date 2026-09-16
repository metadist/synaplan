<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SelfAware;

use App\Repository\ConfigRepository;
use App\Service\SelfAware\SelfAwareConfig;
use PHPUnit\Framework\TestCase;

final class SelfAwareConfigTest extends TestCase
{
    public function testDefaultsAreOnAndFilterHidesTopicWhenDisabled(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);
        $config = new SelfAwareConfig($repo);

        $this->assertTrue($config->isEnabled(2));
        $this->assertTrue($config->isInventoryInGeneral(2));
        $this->assertTrue($config->isDocsRagEnabled(2));
        $this->assertSame(SelfAwareConfig::DEFAULT_DOCS_MANIFEST_URL, $config->docsManifestUrl());

        $topics = [
            ['topic' => 'general', 'description' => 'x'],
            ['topic' => 'synaplan', 'description' => 'y'],
        ];
        $this->assertCount(2, $config->filterRoutableTopics($topics, 2));
    }

    public function testDisabledDropsSynaplanTopic(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static function (int $owner, string $group, string $setting): ?string {
                if (SelfAwareConfig::CONFIG_GROUP === $group && SelfAwareConfig::KEY_ENABLED === $setting) {
                    return '0';
                }

                return null;
            }
        );
        $config = new SelfAwareConfig($repo);

        $this->assertFalse($config->isEnabled(2));
        $filtered = $config->filterRoutableTopics(['general', 'synaplan', 'officemaker'], 2);
        $this->assertSame(['general', 'officemaker'], $filtered);
    }
}
