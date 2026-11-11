<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\User;
use App\Repository\OAuthTokenRepository;
use App\Repository\UserRepository;
use App\Service\Auth\RegistrationConfig;
use App\Service\Auth\SignupAdmission;
use App\Service\Auth\SignupBlockedException;
use App\Service\Auth\SignupDoor;
use App\Service\Auth\TokenService;
use App\Service\Chat\GuestSessionService;
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
 * Self-sign-up (email and the social doors) is created here. Directory
 * provisioning, channel identities and fixtures are not.
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
        private TokenService $tokenService,
        private RegistrationConfig $registration,
        private OAuthTokenRepository $oauthTokens,
        private LevelGrantService $levelGrants,
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

        $existing = $this->users->findOtherByNormalizedEmail($email, 0);
        if ($existing instanceof User) {
            // Same work as a real sign-up, so the two answers take the same time.
            $this->logger->warning('Registration attempt with existing email', ['email' => $email, 'ip' => $clientIp]);
            $this->passwordHasher->hashPassword($existing, $plainPassword);

            return null;
        }

        $user = $this->createUser($email, $plainPassword, SignupDoor::Email->value, 'WEB', 'NEW', false, $userDetails);
        if (null === $admission) {
            return $user;
        }
        $this->signupAdmission->apply($user, $admission);
        if ($admission['code'] instanceof \App\Entity\SignupCode) {
            $this->signupAdmission->commitCode($user, $admission['code']);
        }

        return $user;
    }

    /**
     * A new social account, not yet stored. The OAuth callback fills the
     * provider details and {@see \App\Service\Auth\OAuthSignupGate::finishSignIn()}
     * stores the row in the same transaction as the admission, so a refused
     * plan does not leave an account behind.
     *
     * Refused when self-sign-up is off. A pause is decided earlier, because an
     * invitation code may pass the pause and must not be refused here.
     *
     * @throws SignupBlockedException
     */
    public function buildExternalAccount(SignupDoor $door, string $email, bool $emailVerified): User
    {
        if (SignupDoor::Email === $door) {
            throw new \InvalidArgumentException('Email sign-up uses createSignup().');
        }
        if (!$this->registration->isEnabled()) {
            throw SignupBlockedException::closed();
        }

        return $this->newAccount($email, null, $door->value, 'WEB', $emailVerified, []);
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
        $user = $this->newAccount($email, $plainPassword, $providerId, $type, $emailVerified, $userDetails);

        $this->em->persist($user);
        $this->em->flush();
        $this->grantInitialLevel($user, $userLevel);

        $this->modelConfigService->initializeNewUserDefaults((int) $user->getId());

        $this->logger->info('User created', [
            'user_id' => $user->getId(),
            'provider_id' => $providerId,
            'level' => $userLevel,
        ]);

        return $user;
    }

    /**
     * @param array<string, mixed> $userDetails
     */
    private function newAccount(
        string $email,
        ?string $plainPassword,
        string $providerId,
        string $type,
        bool $emailVerified,
        array $userDetails,
    ): User {
        if (GuestSessionService::isReservedProcessorEmail($email)) {
            throw new \InvalidArgumentException('This email is reserved for guest chat processing');
        }

        $user = new User();
        $user->setMail(mb_strtolower(trim($email)));
        $user->setCreated(date('Y-m-d H:i:s'));
        $user->setType($type);
        $user->setProviderId($providerId);
        $user->setEmailVerified($emailVerified);
        $user->setUserDetails($userDetails);
        $user->setPaymentDetails([]);

        if (null !== $plainPassword && '' !== $plainPassword) {
            $user->setPw($this->passwordHasher->hashPassword($user, $plainPassword));
        }

        return $user;
    }

    /**
     * The level asked for at creation is a grant, written once the account
     * has an id. NEW stays the entity default and records nothing. A sign-up
     * code is not an administrator grant; that path passes NEW and the code
     * writes its own grant after the account exists.
     */
    private function grantInitialLevel(User $user, string $userLevel): void
    {
        $userLevel = strtoupper($userLevel);
        if ('ADMIN' === $userLevel) {
            $this->levelGrants->replace($user, LevelGrantSource::BOOTSTRAP, 'ADMIN', null, null);

            return;
        }
        if (AccountLevel::known($userLevel) && 'NEW' !== $userLevel) {
            $this->levelGrants->replace($user, LevelGrantSource::ADMIN, $userLevel, null, null);
        }
    }

    /**
     * The mailbox was just proven. Refresh the cutoff, drop every refresh
     * token, and leave the access token that was already issued to be refused
     * by the key gate until it expires.
     */
    public function markEmailVerified(User $user): void
    {
        $user->recordMailboxProof();
        $this->em->flush();
        $this->tokenService->revokeAllUserTokens($user);
        $this->endCloudGrants($user);
    }

    /**
     * Stores a password the person just proved they know, and ends every cloud
     * grant that was issued under the old one.
     */
    public function replacePassword(User $user, string $plainPassword): void
    {
        $user->setPw($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setMustChangePassword(false);
        $this->users->save($user);
        $this->endCloudGrants($user);
    }

    /**
     * Ends the grants clouds hold for this person. Signing out everywhere and
     * a new password both do this, so a cloud allowed earlier cannot keep acting.
     */
    public function endCloudGrants(User $user): void
    {
        $id = $user->getId();
        if (null !== $id) {
            $this->oauthTokens->revokeAllForUser($id);
        }
    }
}
