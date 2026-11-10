<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use App\Service\Email\InternalEmailService;
use App\Service\Email\MailerConfig;
use App\Service\Iam\AuditLogWriter;
use App\Service\Iam\LevelGrantService;
use App\Service\Iam\LevelGrantSource;
use App\Service\Iam\UserLifecycleService;
use Doctrine\ORM\EntityManagerInterface;

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
        private LevelGrantService $levelGrants,
        private EntityManagerInterface $em,
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

    /**
     * What an administrator picked on People → Users. The stored level is the
     * highest grant afterwards, which can be higher than the pick.
     *
     * @return array{outcome: string, notice: ?array, grants: list<array<string, mixed>>}
     */
    public function changeLevel(User $actor, User $target, string $requested, string $ip): array
    {
        $requested = strtoupper(trim($requested));
        if (!in_array($requested, ['NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN'], true)) {
            throw new \InvalidArgumentException('Invalid level');
        }

        $old = $target->getUserLevel();
        $notice = null;
        $outcome = $this->em->wrapInTransaction(function () use ($actor, $target, $requested, $ip, $old, &$notice): string {
            $projected = $this->levelGrants->project($target, $requested);
            if ($target->isAdmin() && 'ADMIN' !== $projected && 1 >= $this->users->countAdminsForUpdate()) {
                return 'conflict';
            }
            if ($actor->getId() === $target->getId()) {
                return 'forbidden';
            }

            $notice = $this->levelGrants->assignFromAdministrator($target, $requested, (int) $actor->getId());
            $this->audit->record(
                (int) $actor->getId(),
                'admin.user_level_change',
                'user',
                (string) $target->getId(),
                [
                    'target_email' => $target->getMail(),
                    'old_level' => $old,
                    'new_level' => $requested,
                ],
                $ip,
            );

            return 'ok';
        });

        return [
            'outcome' => $outcome,
            'notice' => $notice,
            'grants' => 'ok' === $outcome ? $this->levelGrants->views($target) : [],
        ];
    }

    /**
     * Removes the administrator grant and nothing else.
     *
     * @return array{outcome: string, notice: ?array, grants: list<array<string, mixed>>}
     */
    public function removeAdminGrant(User $actor, User $target, string $ip): array
    {
        if (null === $this->levelGrants->storedAdminLevel($target)) {
            return ['outcome' => 'missing', 'notice' => null, 'grants' => []];
        }

        $notice = null;
        $outcome = $this->em->wrapInTransaction(function () use ($actor, $target, $ip, &$notice): string {
            $removed = $this->levelGrants->storedAdminLevel($target);
            if (null === $removed) {
                return 'missing';
            }
            $projected = $this->levelGrants->projectWithout($target, LevelGrantSource::ADMIN);
            if ($target->isAdmin() && 'ADMIN' !== $projected && 1 >= $this->users->countAdminsForUpdate()) {
                return 'conflict';
            }
            if ($actor->getId() === $target->getId()) {
                return 'forbidden';
            }

            $notice = $this->levelGrants->drop($target, LevelGrantSource::ADMIN);
            $this->audit->record(
                (int) $actor->getId(),
                'admin.level_grant_removed',
                'user',
                (string) $target->getId(),
                [
                    'target_email' => $target->getMail(),
                    'source' => LevelGrantSource::ADMIN,
                    'old_level' => $removed,
                    'new_level' => $target->getUserLevel(),
                ],
                $ip,
            );

            return 'ok';
        });

        return [
            'outcome' => $outcome,
            'notice' => $notice,
            'grants' => 'ok' === $outcome ? $this->levelGrants->views($target) : [],
        ];
    }

    /**
     * @param list<User>                             $users
     * @param array<int, list<array<string, mixed>>> $groupsByUser
     * @param array<int, list<string>>               $identitiesByUser
     *
     * @return list<array<string, mixed>>
     */
    public function listRows(array $users, bool $iamEnabled, array $groupsByUser, array $identitiesByUser): array
    {
        $ids = [];
        foreach ($users as $user) {
            if (null !== $user->getId()) {
                $ids[] = (int) $user->getId();
            }
        }
        $grants = $this->levelGrants->viewsFor($ids);
        $rows = [];
        foreach ($users as $user) {
            $rows[] = $this->row(
                $user,
                $iamEnabled,
                $groupsByUser,
                $identitiesByUser,
                $grants[(int) $user->getId()] ?? [],
            );
        }

        return $rows;
    }

    /**
     * @param array<int, list<array<string, mixed>>>                                          $groupsByUser
     * @param array<int, list<string>>                                                        $identitiesByUser
     * @param list<array{source: string, level: string, created: string, reference: ?string}> $grants
     *
     * @return array<string, mixed>
     */
    private function row(User $user, bool $iamEnabled, array $groupsByUser, array $identitiesByUser, array $grants): array
    {
        $email = $user->getMail();
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $details = $user->getUserDetails();
            $phone = $details['phone_number'] ?? $details['anonymous_phone'] ?? null;
            $email = is_string($phone) && '' !== $phone ? $phone : null;
        }

        $level = $user->getUserLevel();
        if (!in_array($level, ['ANONYMOUS', 'NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN'], true)) {
            $level = 'NEW';
        }

        $row = [
            'id' => $user->getId(),
            'email' => $email,
            'level' => $level,
            'type' => $user->getType(),
            'providerId' => $user->getProviderId(),
            'emailVerified' => $user->isEmailVerified(),
            'created' => $user->getCreated(),
            'isAdmin' => $user->isAdmin(),
            'locale' => $user->getLocale(),
            'grants' => $grants,
        ];
        if ($iamEnabled) {
            $row['groups'] = $groupsByUser[(int) $user->getId()] ?? [];
            $row['identities'] = array_values(array_unique($identitiesByUser[(int) $user->getId()] ?? []));
        }

        return $row;
    }
}
