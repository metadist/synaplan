<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\SignupAdmission;
use App\Service\Auth\SignupBlockedException;
use App\Service\Chat\GuestSessionService;
use App\Service\Email\EmailChatService;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Central factory for BUSER rows.
 *
 * Every code path that creates a user account should go through createUser()
 * so entity defaults stay consistent. New accounts no longer receive frozen
 * per-user DEFAULTMODEL rows — global defaults apply until the user picks.
 * Deletion lives in {@see UserDeletionService};
 * together they form the user lifecycle.
 *
 * Current callers: AuthController::register(), AdminUserProvisioningService.
 * Not yet migrated (kept intentionally out of the first cut): OAuth/OIDC
 * find-or-create paths, anonymous channel users (EmailChatService), and the
 * WordPress wizard.
 */
final readonly class UserLifecycleService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
        private SignupAdmission $signupAdmission,
        private UserRepository $users,
    ) {
    }

    /**
     * Email/password sign-up. Open mode ignores plan and code. Admission mode
     * requires exactly one of them, and that check happens before we look the
     * address up, so a missing choice is the same answer for every address.
     *
     * Returns null when the address is reserved or already registered. The
     * caller answers exactly as it would for a new account.
     *
     * @param array<string, mixed> $userDetails
     *
     * @throws SignupBlockedException
     */
    public function createSignup(string $email, string $plainPassword, array $userDetails, ?string $plan, ?string $code, ?string $clientIp): ?User
    {
        $admission = $this->signupAdmission->resolve($plan, $code);
        if (GuestSessionService::isReservedProcessorEmail($email)) {
            $this->logger->warning('Registration attempt with reserved guest-processor email', ['ip' => $clientIp]);

            return null;
        }

        $existing = $this->users->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            // Same work as a real sign-up, so the two answers take the same time.
            $this->logger->warning('Registration attempt with existing email', ['email' => $email, 'ip' => $clientIp]);
            $this->passwordHasher->hashPassword($existing, $plainPassword);

            return null;
        }

        $level = 'NEW';
        if (null !== $admission && $admission['code'] instanceof \App\Entity\SignupCode) {
            $level = $admission['code']->getLevel();
        }

        $user = $this->createUser($email, $plainPassword, 'local', 'WEB', $level, false, $userDetails);
        if (null === $admission) {
            return $user;
        }
        if (null !== $admission['plan']) {
            $this->signupAdmission->apply($user, $admission);
            $this->em->flush();

            return $user;
        }
        if ($admission['code'] instanceof \App\Entity\SignupCode) {
            $this->signupAdmission->commitCode($user, $admission['code']);
        }

        return $user;
    }

    /**
     * Create and persist a user. Default models stay global until the user picks.
     *
     * @param string               $email         login email (uniqueness must be checked by the caller,
     *                                            since the required conflict behavior differs per flow)
     * @param string|null          $plainPassword hashed and stored when given; null for
     *                                            accounts that authenticate externally
     * @param array<string, mixed> $userDetails   initial BUSERDETAILS JSON
     */
    public function createUser(
        string $email,
        ?string $plainPassword = null,
        string $providerId = 'local',
        string $type = 'WEB',
        string $userLevel = 'NEW',
        bool $emailVerified = false,
        array $userDetails = [],
    ): User {
        if (GuestSessionService::isReservedProcessorEmail($email)) {
            throw new \InvalidArgumentException('This email is reserved for guest chat processing');
        }

        $user = new User();
        $user->setMail($email);
        $user->setCreated(date('Y-m-d H:i:s'));
        $user->setType($type);
        $user->setProviderId($providerId);
        $user->setUserLevel($userLevel);
        $user->setEmailVerified($emailVerified);
        $user->setUserDetails($userDetails);
        $user->setPaymentDetails([]);

        if (null !== $plainPassword && '' !== $plainPassword) {
            $user->setPw($this->passwordHasher->hashPassword($user, $plainPassword));
        }

        $this->em->persist($user);
        $this->em->flush();

        $this->modelConfigService->initializeNewUserDefaults((int) $user->getId());

        $this->logger->info('User created', [
            'user_id' => $user->getId(),
            'provider_id' => $providerId,
            'level' => $userLevel,
        ]);

        return $user;
    }

    /**
     * Record that this person proved the mailbox. A local account created
     * already verified never passes through the false-to-true change that
     * queues its default plugins, so new accounts are created unverified
     * and verified here.
     */
    public function markEmailVerified(User $user): void
    {
        if ($user->isEmailVerified()) {
            return;
        }

        $user->setEmailVerified(true);
        $this->em->flush();
    }
}
