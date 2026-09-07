<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\PlatformInstance;
use App\Repository\PlatformInstanceRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlatformInstanceRepositoryTest extends KernelTestCase
{
    public function testOutlookBuiltinIsActiveWithRedirectAllowList(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(PlatformInstanceRepository::class);
        $row = $repo->findOutlookBuiltin();

        self::assertInstanceOf(PlatformInstance::class, $row);
        self::assertSame(PlatformInstance::CLIENT_OUTLOOK, $row->getClient());
        self::assertSame('*', $row->getHost());
        self::assertSame('', $row->getSecretHash());
        self::assertTrue($row->isActive());
        self::assertContains('https://localhost', $row->getRedirectUris());
        self::assertContains('https://addin.synaplan.com', $row->getRedirectUris());
    }
}
