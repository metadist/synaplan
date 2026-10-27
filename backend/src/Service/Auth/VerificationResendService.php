<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\EmailVerificationAttempt;
use App\Entity\VerificationToken;
use App\Model\VerificationResendResult;
use App\Model\VerificationResendStatus;
use App\Repository\EmailVerificationAttemptRepository;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use App\Service\Email\InternalEmailService;
use App\Service\Email\MailerConfig;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends another verification email, or says clearly that none went out.
 *
 * The resend allowance is spent only after a real delivery (or for an
 * address we deliberately answer with the generic "if registered" text).
 * An unconfigured transport and a failed send leave the allowance untouched,
 * so configuring mail later still lets the person request the message.
 */
final readonly class VerificationResendService
{
    public function __construct(
        private EmailVerificationAttemptRepository $attempts,
        private UserRepository $users,
        private VerificationTokenRepository $tokens,
        private EntityManagerInterface $em,
        private InternalEmailService $mail,
        private MailerConfig $mailerConfig,
        private LoggerInterface $logger,
    ) {
    }

    public function resend(string $email, ?string $ip): VerificationResendResult
    {
        if (!$this->mailerConfig->isConfigured()) {
            $this->logger->warning('Verification resend skipped: mail transport is not configured');

            return new VerificationResendResult(VerificationResendStatus::Unconfigured);
        }

        $cooldownMinutes = $this->cooldownMinutes();
        $maxAttempts = $this->maxAttempts();
        $attempt = $this->attempts->findByEmail($email);
        if (null !== $attempt && !$attempt->canResend($cooldownMinutes, $maxAttempts)) {
            return $this->rateLimited($attempt, $email, $ip, $cooldownMinutes, $maxAttempts);
        }

        $user = $this->users->findOneBy(['mail' => $email]);
        if (null === $user || $user->isEmailVerified()) {
            $attempt = $this->recordAttempt($attempt, $email, $ip);

            return new VerificationResendResult(
                VerificationResendStatus::Accepted,
                remainingAttempts: $attempt->getRemainingAttempts($maxAttempts),
                cooldownMinutes: $cooldownMinutes,
            );
        }

        $token = null;
        try {
            $token = $this->tokens->createToken($user, 'email_verification', 86400);
            $sent = $this->mail->sendVerificationEmail($user->getMail(), $token->getToken(), $user->getLocale());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send verification email', [
                'user_id' => $user->getId(),
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            $this->discardToken($token);

            return new VerificationResendResult(VerificationResendStatus::Failed);
        }

        if (!$sent) {
            $this->discardToken($token);
            $this->logger->warning('Verification email NOT delivered: mail transport is not configured', [
                'user_id' => $user->getId(),
                'email' => $email,
            ]);

            return new VerificationResendResult(VerificationResendStatus::Unconfigured);
        }

        $attempt = $this->recordAttempt($attempt, $email, $ip);
        $this->logger->info('Verification email sent successfully', [
            'user_id' => $user->getId(),
            'attempt' => $attempt->getAttempts(),
        ]);

        return new VerificationResendResult(
            VerificationResendStatus::Sent,
            remainingAttempts: $attempt->getRemainingAttempts($maxAttempts),
            cooldownMinutes: $cooldownMinutes,
        );
    }

    private function rateLimited(
        EmailVerificationAttempt $attempt,
        string $email,
        ?string $ip,
        int $cooldownMinutes,
        int $maxAttempts,
    ): VerificationResendResult {
        $remaining = $attempt->getRemainingAttempts($maxAttempts);
        if ($remaining <= 0) {
            $this->logger->warning('Max resend attempts reached', [
                'email' => $email,
                'ip' => $ip,
            ]);

            return new VerificationResendResult(
                VerificationResendStatus::RateLimited,
                remainingAttempts: 0,
            );
        }

        $nextAvailable = $attempt->getNextAvailableAt($cooldownMinutes);
        $waitSeconds = null !== $nextAvailable
            ? $nextAvailable->getTimestamp() - (new \DateTime())->getTimestamp()
            : 0;

        return new VerificationResendResult(
            VerificationResendStatus::RateLimited,
            remainingAttempts: $remaining,
            waitSeconds: max(0, $waitSeconds),
            nextAvailableAt: $nextAvailable?->format('c'),
        );
    }

    private function recordAttempt(?EmailVerificationAttempt $attempt, string $email, ?string $ip): EmailVerificationAttempt
    {
        if (null === $attempt) {
            $attempt = new EmailVerificationAttempt();
            $attempt->setEmail($email);
            $attempt->setIpAddress($ip);
            $this->em->persist($attempt);
        } else {
            $attempt->incrementAttempts();
        }

        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save verification resend attempt', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        return $attempt;
    }

    private function discardToken(?VerificationToken $token): void
    {
        if (null === $token) {
            return;
        }

        try {
            $this->em->remove($token);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to discard unsent verification token', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function cooldownMinutes(): int
    {
        return (int) ($_ENV['EMAIL_VERIFICATION_COOLDOWN_MINUTES'] ?? 2);
    }

    private function maxAttempts(): int
    {
        return (int) ($_ENV['EMAIL_VERIFICATION_MAX_ATTEMPTS'] ?? 5);
    }
}
