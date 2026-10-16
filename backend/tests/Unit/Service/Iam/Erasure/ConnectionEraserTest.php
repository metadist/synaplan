<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Erasure;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Repository\CustomToolRepository;
use App\Service\Credential\CredentialNotFoundException;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Iam\Erasure\ConnectionEraser;
use App\Service\Iam\Erasure\UserErasure;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Connections and custom tools go inside the deletion transaction. Each
 * connection's secret is forgotten through the vault, never by a raw delete,
 * and nothing waits for the commit.
 */
final class ConnectionEraserTest extends TestCase
{
    private const USER_ID = 42;

    /** @var list<object> */
    private array $removed = [];

    public function testForgetsEachConnectionSecretThroughTheVaultAndRemovesTheRows(): void
    {
        $withSecret = $this->connection('Nextcloud', 11);
        $withoutSecret = $this->connection('Webhook', null);
        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->expects(self::once())->method('forget')->with(11, self::USER_ID);
        $customTools = $this->createMock(CustomToolRepository::class);
        $customTools->expects(self::once())->method('deleteByOwner')->with(self::USER_ID);

        $erasure = $this->erase([$withSecret, $withoutSecret], $vault, $customTools);

        self::assertSame([$withSecret, $withoutSecret], $this->removed);
        self::assertSame([], $erasure->pendingCleanups());
    }

    public function testADanglingCredentialReferenceDoesNotStopTheDeletion(): void
    {
        $connection = $this->connection('Nextcloud', 11);
        $vault = $this->createStub(CredentialVaultInterface::class);
        $vault->method('forget')->willThrowException(new CredentialNotFoundException(11));

        $this->erase([$connection], $vault);

        self::assertSame([$connection], $this->removed);
    }

    public function testAVaultFailureReachesTheDeletionSoTheTransactionRollsBack(): void
    {
        $vault = $this->createStub(CredentialVaultInterface::class);
        $vault->method('forget')->willThrowException(new \RuntimeException('Deadlock found when trying to get lock'));

        $this->expectExceptionMessage('Deadlock found when trying to get lock');

        $this->erase([$this->connection('Nextcloud', 11)], $vault);
    }

    /**
     * @param list<Connection> $connections
     */
    private function erase(array $connections, CredentialVaultInterface $vault, ?CustomToolRepository $customTools = null): UserErasure
    {
        $repository = $this->createStub(ConnectionRepository::class);
        $repository->method('findByOwner')->willReturnMap([[self::USER_ID, $connections]]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(function (object $row): void {
            $this->removed[] = $row;
        });

        $erasure = new UserErasure(self::USER_ID, 'ada@example.test');
        (new ConnectionEraser($em, $repository, $customTools ?? $this->createStub(CustomToolRepository::class), $vault))->erase($erasure);

        return $erasure;
    }

    private function connection(string $name, ?int $credentialId): Connection
    {
        return (new Connection(self::USER_ID, 'webdav', $name))->setCredentialId($credentialId);
    }
}
