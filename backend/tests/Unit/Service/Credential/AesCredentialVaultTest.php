<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Credential;

use App\Entity\Credential;
use App\Repository\CredentialRepository;
use App\Service\Credential\AesCredentialVault;
use App\Service\Credential\CredentialNotFoundException;
use App\Service\EncryptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AesCredentialVaultTest extends TestCase
{
    private const SECRET = 'super-secret-mailbox-password';

    private CredentialRepository&MockObject $repository;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logRecords = [];

    private AesCredentialVault $vault;

    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CredentialRepository::class);
        $this->encryption = new EncryptionService('unit-test-app-secret', $this->createStub(LoggerInterface::class));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $message, array $context = []): void {
            $this->logRecords[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
            $this->logRecords[] = ['level' => 'error', 'message' => $message, 'context' => $context];
        });

        $this->vault = new AesCredentialVault($this->repository, $this->encryption, $logger);
    }

    public function testRoundTripEncryptsAndReveals(): void
    {
        $stored = null;
        $this->repository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (Credential $credential) use (&$stored): void {
                $this->setId($credential, 42);
                $stored = $credential;
            });

        $id = $this->vault->store(7, 'mailbox', self::SECRET);
        $this->assertSame(42, $id);
        $this->assertNotNull($stored);
        $this->assertNotSame(self::SECRET, $stored->getSecret());

        $this->repository->method('findByIdAndOwner')->willReturn($stored);
        $this->assertSame(self::SECRET, $this->vault->reveal(42, 7));
    }

    public function testRotateReplacesCiphertext(): void
    {
        $credential = new Credential(7, 'mailbox');
        $this->setId($credential, 3);
        $credential->setSecret($this->encryption->encrypt('old-secret'));

        $this->repository->method('findByIdAndOwner')->willReturn($credential);
        $this->repository->expects($this->once())->method('save');

        $this->vault->rotate(3, 7, 'new-secret');
        $this->assertSame('new-secret', $this->encryption->decrypt($credential->getSecret()));
    }

    public function testForgetRemovesRow(): void
    {
        $credential = new Credential(7, 'mailbox');
        $this->setId($credential, 9);
        $this->repository->method('findByIdAndOwner')->willReturn($credential);
        $this->repository->expects($this->once())->method('remove');

        $this->vault->forget(9, 7);
    }

    public function testUnknownCredentialThrows(): void
    {
        $this->repository->method('findByIdAndOwner')->willReturn(null);
        $this->expectException(CredentialNotFoundException::class);
        $this->vault->reveal(1, 7);
    }

    public function testLogsNeverContainTheSecret(): void
    {
        $this->repository->method('save')->willReturnCallback(function (Credential $credential): void {
            $this->setId($credential, 1);
        });

        $this->vault->store(7, 'mailbox', self::SECRET);

        $encoded = json_encode($this->logRecords, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::SECRET, $encoded);
        $this->assertStringNotContainsString('old-secret', $encoded);
    }

    private function setId(Credential $credential, int $id): void
    {
        $ref = new \ReflectionProperty(Credential::class, 'id');
        $ref->setValue($credential, $id);
    }
}
