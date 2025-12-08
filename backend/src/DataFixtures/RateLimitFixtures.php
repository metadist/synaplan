<?php

namespace App\DataFixtures;

use App\Entity\Config;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Rate Limiting Configuration Fixtures.
 *
 * SIMPLIFIED:
 * - NEW = lifetime totals (no reset)
 * - PAID (PRO, TEAM, BUSINESS) = hourly + monthly
 */
class RateLimitFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Clean existing rate limit configs
        $this->createConfig($manager, 0, 'SYSTEM_FLAGS', 'SMART_RATE_LIMITING_ENABLED', '1');
        $this->createConfig($manager, 0, 'SYSTEM_FLAGS', 'RATE_LIMITING_DEBUG_MODE', '0');

        // ANONYMOUS User Limits (NOT PHONE VERIFIED - VERY RESTRICTED)
        $this->createConfig($manager, 0, 'RATELIMITS_ANONYMOUS', 'MESSAGES_TOTAL', '10'); // 10 messages total
        $this->createConfig($manager, 0, 'RATELIMITS_ANONYMOUS', 'IMAGES_TOTAL', '2'); // 2 images total
        $this->createConfig($manager, 0, 'RATELIMITS_ANONYMOUS', 'VIDEOS_TOTAL', '0'); // No videos
        $this->createConfig($manager, 0, 'RATELIMITS_ANONYMOUS', 'AUDIOS_TOTAL', '0'); // No audio
        $this->createConfig($manager, 0, 'RATELIMITS_ANONYMOUS', 'FILE_ANALYSIS_TOTAL', '3'); // 3 file analyses
        $this->createConfig($manager, 0, 'RATELIMITS_ANONYMOUS', 'FILE_UPLOADS_TOTAL', '3'); // 3 file uploads total
        $this->createConfig($manager, 0, 'RATELIMITS_ANONYMOUS', 'STORAGE_MB', '10'); // 10 MB storage

        // NEW User Limits (PHONE VERIFIED - LIFETIME TOTALS - NEVER RESET)
        $this->createConfig($manager, 0, 'RATELIMITS_NEW', 'MESSAGES_TOTAL', '50');
        $this->createConfig($manager, 0, 'RATELIMITS_NEW', 'IMAGES_TOTAL', '5');
        $this->createConfig($manager, 0, 'RATELIMITS_NEW', 'VIDEOS_TOTAL', '2');
        $this->createConfig($manager, 0, 'RATELIMITS_NEW', 'AUDIOS_TOTAL', '3');
        $this->createConfig($manager, 0, 'RATELIMITS_NEW', 'FILE_ANALYSIS_TOTAL', '10');
        $this->createConfig($manager, 0, 'RATELIMITS_NEW', 'FILE_UPLOADS_TOTAL', '10'); // 10 file uploads total
        $this->createConfig($manager, 0, 'RATELIMITS_NEW', 'STORAGE_MB', '100'); // 100 MB storage

        // Pro Level Limits (HOURLY + MONTHLY)
        $this->createConfig($manager, 0, 'RATELIMITS_PRO', 'MESSAGES_HOURLY', '100');
        $this->createConfig($manager, 0, 'RATELIMITS_PRO', 'MESSAGES_MONTHLY', '5000');
        $this->createConfig($manager, 0, 'RATELIMITS_PRO', 'IMAGES_MONTHLY', '50');
        $this->createConfig($manager, 0, 'RATELIMITS_PRO', 'VIDEOS_MONTHLY', '10');
        $this->createConfig($manager, 0, 'RATELIMITS_PRO', 'AUDIOS_MONTHLY', '20');
        $this->createConfig($manager, 0, 'RATELIMITS_PRO', 'FILE_ANALYSIS_MONTHLY', '200');
        $this->createConfig($manager, 0, 'RATELIMITS_PRO', 'FILE_UPLOADS_MONTHLY', '200'); // 200 uploads per month
        $this->createConfig($manager, 0, 'RATELIMITS_PRO', 'STORAGE_GB', '5'); // 5 GB storage

        // Team Level Limits (HOURLY + MONTHLY)
        $this->createConfig($manager, 0, 'RATELIMITS_TEAM', 'MESSAGES_HOURLY', '300');
        $this->createConfig($manager, 0, 'RATELIMITS_TEAM', 'MESSAGES_MONTHLY', '15000');
        $this->createConfig($manager, 0, 'RATELIMITS_TEAM', 'IMAGES_MONTHLY', '200');
        $this->createConfig($manager, 0, 'RATELIMITS_TEAM', 'VIDEOS_MONTHLY', '50');
        $this->createConfig($manager, 0, 'RATELIMITS_TEAM', 'AUDIOS_MONTHLY', '100');
        $this->createConfig($manager, 0, 'RATELIMITS_TEAM', 'FILE_ANALYSIS_MONTHLY', '1000');
        $this->createConfig($manager, 0, 'RATELIMITS_TEAM', 'FILE_UPLOADS_MONTHLY', '1000'); // 1000 uploads per month
        $this->createConfig($manager, 0, 'RATELIMITS_TEAM', 'STORAGE_GB', '20'); // 20 GB storage

        // Business Level Limits (HOURLY + MONTHLY)
        $this->createConfig($manager, 0, 'RATELIMITS_BUSINESS', 'MESSAGES_HOURLY', '1000');
        $this->createConfig($manager, 0, 'RATELIMITS_BUSINESS', 'MESSAGES_MONTHLY', '50000');
        $this->createConfig($manager, 0, 'RATELIMITS_BUSINESS', 'IMAGES_MONTHLY', '1000');
        $this->createConfig($manager, 0, 'RATELIMITS_BUSINESS', 'VIDEOS_MONTHLY', '200');
        $this->createConfig($manager, 0, 'RATELIMITS_BUSINESS', 'AUDIOS_MONTHLY', '500');
        $this->createConfig($manager, 0, 'RATELIMITS_BUSINESS', 'FILE_ANALYSIS_MONTHLY', '5000');
        $this->createConfig($manager, 0, 'RATELIMITS_BUSINESS', 'FILE_UPLOADS_MONTHLY', '5000'); // 5000 uploads per month
        $this->createConfig($manager, 0, 'RATELIMITS_BUSINESS', 'STORAGE_GB', '100'); // 100 GB storage

        $manager->flush();
    }

    private function createConfig(
        ObjectManager $manager,
        int $ownerId,
        string $group,
        string $setting,
        string $value,
    ): void {
        $config = new Config();
        $config->setOwnerId($ownerId);
        $config->setGroup($group);
        $config->setSetting($setting);
        $config->setValue($value);

        $manager->persist($config);
    }
}
