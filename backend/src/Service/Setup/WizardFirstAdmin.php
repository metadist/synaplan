<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Lock\LockFactory;

/**
 * The setup wizard's way to the first administrator: setup token, lock,
 * re-check, create.
 */
final readonly class WizardFirstAdmin
{
    public const LOCK_NAME = 'first-run-setup-admin';

    private const LOCK_TTL_SECONDS = 30.0;

    public function __construct(
        private SetupTokenService $setupToken,
        private LockFactory $lockFactory,
        private UserRepository $userRepository,
        private FirstAdminCreator $firstAdminCreator,
    ) {
    }

    /**
     * @throws SetupRefusedException
     */
    public function create(
        string $email,
        #[\SensitiveParameter]
        string $plainPassword,
        #[\SensitiveParameter]
        string $setupToken,
    ): User {
        if (!$this->setupToken->matches($setupToken)) {
            throw new SetupRefusedException(SetupRefusal::TokenInvalid);
        }

        // Two containers starting at once (Compose scale, a Kubernetes rollout),
        // or simply a second browser tab, would otherwise both pass the check
        // and create two "first" administrators. Same lock discipline as
        // BootstrapAdminService, which solves the identical race on the
        // headless path.
        //
        // Non-blocking on purpose: the caller is a person waiting on a form,
        // and the loser of this race has nothing to wait for. The request
        // holding the lock is about to create the one administrator there
        // will be.
        $lock = $this->lockFactory->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS, false);
        if (!$lock->acquire()) {
            throw new SetupRefusedException(SetupRefusal::InProgress);
        }

        try {
            // Re-checked under the lock: the loser of the race must not create
            // a second administrator on the strength of a stale read.
            if (0 !== $this->userRepository->countAll()) {
                throw new SetupRefusedException(SetupRefusal::AlreadyCompleted);
            }

            $user = $this->firstAdminCreator->create($email, $plainPassword);
            $this->setupToken->clear();

            return $user;
        } finally {
            $lock->release();
        }
    }
}
