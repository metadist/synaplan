<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Entity\Connection;

/**
 * The same yes/no the checklist used to compute in the browser.
 */
final class GettingStartedRules
{
    /**
     * A connection counts until it is explicitly disconnected. `never_tested`,
     * `error` and `reauth_required` still mean the account is linked.
     *
     * @param iterable<Connection> $connections
     *
     * @return array{m365: bool, dropbox: bool, webdav: bool}
     */
    public static function liveConnectionFlags(iterable $connections): array
    {
        $flags = ['m365' => false, 'dropbox' => false, 'webdav' => false];
        foreach ($connections as $connection) {
            if (Connection::STATUS_DISCONNECTED === $connection->getStatus()) {
                continue;
            }
            $type = $connection->getType();
            if (Connection::TYPE_M365 === $type) {
                $flags['m365'] = true;
            } elseif (Connection::TYPE_DROPBOX === $type) {
                $flags['dropbox'] = true;
            } elseif ('webdav' === $type || 'caldav' === $type) {
                $flags['webdav'] = true;
            }
        }

        return $flags;
    }

    /**
     * True when any app the directory would actually show is connected.
     * A feature that is off is absent from the directory, so its rows do not
     * count. Admins still see an unconfigured Microsoft 365 or Dropbox app,
     * and the coding-client app, the same way the directory does.
     */
    public static function anyAppConnected(GettingStartedFacts $facts, bool $isAdmin): bool
    {
        if ($facts->telegramConfigured && $facts->telegramConnected) {
            return true;
        }
        if ($facts->whatsappConfigured && $facts->whatsappBound) {
            return true;
        }
        if ($facts->widgetCount > 0 || '' !== $facts->emailKeyword || $facts->mailboxCount > 0) {
            return true;
        }
        if (($isAdmin || $facts->m365Available) && $facts->m365Connected) {
            return true;
        }
        if (($isAdmin || $facts->dropboxAvailable) && $facts->dropboxConnected) {
            return true;
        }
        if ($facts->webdavConnected) {
            return true;
        }
        if ($facts->platformLinksEnabled) {
            foreach (['nextcloud', 'owncloud', 'outlook'] as $client) {
                if (in_array($client, $facts->platformClients, true)) {
                    return true;
                }
            }
        }
        if (($isAdmin || $facts->gatewayEnabled) && $facts->anthropicUserKey) {
            return true;
        }
        if ($facts->higgsfieldConfigured && $facts->higgsfieldUser) {
            return true;
        }
        if ($facts->mcpCount > 0) {
            return true;
        }
        if ($facts->customToolsEnabled && $facts->customToolCount > 0) {
            return true;
        }
        if ($facts->desktopEnabled && $facts->desktopDeviceCount > 0) {
            return true;
        }

        return $facts->apiKeyCount > 0;
    }
}
