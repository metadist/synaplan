<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Service\PlatformLink\CloudFolderLink;
use App\Service\PlatformLink\RedirectUriPolicy;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;

final class CloudFolderLinkTest extends TestCase
{
    public function testANextcloudFolderOpensInItsFilesApp(): void
    {
        self::assertSame(
            'https://files.example.org/index.php/apps/files/?dir=%2FInvoices%2F2026',
            $this->link('prod')->to('nextcloud', 'files.example.org', 'Invoices/2026'),
        );
    }

    public function testALocalDevCloudOpensOverHttpOnlyInDev(): void
    {
        self::assertSame(
            'http://localhost:8081/index.php/apps/files/?dir=%2FDocs',
            $this->link('dev')->to('owncloud', 'localhost:8081', '/Docs/'),
        );
        self::assertStringStartsWith('https://', (string) $this->link('prod')->to('nextcloud', 'localhost:8081', 'Docs'));
    }

    public function testACloudWithoutAKnownFilesAddressGetsNoLink(): void
    {
        self::assertNull($this->link('prod')->to('opencloud', 'cloud.example.org', 'Docs'));
        self::assertNull($this->link('prod')->to('nextcloud', '', 'Docs'));
        self::assertNull($this->link('prod')->to('nextcloud', 'evil.example.org/path', 'Docs'));
    }

    private function link(string $environment): CloudFolderLink
    {
        return new CloudFolderLink(new RedirectUriPolicy(new SsrfGuard(), $environment));
    }
}
