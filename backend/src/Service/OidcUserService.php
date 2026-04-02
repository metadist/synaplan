<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared OIDC user provisioning service.
 *
 * Finds or creates Synaplan users from OIDC claims. Used by both
 * the Keycloak browser login flow and the OIDC bearer token authenticator.
 */
class OidcUserService
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Find or create a user from OIDC claims.
     *
     * @param array<string, mixed> $claims       OIDC token/userinfo claims (sub, email, preferred_username, given_name, family_name, name)
     * @param string|null          $refreshToken Keycloak refresh token (only set during browser login)
     */
    public function findOrCreateFromClaims(array $claims, ?string $refreshToken = null): User
    {
        $sub = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        $username = $claims['preferred_username'] ?? null;

        if (!$sub) {
            throw new \RuntimeException('OIDC claims missing subject (sub)');
        }

        $user = $this->findBySub($sub) ?? $this->findByEmail($email);

        if ($user) {
            if ('keycloak' !== $user->getProviderId()) {
                throw new \RuntimeException(sprintf(
                    'This email is already registered using %s. Please use the same login method.',
                    $user->getAuthProviderName()
                ));
            }

            $this->logger->info('Existing OIDC user found', ['user_id' => $user->getId()]);
        } else {
            $user = new User();
            $user->setMail($email ?? $username.'@keycloak.local');
            $user->setType('WEB');
            $user->setProviderId('keycloak');
            $user->setUserLevel('NEW');
            $user->setEmailVerified(true);
            $user->setCreated(date('Y-m-d H:i:s'));
            $user->setUserDetails([]);
            $user->setPaymentDetails([]);

            $this->logger->info('Creating new user from OIDC claims', [
                'email' => $email,
                'sub' => $sub,
            ]);
        }

        $this->updateUserDetails($user, $claims, $refreshToken);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Sync OIDC roles and update admin level.
     *
     * @param array<string>        $oidcRoles      Roles extracted from the OIDC token
     * @param array<string>        $adminRoleNames  Role names that grant admin level (lowercase)
     */
    public function syncRoles(User $user, array $oidcRoles, array $adminRoleNames): void
    {
        if (empty($oidcRoles)) {
            return;
        }

        $userDetails = $user->getUserDetails() ?? [];
        $userDetails['oidc_roles'] = $oidcRoles;

        $hasAdmin = !empty(array_intersect(array_map('strtolower', $oidcRoles), $adminRoleNames));
        if ($hasAdmin && 'ADMIN' !== $user->getUserLevel()) {
            $user->setUserLevel('ADMIN');
            $this->logger->info('User promoted to ADMIN via OIDC role', [
                'user_id' => $user->getId(),
                'oidc_roles' => $oidcRoles,
            ]);
        } elseif (!$hasAdmin && 'ADMIN' === $user->getUserLevel()) {
            $user->setUserLevel('NEW');
            $this->logger->info('User demoted from ADMIN — OIDC roles no longer include admin', [
                'user_id' => $user->getId(),
                'oidc_roles' => $oidcRoles,
            ]);
        }

        $user->setUserDetails($userDetails);
        $this->em->persist($user);
        $this->em->flush();
    }

    private function findBySub(string $sub): ?User
    {
        $users = $this->userRepository->findAll();
        foreach ($users as $user) {
            $details = $user->getUserDetails() ?? [];
            if (isset($details['oidc_sub']) && $details['oidc_sub'] === $sub) {
                return $user;
            }
        }

        return null;
    }

    private function findByEmail(?string $email): ?User
    {
        if (!$email) {
            return null;
        }

        return $this->userRepository->findOneBy(['mail' => $email]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function updateUserDetails(User $user, array $claims, ?string $refreshToken): void
    {
        $userDetails = $user->getUserDetails() ?? [];
        $userDetails['oidc_sub'] = $claims['sub'];
        $userDetails['oidc_email'] = $claims['email'] ?? null;
        $userDetails['oidc_username'] = $claims['preferred_username'] ?? null;
        $userDetails['oidc_last_login'] = (new \DateTime())->format('Y-m-d H:i:s');

        if (null !== $refreshToken) {
            $userDetails['oidc_refresh_token'] = $refreshToken;
        }

        if (isset($claims['given_name'])) {
            $userDetails['first_name'] = $claims['given_name'];
        }
        if (isset($claims['family_name'])) {
            $userDetails['last_name'] = $claims['family_name'];
        }
        if (isset($claims['name'])) {
            $userDetails['full_name'] = $claims['name'];
        }

        $user->setUserDetails($userDetails);

        $email = $claims['email'] ?? null;
        if (!$user->isEmailVerified() && $email && ($claims['email_verified'] ?? true)) {
            $user->setEmailVerified(true);
        }
    }
}
