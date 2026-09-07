<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\PlatformInstance;
use App\Repository\PlatformInstanceRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlatformInstanceRepositoryTest extends KernelTestCase
{
    public function testSaveAndFindByInstanceId(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(PlatformInstanceRepository::class);
        $id = 'pi_'.bin2hex(random_bytes(12));

        $instance = new PlatformInstance(
            PlatformInstance::CLIENT_NEXTCLOUD,
            $id,
            'files.example.org',
            ['https://files.example.org/cb'],
            PlatformInstance::STATUS_ACTIVE,
            0,
        );
        $instance->setSecretHash('hash');
        $repo->save($instance);

        $found = $repo->findByInstanceId($id);
        self::assertInstanceOf(PlatformInstance::class, $found);
        self::assertSame('files.example.org', $found->getHost());
        self::assertSame(['https://files.example.org/cb'], $found->getRedirectUris());
    }

    public function testOutlookBuiltinIsSeeded(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(PlatformInstanceRepository::class);
        $outlook = $repo->findOutlookBuiltin();
        self::assertInstanceOf(PlatformInstance::class, $outlook);
        self::assertSame(PlatformInstance::CLIENT_OUTLOOK, $outlook->getClient());
        self::assertTrue($outlook->isActive());
    }
}
