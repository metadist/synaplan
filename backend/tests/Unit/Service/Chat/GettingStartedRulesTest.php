<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat;

use App\Entity\Connection;
use App\Service\Chat\GettingStartedFacts;
use App\Service\Chat\GettingStartedRules;
use PHPUnit\Framework\TestCase;

final class GettingStartedRulesTest extends TestCase
{
    public function testDisconnectedRowsDoNotCount(): void
    {
        $live = new Connection(1, Connection::TYPE_M365, 'Work');
        $live->setStatus(Connection::STATUS_CONNECTED);
        $gone = new Connection(1, Connection::TYPE_DROPBOX, 'Old');
        $gone->setStatus(Connection::STATUS_DISCONNECTED);
        $dav = new Connection(1, 'caldav', 'Calendar');

        self::assertSame(
            ['m365' => true, 'dropbox' => false, 'webdav' => true],
            GettingStartedRules::liveConnectionFlags([$live, $gone, $dav])
        );
    }

    public function testNothingConnected(): void
    {
        self::assertFalse(GettingStartedRules::anyAppConnected(new GettingStartedFacts(), false));
    }

    public function testASwitchedOffModuleDoesNotCount(): void
    {
        $facts = new GettingStartedFacts();
        $facts->telegramConnected = true;
        $facts->whatsappBound = true;
        $facts->higgsfieldUser = true;
        $facts->customToolCount = 2;
        $facts->desktopDeviceCount = 1;
        $facts->platformClients = ['nextcloud'];
        $facts->anthropicUserKey = true;
        $facts->m365Connected = true;

        self::assertFalse(GettingStartedRules::anyAppConnected($facts, false));
    }

    public function testAnAdminSeesAnUnconfiguredMicrosoftConnection(): void
    {
        $facts = new GettingStartedFacts();
        $facts->m365Connected = true;

        self::assertFalse(GettingStartedRules::anyAppConnected($facts, false));
        self::assertTrue(GettingStartedRules::anyAppConnected($facts, true));
    }

    public function testAConfiguredAppCountsForEveryone(): void
    {
        $facts = new GettingStartedFacts();
        $facts->telegramConfigured = true;
        $facts->telegramConnected = true;

        self::assertTrue(GettingStartedRules::anyAppConnected($facts, false));
    }

    public function testEverydayAppsCountWithoutAFeatureFlag(): void
    {
        $withKeyword = new GettingStartedFacts();
        $withKeyword->emailKeyword = 'notes';
        self::assertTrue(GettingStartedRules::anyAppConnected($withKeyword, false));

        $withWidget = new GettingStartedFacts();
        $withWidget->widgetCount = 1;
        self::assertTrue(GettingStartedRules::anyAppConnected($withWidget, false));

        $withKey = new GettingStartedFacts();
        $withKey->apiKeyCount = 1;
        self::assertTrue(GettingStartedRules::anyAppConnected($withKey, false));
    }

    public function testPlatformLinksAndTheGatewayStayHiddenUntilTheyAreOn(): void
    {
        $links = new GettingStartedFacts();
        $links->platformClients = ['outlook'];
        self::assertFalse(GettingStartedRules::anyAppConnected($links, false));
        $links->platformLinksEnabled = true;
        self::assertTrue(GettingStartedRules::anyAppConnected($links, false));

        $gateway = new GettingStartedFacts();
        $gateway->anthropicUserKey = true;
        self::assertFalse(GettingStartedRules::anyAppConnected($gateway, false));
        self::assertTrue(GettingStartedRules::anyAppConnected($gateway, true));
        $gateway = new GettingStartedFacts();
        $gateway->gatewayEnabled = true;
        $gateway->anthropicUserKey = true;
        self::assertTrue(GettingStartedRules::anyAppConnected($gateway, false));
    }
}
