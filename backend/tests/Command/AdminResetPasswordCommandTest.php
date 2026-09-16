<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AdminResetPasswordCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The last way back into a locked-out installation, so its refusals matter as
 * much as its happy path: a silent no-op here leaves an operator convinced the
 * password was changed when it was not.
 */
#[AllowMockObjectsWithoutExpectations]
final class AdminResetPasswordCommandTest extends TestCase
{
    private UserRepository&MockObject $userRepository;
    private UserPasswordHasherInterface&MockObject $passwordHasher;
    private EntityManagerInterface&MockObject $entityManager;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->passwordHasher->method('hashPassword')->willReturn('new-hash');
    }

    public function testSetsAnExplicitPasswordWithoutForcingAChange(): void
    {
        $user = $this->givenLocalUser();
        $this->entityManager->expects($this->once())->method('flush');

        $tester = $this->tester();
        $tester->execute(['email' => 'admin@example.com', '--password' => 'SecurePass123']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('new-hash', $user->getPw());
        $this->assertFalse(
            $user->mustChangePassword(),
            'a password the operator chose is theirs to keep'
        );
    }

    /**
     * A generated password travels through a terminal buffer and a shell history,
     * so it must not survive the first sign-in.
     */
    public function testAGeneratedPasswordIsPrintedOnceAndMustBeReplaced(): void
    {
        $user = $this->givenLocalUser();

        $tester = $this->tester();
        $tester->execute(['email' => 'admin@example.com', '--generate' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertTrue($user->mustChangePassword());
        $this->assertMatchesRegularExpression('/[0-9a-f]{24}/', $tester->getDisplay());
    }

    public function testPromoteMakesTheAccountAnAdministratorAndVerifiesIt(): void
    {
        $user = $this->givenLocalUser();
        $user->setUserLevel('NEW')->setEmailVerified(false);

        $tester = $this->tester();
        $tester->execute([
            'email' => 'admin@example.com',
            '--generate' => true,
            '--promote' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertTrue($user->isAdmin());
        $this->assertTrue(
            $user->isEmailVerified(),
            'an unverified recovered administrator would be stuck behind the verification gate'
        );
    }

    public function testWithoutPromoteTheAccountLevelIsLeftAlone(): void
    {
        $user = $this->givenLocalUser();
        $user->setUserLevel('PRO');

        $tester = $this->tester();
        $tester->execute(['email' => 'admin@example.com', '--password' => 'SecurePass123']);

        $this->assertSame('PRO', $user->getUserLevel());
    }

    public function testFailsWithoutAPasswordSource(): void
    {
        $this->userRepository->expects($this->never())->method('findByEmail');
        $this->entityManager->expects($this->never())->method('flush');

        $tester = $this->tester();
        $tester->execute(['email' => 'admin@example.com']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('--password', $tester->getDisplay());
    }

    public function testFailsWhenBothPasswordSourcesAreGiven(): void
    {
        $this->entityManager->expects($this->never())->method('flush');

        $tester = $this->tester();
        $tester->execute([
            'email' => 'admin@example.com',
            '--password' => 'SecurePass123',
            '--generate' => true,
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testFailsForAnUnknownAccount(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->entityManager->expects($this->never())->method('flush');

        $tester = $this->tester();
        $tester->execute(['email' => 'nobody@example.com', '--generate' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No account found', $tester->getDisplay());
    }

    /**
     * An externally managed account has no local password, so writing one would
     * change nothing while telling the operator it worked.
     */
    public function testRefusesAnExternallyManagedAccount(): void
    {
        $user = (new User())->setMail('sso@example.com')->setProviderId('keycloak');
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->entityManager->expects($this->never())->method('flush');

        $tester = $this->tester();
        $tester->execute(['email' => 'sso@example.com', '--generate' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('managed by', $tester->getDisplay());
    }

    public function testRefusesATooShortPassword(): void
    {
        $this->givenLocalUser();
        $this->entityManager->expects($this->never())->method('flush');

        $tester = $this->tester();
        $tester->execute(['email' => 'admin@example.com', '--password' => 'short']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('at least 8 characters', $tester->getDisplay());
    }

    public function testRefusesAnOverlongPassword(): void
    {
        $this->givenLocalUser();
        $this->entityManager->expects($this->never())->method('flush');

        $tester = $this->tester();
        $tester->execute(['email' => 'admin@example.com', '--password' => str_repeat('a', 65)]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('at most 64 characters', $tester->getDisplay());
    }

    /**
     * The documented policy for an operator-set password is the one
     * BOOTSTRAP_ADMIN_PASSWORD follows. A recovery path that quietly accepted
     * less would be the weakest way onto the account that can reach everything.
     */
    public function testRefusesAShortPasswordWithoutTheRequiredCharacterClasses(): void
    {
        $this->givenLocalUser();
        $this->entityManager->expects($this->never())->method('flush');

        $tester = $this->tester();
        $tester->execute(['email' => 'admin@example.com', '--password' => 'alllowercase']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('uppercase', $tester->getDisplay());
        $this->assertStringNotContainsString(
            'alllowercase',
            $tester->getDisplay(),
            'a terminal keeps scrollback, so the refusal must not repeat the password'
        );
    }

    /**
     * NIST SP 800-63B: length substitutes for composition. The same waiver lets a
     * managed platform's generated secret through on the bootstrap path.
     */
    public function testAcceptsALongPasswordWithoutCharacterClasses(): void
    {
        $user = $this->givenLocalUser();
        $this->entityManager->expects($this->once())->method('flush');

        $tester = $this->tester();
        $tester->execute(['email' => 'admin@example.com', '--password' => str_repeat('a', 16)]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('new-hash', $user->getPw());
    }

    /**
     * Operators reach for this command because the reset mail never arrived. Say
     * why, so the next person fixes the mailer instead of running this again.
     */
    public function testItPointsAtTheSelfServiceAlternative(): void
    {
        $this->givenLocalUser();

        $tester = $this->tester();
        $tester->execute(['email' => 'admin@example.com', '--password' => 'SecurePass123']);

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertStringContainsString('/forgot-password', $display);
        $this->assertStringContainsString('MAILER_DSN', $display);
    }

    private function givenLocalUser(): User
    {
        $user = (new User())
            ->setMail('admin@example.com')
            ->setProviderId('local')
            ->setUserLevel('ADMIN')
            ->setPw('old-hash');

        $this->userRepository->method('findByEmail')->willReturn($user);

        return $user;
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new AdminResetPasswordCommand(
            $this->userRepository,
            $this->passwordHasher,
            $this->entityManager,
        ));

        return new CommandTester($application->find('app:admin:reset-password'));
    }
}
