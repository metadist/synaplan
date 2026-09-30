<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SmartSearch;

use App\Service\SmartSearch\Provider\SettingMatcher;
use PHPUnit\Framework\TestCase;

final class SettingMatcherTest extends TestCase
{
    private const KEY = 'FEATURE_IAM_GROUPS_ENABLED';
    private const LABELS = 'Features People & sharing';
    private const DESCRIPTION = 'Let people create groups and share with them.';

    public function testExactKeyWins(): void
    {
        $exact = SettingMatcher::score('FEATURE_IAM_GROUPS_ENABLED', self::KEY, self::LABELS, self::DESCRIPTION);
        $words = SettingMatcher::score('iam groups', self::KEY, self::LABELS, self::DESCRIPTION);

        self::assertSame(100.0, $exact);
        self::assertGreaterThan(0, $words);
        self::assertGreaterThan($words, $exact);
    }

    public function testKeyWordsOutrankDescriptionWords(): void
    {
        $keyHit = SettingMatcher::score('groups', self::KEY, self::LABELS, self::DESCRIPTION);
        $descriptionHit = SettingMatcher::score('share', 'FEATURE_OTHER', 'Features Other', 'Share things with people.');

        self::assertGreaterThan($descriptionHit, $keyHit);
    }

    public function testKeyPrefixMatches(): void
    {
        self::assertGreaterThan(0, SettingMatcher::score('grou', self::KEY, self::LABELS, self::DESCRIPTION));
    }

    public function testSwitchingVerbsDoNotMatchEveryToggle(): void
    {
        self::assertSame(0.0, SettingMatcher::score('enable', 'REGISTRATION_ENABLED', 'Users Sign-up', 'Allow new accounts.'));
        self::assertSame(
            SettingMatcher::score('groups', self::KEY, self::LABELS, self::DESCRIPTION),
            SettingMatcher::score('turn on groups', self::KEY, self::LABELS, self::DESCRIPTION),
        );
        self::assertGreaterThan(0, SettingMatcher::score('Gruppen einschalten groups', self::KEY, self::LABELS, self::DESCRIPTION));
    }

    public function testMostQueryWordsMustMatch(): void
    {
        self::assertSame(0.0, SettingMatcher::score('turn on the weather forecast', self::KEY, self::LABELS, self::DESCRIPTION));
        self::assertSame(0.0, SettingMatcher::score('', self::KEY, self::LABELS, self::DESCRIPTION));
    }
}
