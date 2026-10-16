<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Iam;

use App\Entity\Connection;
use App\Entity\Credential;
use App\Entity\User;
use App\Service\Credential\AesCredentialVault;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Iam\UserDeletionService;
use Doctrine\DBAL\Connection as Database;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A vault that fails while a connection's secret is forgotten rolls the whole
 * account deletion back: the account, the connection and its secret are all
 * still there, so the next attempt can finish the job.
 */
final class UserErasureVaultFailureTest extends KernelTestCase
{
    public function testAVaultFailureLeavesTheAccountAndItsSecretInPlace(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $failingVault = $this->createStub(CredentialVaultInterface::class);
        $failingVault->method('forget')->willThrowException(new \RuntimeException('Lock wait timeout exceeded'));
        $container->set(AesCredentialVault::class, $failingVault);

        $em = $container->get(EntityManagerInterface::class);
        $user = $this->persistUser($em);
        $userId = (int) $user->getId();
        $credential = new Credential($userId, 'webdav');
        $credential->setSecret('ciphertext');
        $em->persist($credential);
        $em->flush();
        $connection = (new Connection($userId, 'webdav', 'Nextcloud'))->setCredentialId($credential->getId());
        $em->persist($connection);
        $em->flush();

        try {
            $container->get(UserDeletionService::class)->deleteUser($user);
            self::fail('The vault failure must reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('Lock wait timeout exceeded', $e->getMessage());
        }

        $db = $em->getConnection();
        self::assertSame(1, $this->rowsWithId($db, 'BUSER', 'BID', $userId));
        self::assertSame(1, $this->rowsWithId($db, 'BCONNECTIONS', 'BOWNERID', $userId));
        self::assertSame(1, $this->rowsWithId($db, 'BCREDENTIALS', 'BOWNERID', $userId));
    }

    private function persistUser(EntityManagerInterface $em): User
    {
        $user = new User();
        $user->setMail('erasure-vault-'.bin2hex(random_bytes(4)).'@example.com');
        $user->setProviderId('local');
        $user->setPw('dummy_hash');
        $user->setUserLevel('NEW');
        $user->setEmailVerified(true);
        $user->setCreated(date('YmdHis'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function rowsWithId(Database $db, string $table, string $column, int $id): int
    {
        return (int) $db->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $table, $column), [$id]);
    }
}
