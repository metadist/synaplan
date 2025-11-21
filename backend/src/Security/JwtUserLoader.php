<?php

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Custom JWT User Loader that loads users by ID instead of email
 * This is necessary for OAuth users who don't have passwords
 */
class JwtUserLoader implements UserProviderInterface
{
    public function __construct(
        private UserRepository $userRepository
    ) {}

    /**
     * Load user by identifier (which is the User ID from JWT token)
     * 
     * The JWT token contains: {"id": 123, ...}
     * Lexik JWT calls this method with the ID as string
     */
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // The identifier could be either ID or email depending on context
        // Try to load by ID first (for JWT tokens)
        if (is_numeric($identifier)) {
            $user = $this->userRepository->find((int)$identifier);
            if ($user) {
                return $user;
            }
        }
        
        // Fallback: try to load by email (for backwards compatibility)
        $user = $this->userRepository->findOneBy(['mail' => $identifier]);
        
        if (!$user) {
            throw new UserNotFoundException(sprintf('User with identifier "%s" not found', $identifier));
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        // Refresh by ID, not by email
        $userId = $user->getId();
        
        if (!$userId) {
            throw new UserNotFoundException('User ID is null');
        }
        
        return $this->loadUserByIdentifier((string)$userId);
    }

    public function supportsClass(string $class): bool
    {
        return $class === 'App\Entity\User' || is_subclass_of($class, 'App\Entity\User');
    }
}

