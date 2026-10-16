<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\ComputeWorkspace;
use App\Entity\User;
use App\Repository\ComputeWorkspaceRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Workspace creation racing with account deletion. The erasing transaction
 * reads the user's workspaces with a lock, so a creation for that user waits
 * until the deletion commits or rolls back; and a creation that finishes
 * after the commit stores nothing for the deleted owner.
 */
final class ComputeWorkspaceRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ComputeWorkspaceRepository $workspaces;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->workspaces = static::getContainer()->get(ComputeWorkspaceRepository::class);
    }

    public function testACreationWaitsWhileTheErasingTransactionHoldsTheUsersWorkspaces(): void
    {
        $userId = random_int(900_000_000, 999_999_999);
        $erasing = $this->em->getConnection();
        $creating = DriverManager::getConnection($erasing->getParams());
        $creating->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        $erasing->beginTransaction();
        try {
            self::assertSame([], $this->workspaces->findForUserLocked($userId));

            $this->expectException(LockWaitTimeoutException::class);
            $creating->insert('BCOMPUTEWORKSPACES', [
                'BUSERID' => $userId,
                'BWORKSPACEID' => substr(strtoupper(bin2hex(random_bytes(13))), 0, 26),
                'BQUOTAMB' => 256,
                'BSTATUS' => ComputeWorkspace::STATUS_ACTIVE,
                'BCREATED' => '2026-10-16 12:00:00',
            ]);
        } finally {
            $erasing->rollBack();
            $creating->close();
        }
    }

    public function testAWorkspaceIsStoredForAnExistingOwner(): void
    {
        $user = new User();
        $user->setMail('compute-owner-'.bin2hex(random_bytes(4)).'@example.com');
        $user->setProviderId('local');
        $user->setPw('dummy_hash');
        $user->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $this->em->persist($user);
        $this->em->flush();

        self::assertTrue($this->workspaces->saveForExistingOwner(new ComputeWorkspace((int) $user->getId(), '01JOWNEREXISTS0000000000AA', 256)));
        self::assertNotNull($this->workspaces->findAccessibleForUser((int) $user->getId()));
    }

    public function testNothingIsStoredOnceTheOwnersAccountIsGone(): void
    {
        $deletedUserId = random_int(900_000_000, 999_999_999);

        self::assertFalse($this->workspaces->saveForExistingOwner(new ComputeWorkspace($deletedUserId, '01JOWNERGONE00000000000000', 256)));
        self::assertSame([], $this->workspaces->findBy(['userId' => $deletedUserId]));
    }
}
