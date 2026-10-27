<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\EmailVerificationAttempt;
use App\Entity\User;
use App\Entity\VerificationToken;
use App\Model\VerificationResendStatus;
use App\Repository\EmailVerificationAttemptRepository;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use App\Service\Auth\VerificationResendService;
use App\Service\Email\InternalEmailService;
use App\Service\Email\MailerConfig;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class VerificationResendServiceTest extends TestCase
{
    private EmailVerificationAttemptRepository $attempts;
    private UserRepository $users;
    private VerificationTokenRepository $tokens;
    private EntityManagerInterface $em;
    private InternalEmailService $mail;

    /** @var array<string, string|null> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        $this->attempts = $this->createStub(EmailVerificationAttemptRepository::class);
        $this->users = $this->createStub(UserRepository::class);
        $this->tokens = $this->createStub(VerificationTokenRepository::class);
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->mail = $this->createStub(InternalEmailService::class);
        foreach (['MAILER_DSN', 'EMAIL_VERIFICATION_MAX_ATTEMPTS', 'EMAIL_VERIFICATION_COOLDOWN_MINUTES'] as $name) {
            $this->previousEnv[$name] = $_ENV[$name] ?? null;
        }
        $_ENV['EMAIL_VERIFICATION_MAX_ATTEMPTS'] = '5';
        $_ENV['EMAIL_VERIFICATION_COOLDOWN_MINUTES'] = '2';
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            if (null === $value) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
    }

    public function testUnconfiguredMailDoesNotStoreAnAttemptOrSend(): void
    {
        $_ENV['MAILER_DSN'] = 'null://null';
        $attempts = $this->createMock(EmailVerificationAttemptRepository::class);
        $attempts->expects($this->never())->method('findByEmail');
        $this->attempts = $attempts;
        $mail = $this->createMock(InternalEmailService::class);
        $mail->expects($this->never())->method('sendVerificationEmail');
        $this->mail = $mail;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');
        $this->em = $em;

        $result = $this->service()->resend('ada@example.com', '127.0.0.1');

        $this->assertSame(VerificationResendStatus::Unconfigured, $result->status);
    }

    public function testAFailedSendDoesNotSpendAnAttempt(): void
    {
        $_ENV['MAILER_DSN'] = 'smtp://127.0.0.1:1025';
        $user = $this->unverifiedUser();
        $this->attempts->method('findByEmail')->willReturn(null);
        $this->users->method('findOneBy')->willReturn($user);
        $this->tokens->method('createToken')->willReturn($this->token());
        $this->mail->method('sendVerificationEmail')->willThrowException(new \RuntimeException('smtp down'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');
        $this->em = $em;

        $result = $this->service()->resend('ada@example.com', '127.0.0.1');

        $this->assertSame(VerificationResendStatus::Failed, $result->status);
    }

    public function testAFalseDeliveryDoesNotSpendAnAttempt(): void
    {
        $_ENV['MAILER_DSN'] = 'smtp://127.0.0.1:1025';
        $user = $this->unverifiedUser();
        $this->attempts->method('findByEmail')->willReturn(null);
        $this->users->method('findOneBy')->willReturn($user);
        $this->tokens->method('createToken')->willReturn($this->token());
        $this->mail->method('sendVerificationEmail')->willReturn(false);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');
        $this->em = $em;

        $result = $this->service()->resend('ada@example.com', '127.0.0.1');

        $this->assertSame(VerificationResendStatus::Unconfigured, $result->status);
    }

    public function testADeliveredMessageSpendsOneAttempt(): void
    {
        $_ENV['MAILER_DSN'] = 'smtp://127.0.0.1:1025';
        $user = $this->unverifiedUser();
        $this->attempts->method('findByEmail')->willReturn(null);
        $this->users->method('findOneBy')->willReturn($user);
        $tokens = $this->createMock(VerificationTokenRepository::class);
        $tokens->expects($this->once())->method('createToken')->willReturn($this->token());
        $this->tokens = $tokens;
        $mail = $this->createMock(InternalEmailService::class);
        $mail->expects($this->once())->method('sendVerificationEmail')->willReturn(true);
        $this->mail = $mail;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');
        $this->em = $em;

        $result = $this->service()->resend('ada@example.com', '127.0.0.1');

        $this->assertSame(VerificationResendStatus::Sent, $result->status);
        $this->assertSame(4, $result->remainingAttempts);
        $this->assertSame(2, $result->cooldownMinutes);
    }

    public function testAVerifiedAddressIsCountedWithoutSending(): void
    {
        $_ENV['MAILER_DSN'] = 'smtp://127.0.0.1:1025';
        $user = $this->unverifiedUser();
        $user->setEmailVerified(true);
        $this->attempts->method('findByEmail')->willReturn(null);
        $this->users->method('findOneBy')->willReturn($user);
        $mail = $this->createMock(InternalEmailService::class);
        $mail->expects($this->never())->method('sendVerificationEmail');
        $this->mail = $mail;
        $tokens = $this->createMock(VerificationTokenRepository::class);
        $tokens->expects($this->never())->method('createToken');
        $this->tokens = $tokens;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');
        $this->em = $em;

        $result = $this->service()->resend('ada@example.com', '127.0.0.1');

        $this->assertSame(VerificationResendStatus::Accepted, $result->status);
    }

    public function testUnknownAddressIsCountedWithoutSending(): void
    {
        $_ENV['MAILER_DSN'] = 'smtp://127.0.0.1:1025';
        $this->attempts->method('findByEmail')->willReturn(null);
        $this->users->method('findOneBy')->willReturn(null);
        $mail = $this->createMock(InternalEmailService::class);
        $mail->expects($this->never())->method('sendVerificationEmail');
        $this->mail = $mail;
        $tokens = $this->createMock(VerificationTokenRepository::class);
        $tokens->expects($this->never())->method('createToken');
        $this->tokens = $tokens;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');
        $this->em = $em;

        $result = $this->service()->resend('missing@example.com', '127.0.0.1');

        $this->assertSame(VerificationResendStatus::Accepted, $result->status);
    }

    public function testTheAllowanceStopsFurtherSends(): void
    {
        $_ENV['MAILER_DSN'] = 'smtp://127.0.0.1:1025';
        $attempt = new EmailVerificationAttempt();
        $attempt->setEmail('ada@example.com');
        for ($i = 0; $i < 4; ++$i) {
            $attempt->incrementAttempts();
        }
        $this->attempts->method('findByEmail')->willReturn($attempt);
        $mail = $this->createMock(InternalEmailService::class);
        $mail->expects($this->never())->method('sendVerificationEmail');
        $this->mail = $mail;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');
        $this->em = $em;

        $result = $this->service()->resend('ada@example.com', '127.0.0.1');

        $this->assertSame(VerificationResendStatus::RateLimited, $result->status);
        $this->assertTrue($result->maxAttemptsReached());
        $this->assertSame(5, $attempt->getAttempts());
    }

    private function service(): VerificationResendService
    {
        return new VerificationResendService(
            $this->attempts,
            $this->users,
            $this->tokens,
            $this->em,
            $this->mail,
            new MailerConfig(),
            new NullLogger(),
        );
    }

    private function unverifiedUser(): User
    {
        $user = new User();
        $user->setMail('ada@example.com');

        return $user;
    }

    private function token(): VerificationToken
    {
        $token = $this->createStub(VerificationToken::class);
        $token->method('getToken')->willReturn('token');

        return $token;
    }
}
