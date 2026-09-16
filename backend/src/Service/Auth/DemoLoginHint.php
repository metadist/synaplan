<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Whether the login page may show the seeded first-run administrator.
 *
 * Only in dev/test, and only while admin@synaplan.com still uses the
 * fixture password. Production never advertises this, even if someone
 * left the default password in place.
 */
final readonly class DemoLoginHint
{
    public const EMAIL = 'admin@synaplan.com';
    public const PASSWORD = 'admin123';

    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function isVisible(): bool
    {
        if (!\in_array($this->environment, ['dev', 'test'], true)) {
            return false;
        }

        $admin = $this->userRepository->findByEmail(self::EMAIL);
        if (null === $admin) {
            return false;
        }

        return $this->passwordHasher->isPasswordValid($admin, self::PASSWORD);
    }
}
