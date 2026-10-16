<?php

declare(strict_types=1);

namespace App\Service\Chat;

/**
 * What the getting-started checklist can see about connected apps.
 *
 * Each flag matches one app in the directory (`frontend/src/apps/catalog.ts`
 * plus `frontend/src/apps/status.ts`). A flag stays false when that check
 * fails: an unknown state is not "connected".
 */
final class GettingStartedFacts
{
    public bool $telegramConfigured = false;

    public bool $telegramConnected = false;

    public bool $whatsappConfigured = false;

    public bool $whatsappBound = false;

    public int $widgetCount = 0;

    public string $emailKeyword = '';

    public int $mailboxCount = 0;

    public bool $m365Available = false;

    public bool $m365Connected = false;

    public bool $dropboxAvailable = false;

    public bool $dropboxConnected = false;

    public bool $webdavConnected = false;

    public bool $platformLinksEnabled = false;

    /** @var list<string> */
    public array $platformClients = [];

    public bool $gatewayEnabled = false;

    public bool $anthropicUserKey = false;

    public bool $higgsfieldConfigured = false;

    public bool $higgsfieldUser = false;

    public int $mcpCount = 0;

    public bool $customToolsEnabled = false;

    public int $customToolCount = 0;

    public bool $desktopEnabled = false;

    public int $desktopDeviceCount = 0;

    public int $apiKeyCount = 0;
}
