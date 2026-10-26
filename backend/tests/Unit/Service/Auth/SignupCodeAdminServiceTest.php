<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\SignupCode;
use App\Entity\User;
use App\Repository\SignupCodeRepository;
use App\Repository\UserRepository;
use App\Service\Auth\SignupCodeAdminService;
use App\Service\Iam\AuditLogWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A sign-up code and its audit row commit together: an audit failure must
 * not leave a live code behind whose plain form never reached anyone.
 */
final class SignupCodeAdminServiceTest extends TestCase
{
    public function testTheCodeIsSavedInsideTheTransactionThatAlsoWritesTheAuditRow(): void
    {
        $inTransaction = false;
        $savedInTransaction = null;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static function (callable $work) use (&$inTransaction): mixed {
                $inTransaction = true;
                try {
                    return $work();
                } finally {
                    $inTransaction = false;
                }
            }
        );

        $codes = $this->createMock(SignupCodeRepository::class);
        $codes->method('findOneBy')->willReturn(null);
        $codes->expects(self::once())->method('save')->willReturnCallback(
            static function (SignupCode $code) use (&$inTransaction, &$savedInTransaction): void {
                $savedInTransaction = $inTransaction;
            }
        );

        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::once())->method('record')
            ->willThrowException(new \RuntimeException('audit table is gone'));

        $service = new SignupCodeAdminService($codes, $this->createStub(UserRepository::class), $audit, $em);
        $actor = $this->createStub(User::class);
        $actor->method('getId')->willReturn(1);

        try {
            $service->create($actor, 'Pilots', 'TEAM', SignupCodeAdminService::VALIDITY_FOREVER, 5);
            self::fail('An audit failure must fail the creation.');
        } catch (\RuntimeException $e) {
            self::assertSame('audit table is gone', $e->getMessage());
        }

        self::assertTrue($savedInTransaction, 'the code is written in the same transaction as its audit row');
    }
}
