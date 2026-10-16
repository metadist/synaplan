<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Erasure;

use App\Entity\Credential;
use App\Repository\CredentialRepository;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Iam\Erasure\CredentialEraser;
use App\Service\Iam\Erasure\UserErasure;
use PHPUnit\Framework\TestCase;

/**
 * Whatever secret the account still owns when every domain has run is
 * forgotten through the vault inside the transaction.
 */
final class CredentialEraserTest extends TestCase
{
    public function testForgetsEverySecretTheAccountStillOwnsThroughTheVault(): void
    {
        $credentials = $this->createMock(CredentialRepository::class);
        $credentials->expects(self::once())->method('findBy')->with(['ownerId' => 42])
            ->willReturn([$this->credential(7), $this->credential(9)]);
        $forgotten = [];
        $vault = $this->createStub(CredentialVaultInterface::class);
        $vault->method('forget')->willReturnCallback(static function (int $credentialId, int $ownerId) use (&$forgotten): void {
            $forgotten[] = [$credentialId, $ownerId];
        });

        $erasure = new UserErasure(42, 'ada@example.test');
        (new CredentialEraser($credentials, $vault))->erase($erasure);

        self::assertSame([[7, 42], [9, 42]], $forgotten);
        self::assertSame([], $erasure->pendingCleanups());
    }

    private function credential(int $id): Credential
    {
        $credential = new Credential(42, 'webdav');
        (new \ReflectionProperty(Credential::class, 'id'))->setValue($credential, $id);

        return $credential;
    }
}
