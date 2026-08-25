<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Repository\UserRepository;
use App\Service\Setup\SetupStateService;
use App\Service\UserLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates the first administrator from deployment credentials.
 *
 * Once any administrator exists, this service is deliberately a no-op. This
 * makes it safe to run on every container start without turning the bootstrap
 * secret into a permanent password-reset mechanism.
 *
 * The rules for the configured values are not implemented here: they belong to
 * BootstrapAdminConfiguration, which the container entrypoint also calls long
 * before this service runs. One implementation, no drift.
 *
 * Creating or promoting an administrator also closes the first-run setup window
 * ({@see SetupStateService::markCompleted()}), so a headless deployment that
 * ships BOOTSTRAP_ADMIN_* — CI, Umbrel, Kubernetes, `install.sh --mode server` —
 * never shows the browser wizard.
 */
final readonly class BootstrapAdminService
{
    public const RESULT_NOT_CONFIGURED = 'not_configured';
    public const RESULT_ADMIN_EXISTS = 'admin_exists';
    public const RESULT_PROMOTED = 'promoted';
    public const RESULT_CREATED = 'created';

    private const LOCK_TTL_SECONDS = 60.0;

    public function __construct(
        private UserRepository $userRepository,
        private UserLifecycleService $userLifecycleService,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private LockFactory $lockFactory,
        private SetupStateService $setupState,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $forcePasswordChange for deployments that generate the password themselves
     *                                  (marketplace images), so the generated value is a
     *                                  one-time credential the admin must replace at first login
     */
    public function bootstrap(
        string $configuredEmail,
        #[\SensitiveParameter]
        string $configuredPassword,
        bool $forcePasswordChange = false,
    ): string {
        $configuration = BootstrapAdminConfiguration::fromConfiguration($configuredEmail, $configuredPassword);
        if (null === $configuration) {
            return self::RESULT_NOT_CONFIGURED;
        }

        $email = $configuration->email;
        $password = $configuration->password();

        $lock = $this->lockFactory->createLock('bootstrap-first-admin', self::LOCK_TTL_SECONDS, false);
        if (!$lock->acquire(true)) {
            throw new \RuntimeException('Could not acquire the first-admin bootstrap lock.');
        }

        try {
            if ($this->userRepository->hasAdmin()) {
                return self::RESULT_ADMIN_EXISTS;
            }

            $user = $this->userRepository->findByEmail($email);
            if (null !== $user) {
                $user->setUserLevel('ADMIN');
                $user->setEmailVerified(true);
                $user->setPw($this->passwordHasher->hashPassword($user, $password));
                $user->setMustChangePassword($forcePasswordChange);
                $this->entityManager->flush();
                $this->setupState->markCompleted();

                $this->logger->notice('Promoted existing user during first-admin bootstrap', [
                    'user_id' => $user->getId(),
                ]);

                return self::RESULT_PROMOTED;
            }

            $user = $this->userLifecycleService->createUser(
                email: $email,
                plainPassword: $password,
                userLevel: 'ADMIN',
                emailVerified: true,
            );

            if ($forcePasswordChange) {
                $user->setMustChangePassword(true);
                $this->entityManager->flush();
            }

            $this->setupState->markCompleted();

            $this->logger->notice('Created user during first-admin bootstrap', [
                'user_id' => $user->getId(),
                'must_change_password' => $forcePasswordChange,
            ]);

            return self::RESULT_CREATED;
        } finally {
            $lock->release();
        }
    }
}
