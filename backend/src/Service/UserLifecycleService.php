<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Central factory for BUSER rows.
 *
 * Every code path that creates a user account should go through createUser()
 * so entity defaults and post-create side effects (per-user default model
 * config) stay consistent. Deletion lives in {@see UserDeletionService};
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
    ) {
    }

    /**
     * Create and persist a user, then seed per-user default model config.
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
}
