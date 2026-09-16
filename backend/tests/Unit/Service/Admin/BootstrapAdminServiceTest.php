<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\BootstrapAdminService;
use App\Service\Setup\SetupStateService;
use App\Service\UserLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The rules themselves belong to BootstrapAdminConfiguration and are covered by
 * BootstrapAdminConfigurationTest. The validation cases here stay on purpose:
 * they prove that the service still enforces those rules through that class, so
 * a second copy cannot creep back in unnoticed.
 */
#[AllowMockObjectsWithoutExpectations]
final class BootstrapAdminServiceTest extends TestCase
{
    private UserRepository&MockObject $userRepository;
    private UserLifecycleService&MockObject $userLifecycleService;
    private EntityManagerInterface&MockObject $entityManager;
    private UserPasswordHasherInterface&MockObject $passwordHasher;
    private LockFactory&MockObject $lockFactory;
    private SharedLockInterface&MockObject $lock;
    private SetupStateService&MockObject $setupState;
    private LoggerInterface&MockObject $logger;
    private BootstrapAdminService $service;
    private bool $lockAcquired = true;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->userLifecycleService = $this->createMock(UserLifecycleService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->lockFactory = $this->createMock(LockFactory::class);
        $this->lock = $this->createMock(SharedLockInterface::class);
        $this->setupState = $this->createMock(SetupStateService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->lockFactory->method('createLock')->willReturn($this->lock);
        $this->lock->method('acquire')->willReturnCallback(fn (): bool => $this->lockAcquired);

        $this->service = new BootstrapAdminService(
            $this->userRepository,
            $this->userLifecycleService,
            $this->entityManager,
            $this->passwordHasher,
            $this->lockFactory,
            $this->setupState,
            $this->logger,
        );
    }

    public function testDoesNothingWhenBootstrapIsNotConfigured(): void
    {
        $this->lockFactory->expects($this->never())->method('createLock');
        $this->userRepository->expects($this->never())->method('hasAdmin');

        $result = $this->service->bootstrap('', '');

        $this->assertSame(BootstrapAdminService::RESULT_NOT_CONFIGURED, $result);
    }

    public function testRejectsEmailWithoutPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must either both be set or both be empty');

        $this->service->bootstrap('admin@example.com', '');
    }

    public function testRejectsPasswordWithoutEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must either both be set or both be empty');

        $this->service->bootstrap('', 'SecurePass123!');
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a valid email address');

