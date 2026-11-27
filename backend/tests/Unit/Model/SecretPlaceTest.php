<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\SecretPlace;
use PHPUnit\Framework\TestCase;

final class SecretPlaceTest extends TestCase
{
    public function testAConfigRowIsNamedByOwnerGroupAndSetting(): void
    {
        $place = SecretPlace::config(7, 'provider_keys_user', 'openai');

        self::assertNotSame($place, SecretPlace::config(8, 'provider_keys_user', 'openai'), 'another owner');
        self::assertNotSame($place, SecretPlace::config(7, 'provider_keys', 'openai'), 'another group');
        self::assertNotSame($place, SecretPlace::config(7, 'provider_keys_user', 'anthropic'), 'another setting');
    }

    public function testGroupAndSettingAreMatchedWithoutCaseLikeTheDatabaseDoes(): void
    {
        self::assertSame(SecretPlace::config(0, 'M365', 'CLIENT_SECRET'), SecretPlace::config(0, 'm365', 'client_secret'));
    }

    public function testAGroupAndSettingCannotBeShiftedIntoEachOther(): void
    {
        self::assertNotSame(SecretPlace::column('A', 'B/C', 1), SecretPlace::column('A/B', 'C', 1));
        self::assertNotSame(SecretPlace::column('T', 'F', 12), SecretPlace::column('T', 'F1', 2));
    }

    public function testAColumnIsNamedByTableFieldAndOwner(): void
    {
        $place = SecretPlace::column('BMCPSERVERS', 'BAUTHTOKEN', 3);

        self::assertNotSame($place, SecretPlace::column('BMCPSERVERS', 'BOAUTH', 3));
        self::assertNotSame($place, SecretPlace::column('BMCPSERVERS', 'BAUTHTOKEN', 4));
        self::assertNotSame($place, SecretPlace::config(3, 'BMCPSERVERS', 'BAUTHTOKEN'));
    }
}
