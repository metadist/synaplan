<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Iam;

use App\Entity\Connection;
use App\Entity\Credential;
use App\Entity\File;
use App\Entity\User;
use App\Service\Credential\AesCredentialVault;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\File\FileStorageService;
use App\Service\Iam\UserDeletionService;
use Doctrine\DBAL\Connection as Database;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A vault failure rolls the whole account deletion back, early or late: the
 * account, its secrets and its files are all still there, the stored bytes
 * included, so the next attempt can finish the job.
 */
final class UserErasureVaultFailureTest extends KernelTestCase
{
    private const VAULT_ERROR = 'Lock wait timeout exceeded';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $failingVault = $this->createStub(CredentialVaultInterface::class);
        $failingVault->method('forget')->willThrowException(new \RuntimeException(self::VAULT_ERROR));
        static::getContainer()->set(AesCredentialVault::class, $failingVault);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAFailureOnAConnectionSecretLeavesTheAccountAndTheSecretInPlace(): void
    {
        $user = $this->persistUser();
        $userId = (int) $user->getId();
        $credential = $this->persistCredential($userId);
        $this->persist((new Connection($userId, 'webdav', 'Nextcloud'))->setCredentialId($credential->getId()));

        $this->deleteExpectingTheVaultFailure($user);

        $db = $this->em->getConnection();
        self::assertSame(1, $this->rowsWithId($db, 'BUSER', 'BID', $userId));
        self::assertSame(1, $this->rowsWithId($db, 'BCONNECTIONS', 'BOWNERID', $userId));
        self::assertSame(1, $this->rowsWithId($db, 'BCREDENTIALS', 'BOWNERID', $userId));
    }

    public function testAFailureInTheFinalCredentialSweepKeepsTheStoredFileOnDisk(): void
    {
        $user = $this->persistUser();
        $userId = (int) $user->getId();
        $this->persistCredential($userId);
        $storage = static::getContainer()->get(FileStorageService::class);
        $relativePath = 'erasure-test/'.bin2hex(random_bytes(6)).'.txt';
        $absolutePath = $storage->getAbsolutePath($relativePath);
        @mkdir(dirname($absolutePath), 0775, true);
        file_put_contents($absolutePath, 'quarterly report');
        $this->persist((new File())->setUserId($userId)->setFilePath($relativePath)->setFileName('report.txt')
            ->setFileType('txt')->setFileMime('text/plain'));

        try {
            $this->deleteExpectingTheVaultFailure($user);

            self::assertSame(1, $this->rowsWithId($this->em->getConnection(), 'BFILES', 'BUSERID', $userId));
            self::assertFileExists($absolutePath, 'A rolled-back deletion must not have deleted the stored file.');
        } finally {
            @unlink($absolutePath);
            @rmdir(dirname($absolutePath));
        }
    }

    private function deleteExpectingTheVaultFailure(User $user): void
    {
        try {
            static::getContainer()->get(UserDeletionService::class)->deleteUser($user);
            self::fail('The vault failure must reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame(self::VAULT_ERROR, $e->getMessage());
        }
    }

    private function persistUser(): User
    {
        $user = new User();
        $user->setMail('erasure-vault-'.bin2hex(random_bytes(4)).'@example.com');
        $user->setProviderId('local');
        $user->setPw('dummy_hash');
        $user->setUserLevel('NEW');
        $user->setEmailVerified(true);
        $user->setCreated(date('YmdHis'));

        return $this->persist($user);
    }

    private function persistCredential(int $userId): Credential
    {
        return $this->persist((new Credential($userId, 'webdav'))->setSecret('ciphertext'));
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }

    private function rowsWithId(Database $db, string $table, string $column, int $id): int
    {
        return (int) $db->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $table, $column), [$id]);
    }
}
