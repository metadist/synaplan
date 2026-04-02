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
    /** @var array<string> */
    private array $adminRoleNames;

    /** @var array<array<string>> */
    private array $roleClaimPaths;

    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        string $oidcAdminRoles,
        string $oidcRoleClaims,
        string $oidcClientId,
    ) {
        $this->adminRoleNames = array_map('strtolower', array_map('trim', explode(',', $oidcAdminRoles)));
        $this->roleClaimPaths = $this->parseRoleClaims($oidcRoleClaims, $oidcClientId);
    }

    /**
     * Find or create a user from OIDC claims, sync roles, persist.
     *
     * @param array<string, mixed> $claims       OIDC token/userinfo claims
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
                throw new \RuntimeException(sprintf('This email is already registered using %s. Please use the same login method.', $user->getAuthProviderName()));
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
        $this->syncRoles($user, $claims);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function syncRoles(User $user, array $claims): void
    {
        $oidcRoles = [];
        foreach ($this->roleClaimPaths as $segments) {
            $value = $this->resolveClaimPath($claims, $segments);
            if (is_array($value)) {
                $oidcRoles = array_values(array_unique(array_merge($oidcRoles, $value)));
            }
        }

        if (empty($oidcRoles)) {
            return;
        }

        $userDetails = $user->getUserDetails() ?? [];
        $userDetails['oidc_roles'] = $oidcRoles;
        $user->setUserDetails($userDetails);

        $hasAdmin = !empty(array_intersect(array_map('strtolower', $oidcRoles), $this->adminRoleNames));
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
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string>        $segments
     */
    private function resolveClaimPath(array $data, array $segments): mixed
    {
        $current = $data;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @return array<array<string>>
     */
    private function parseRoleClaims(string $oidcRoleClaims, string $clientId): array
    {
        $raw = array_map('trim', explode(',', $oidcRoleClaims));

        $paths = [];
        foreach ($raw as $path) {
            if ('' === $path) {
                continue;
            }
            $path = str_replace('{client_id}', $clientId, $path);
            $segments = preg_split('/(?<!\\\\)\./', $path);
            $segments = array_map(static fn (string $s) => str_replace('\\.', '.', $s), $segments);
            $paths[] = $segments;
        }

        return $paths;
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