        $this->service->bootstrap('not-an-email', 'SecurePass123!');
    }

    public function testRejectsShortPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be at least 8 characters');

        $this->service->bootstrap('admin@example.com', 'short');
    }

    public function testRejectsPasswordOneCharacterBelowTheMinimumLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be at least 8 characters');

        $this->service->bootstrap('admin@example.com', 'Abcdef1');
    }

    public function testRejectsOverlongPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be at most 64 characters');

        $this->service->bootstrap('admin@example.com', 'Secure1'.str_repeat('x', 58));
    }

    public function testRejectsWeakPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('uppercase letter, one lowercase letter, and one number, or be at least 16 characters long');

        $this->service->bootstrap('admin@example.com', 'lowercase-only');
    }

    public function testRejectsIncompleteCompositionJustBelowTheWaiverLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('or be at least 16 characters long');

        $this->service->bootstrap('admin@example.com', 'Ab'.str_repeat('c', 13));
    }

    public function testAcceptsShortPasswordWithCompleteComposition(): void
    {
        $this->assertPasswordIsAccepted('Abcdefg1');
    }

    public function testAcceptsPasswordWithoutDigitAtTheWaiverLength(): void
    {
        $this->assertPasswordIsAccepted('Ab'.str_repeat('c', 14));
    }

    public function testAcceptsAllLowercasePasswordAtTheWaiverLength(): void
    {
        $this->assertPasswordIsAccepted(str_repeat('a', 16));
    }

    public function testAcceptsGeneratedManagedPlatformPasswordWithoutDigit(): void
    {
        $this->assertPasswordIsAccepted('QWZFaxYB-gtYh-AXqFbcde');
    }

    public function testExistingAdminIsNeverChanged(): void
    {
        $this->userRepository->expects($this->once())->method('hasAdmin')->willReturn(true);
        $this->userRepository->expects($this->never())->method('findByEmail');
        $this->passwordHasher->expects($this->never())->method('hashPassword');
        $this->userLifecycleService->expects($this->never())->method('createUser');
        $this->entityManager->expects($this->never())->method('flush');
        $this->lock->expects($this->once())->method('release');

        $result = $this->service->bootstrap('admin@example.com', 'ChangedPass123!');

        $this->assertSame(BootstrapAdminService::RESULT_ADMIN_EXISTS, $result);
    }

    public function testPromotesAndVerifiesExactMatchingUser(): void
    {
        $user = (new User())
            ->setMail('admin@example.com')
            ->setUserLevel('NEW')
            ->setEmailVerified(false)
            ->setPw('old-hash');

        $this->userRepository->expects($this->once())->method('hasAdmin')->willReturn(false);
        $this->userRepository->expects($this->once())
            ->method('findByEmail')
            ->with('admin@example.com')
            ->willReturn($user);
        $this->passwordHasher->expects($this->once())
            ->method('hashPassword')
            ->with($user, 'SecurePass123!')
            ->willReturn('new-hash');
        $this->entityManager->expects($this->once())->method('flush');
        $this->userLifecycleService->expects($this->never())->method('createUser');
        $this->logger->expects($this->once())
            ->method('notice')
            ->with(
                'Promoted existing user during first-admin bootstrap',
                $this->logicalNot($this->arrayHasKey('password')),
            );
        $this->lock->expects($this->once())->method('release');

        $result = $this->service->bootstrap(' admin@example.com ', 'SecurePass123!');

        $this->assertSame(BootstrapAdminService::RESULT_PROMOTED, $result);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->isEmailVerified());
        $this->assertSame('new-hash', $user->getPw());
    }

    public function testCreatesVerifiedAdminThroughLifecycleService(): void
    {
        $createdUser = (new User())
            ->setMail('admin@example.com')
            ->setUserLevel('ADMIN')
            ->setEmailVerified(true);

        $this->userRepository->expects($this->once())->method('hasAdmin')->willReturn(false);
        $this->userRepository->expects($this->once())
            ->method('findByEmail')
            ->with('admin@example.com')
            ->willReturn(null);
        $this->userLifecycleService->expects($this->once())
            ->method('createUser')
            ->with(
                'admin@example.com',
                'SecurePass123!',
                'local',
                'WEB',
                'ADMIN',
                true,
            )
            ->willReturn($createdUser);
        $this->passwordHasher->expects($this->never())->method('hashPassword');
        $this->entityManager->expects($this->never())->method('flush');
        $this->logger->expects($this->once())
            ->method('notice')
            ->with(
                'Created user during first-admin bootstrap',
                $this->logicalNot($this->arrayHasKey('password')),
            );
        $this->lock->expects($this->once())->method('release');

        $result = $this->service->bootstrap('admin@example.com', 'SecurePass123!');

        $this->assertSame(BootstrapAdminService::RESULT_CREATED, $result);
    }

    /**
     * The headless path must leave no first-run setup window open behind it —
     * otherwise a deployment that ships BOOTSTRAP_ADMIN_* would still send its
     * operator through the browser wizard on first visit.
     */
    public function testClosesTheSetupWindowAfterCreatingTheAdmin(): void
    {
        $this->userRepository->method('hasAdmin')->willReturn(false);
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->userLifecycleService->method('createUser')->willReturn(new User());
        $this->setupState->expects($this->once())->method('markCompleted');

        $this->service->bootstrap('admin@example.com', 'SecurePass123!');
    }

    public function testClosesTheSetupWindowAfterPromotingAnExistingUser(): void
    {
        $this->userRepository->method('hasAdmin')->willReturn(false);
        $this->userRepository->method('findByEmail')->willReturn((new User())->setPw('old-hash'));
        $this->passwordHasher->method('hashPassword')->willReturn('new-hash');
        $this->setupState->expects($this->once())->method('markCompleted');

        $this->service->bootstrap('admin@example.com', 'SecurePass123!');
    }

    public function testLeavesTheSetupWindowUntouchedWhenAnAdminAlreadyExists(): void
    {
        $this->userRepository->method('hasAdmin')->willReturn(true);
        $this->setupState->expects($this->never())->method('markCompleted');

        $this->service->bootstrap('admin@example.com', 'SecurePass123!');
    }

    public function testCreatedAdminKeepsItsPasswordWhenNoChangeIsForced(): void
    {
        $createdUser = new User();

        $this->userRepository->method('hasAdmin')->willReturn(false);
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->userLifecycleService->method('createUser')->willReturn($createdUser);

        $this->service->bootstrap('admin@example.com', 'SecurePass123!');

        $this->assertFalse($createdUser->mustChangePassword());
    }

    public function testCreatedAdminMustReplaceADeploymentGeneratedPassword(): void
    {
        $createdUser = new User();

        $this->userRepository->method('hasAdmin')->willReturn(false);
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->userLifecycleService->method('createUser')->willReturn($createdUser);
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->bootstrap('admin@example.com', 'SecurePass123!', true);

        $this->assertSame(BootstrapAdminService::RESULT_CREATED, $result);
        $this->assertTrue($createdUser->mustChangePassword());
    }

    public function testPromotedAdminMustReplaceADeploymentGeneratedPassword(): void
    {
        $user = (new User())->setMail('admin@example.com')->setUserLevel('NEW');

        $this->userRepository->method('hasAdmin')->willReturn(false);
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->passwordHasher->method('hashPassword')->willReturn('new-hash');

        $result = $this->service->bootstrap('admin@example.com', 'SecurePass123!', true);

        $this->assertSame(BootstrapAdminService::RESULT_PROMOTED, $result);
        $this->assertTrue($user->mustChangePassword());
    }

    public function testReleasesLockWhenCreationFails(): void
    {
        $this->userRepository->method('hasAdmin')->willReturn(false);
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->userLifecycleService->method('createUser')->willThrowException(new \RuntimeException('database unavailable'));
        $this->lock->expects($this->once())->method('release');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');

        $this->service->bootstrap('admin@example.com', 'SecurePass123!');
    }

    public function testFailsWhenBootstrapLockCannotBeAcquired(): void
    {
        $this->lockAcquired = false;
        $this->userRepository->expects($this->never())->method('hasAdmin');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not acquire');

        $this->service->bootstrap('admin@example.com', 'SecurePass123!');
    }

    /**
     * Asserts that validation lets the password through into the bootstrap flow.
     */
    private function assertPasswordIsAccepted(string $password): void
    {
        $this->userRepository->expects($this->once())->method('hasAdmin')->willReturn(false);
        $this->userRepository->expects($this->once())->method('findByEmail')->willReturn(null);
        $this->userLifecycleService->expects($this->once())
            ->method('createUser')
            ->with('admin@example.com', $password, 'local', 'WEB', 'ADMIN', true)
            ->willReturn(new User());

        $result = $this->service->bootstrap('admin@example.com', $password);

        $this->assertSame(BootstrapAdminService::RESULT_CREATED, $result);
    }
}
