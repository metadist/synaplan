<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\PlatformInstance;
use App\Repository\PlatformInstanceRepository;
use App\Seed\PlatformLinksConfigSeeder;
use Doctrine\DBAL\Connection;
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

    /**
     * `doctrine:fixtures:load` purges BPLATFORMINSTANCES (CI does that before
     * `app:seed`), so the built-in Outlook row must survive through the seeder,
     * not only through the migration.
     */
    public function testOutlookBuiltinIsRestoredBySeeder(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $repo = $container->get(PlatformInstanceRepository::class);
        $seeder = $container->get(PlatformLinksConfigSeeder::class);
        $connection = $container->get(Connection::class);

        $connection->executeStatement(
            'DELETE FROM BPLATFORMINSTANCES WHERE BINSTANCEID = :id',
            ['id' => PlatformInstance::OUTLOOK_BUILTIN_ID],
        );
        $container->get('doctrine')->getManager()->clear();

        $first = $seeder->seed();
        self::assertGreaterThanOrEqual(1, $first->inserted, 'the instance row (and possibly the flag row) must be inserted');

        $outlook = $repo->findOutlookBuiltin();
        self::assertInstanceOf(PlatformInstance::class, $outlook);
        self::assertSame(PlatformInstance::CLIENT_OUTLOOK, $outlook->getClient());
        self::assertSame('*', $outlook->getHost());
        self::assertSame(PlatformInstance::OUTLOOK_BUILTIN_REDIRECT_URIS, $outlook->getRedirectUris());
        self::assertTrue($outlook->isActive());

        $second = $seeder->seed();
        self::assertSame(0, $second->inserted);
        self::assertSame(
            1,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM BPLATFORMINSTANCES WHERE BINSTANCEID = :id',
                ['id' => PlatformInstance::OUTLOOK_BUILTIN_ID],
            ),
        );
    }
}
