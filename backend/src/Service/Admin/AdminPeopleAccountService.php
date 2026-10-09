<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\InternalEmailService;
use App\Service\MailerConfig;
use App\Service\UserLifecycleService;

/**
 * Accounts an administrator creates or unblocks from Users.
 *
 * Distinct from {@see AdminUserProvisioningService}, which maps an external
 * identity and sets provider "external". A person added here signs in with
 * email and password.
 */
final readonly class AdminPeopleAccountService
{
    private const ASSIGNABLE_LEVELS = ['NEW', 'PRO', 'TEAM', 'BUSINESS'];

    public function __construct(
        private UserRepository $users,
        private UserLifecycleService $lifecycle,
        private VerificationTokenRepository $tokens,
        private InternalEmailService $mail,
        private MailerConfig $mailerConfig,
        private AuditLogWriter $audit,
    ) {
    }

    public function create(User $actor, string $email, string $displayName, string $level, string $password, string $ip): User
    {
        $email = trim($email);
        $level = strtoupper(trim($level));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email is required');
        }
        if (!in_array($level, self::ASSIGNABLE_LEVELS, true)) {
            throw new \InvalidArgumentException('level must be one of: '.implode(', ', self::ASSIGNABLE_LEVELS));
        }
        if (strlen($password) < 8 || strlen($password) > 64 || 1 !== preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $password)) {
            throw new \InvalidArgumentException('Password must be 8-64 characters and contain an uppercase letter, a lowercase letter, and a number');
        }
        if (null !== $this->users->findOneBy(['mail' => $email])) {
            throw new \InvalidArgumentException('An account with this email already exists');
        }

        $details = [];
        $displayName = trim($displayName);
        if ('' !== $displayName) {
            $details['display_name'] = $displayName;
        }

        $user = $this->lifecycle->createUser(
            email: $email,
            plainPassword: $password,
            userLevel: $level,
            emailVerified: true,
            userDetails: $details,
        );

        $this->audit->record(
            (int) $actor->getId(),
            'user.create',
            'user',
            (string) $user->getId(),
            ['email' => $email, 'level' => $level],
            $ip,
        );

        return $user;
    }

    public function markVerified(User $actor, User $target, string $ip): void
    {
        if ($target->isEmailVerified()) {
            return;
        }
        $target->setEmailVerified(true);
        $this->users->save($target);
        $this->audit->record(
            (int) $actor->getId(),
            'user.verify',
            'user',
            (string) $target->getId(),
            ['email' => $target->getMail()],
            $ip,
        );
    }

    /**
     * @return 'sent'|'unconfigured'
     */
    public function resendVerification(User $actor, User $target, string $ip): string
    {
        if (!$this->mailerConfig->isConfigured()) {
            return 'unconfigured';
        }
        $token = $this->tokens->createToken($target, 'email_verification', 86400);
        $sent = $this->mail->sendVerificationEmail($target->getMail(), $token->getToken(), $target->getLocale());
        if (!$sent) {
            return 'unconfigured';
        }
        $this->audit->record(
            (int) $actor->getId(),
            'user.resend_verification',
            'user',
            (string) $target->getId(),
            ['email' => $target->getMail()],
            $ip,
        );

        return 'sent';
    }
}
