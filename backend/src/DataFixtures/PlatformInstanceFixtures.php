<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\PlatformInstance;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * One approved platform instance for the cloud apps' dev and test stacks.
 *
 * A cloud app (OpenCloud, Nextcloud) needs an instance credential before
 * copies, folder reads and watches work. In production the cloud registers
 * itself and an administrator approves it. A test stack cannot wait for
 * that click, and minting a credential at run time would put a generated
 * secret into a CI log or an environment — so the stack ships one fixed,
 * well-known test credential instead, like the demo logins.
 *
 * Fixtures never run in production ({@see docs/MIGRATIONS.md}).
 */
class PlatformInstanceFixtures extends Fixture
{
    /** The id and secret the cloud apps' CI passes as their instance credential. */
    public const TEST_INSTANCE_ID = 'pi_test_opencloud_dev_stack';
    public const TEST_INSTANCE_SECRET = 'test-opencloud-instance-secret';

    /** The OpenCloud of the synaplan-opencloud dev stack. */
    private const TEST_HOST = 'host.docker.internal:9200';

    /** The fixture administrator's id ({@see UserFixtures}); a plain column, not a foreign key. */
    private const REGISTERED_BY_ADMIN = 1;

    public function load(ObjectManager $manager): void
    {
        $instance = new PlatformInstance(
            'opencloud',
            self::TEST_INSTANCE_ID,
            self::TEST_HOST,
            ['https://'.self::TEST_HOST],
            PlatformInstance::STATUS_ACTIVE,
            self::REGISTERED_BY_ADMIN,
        );
        $instance->setSecretHash(password_hash(self::TEST_INSTANCE_SECRET, \PASSWORD_DEFAULT));

        $manager->persist($instance);
        $manager->flush();
    }
}
