<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\UserRepository;
use App\Service\Auth\OidcClaimResolver;
use App\Service\Iam\DirectoryGroupSync;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

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
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
        private ExternalIdentityRepository $externalIdentityRepository,
        private OidcClaimResolver $claimResolver,
        private DirectoryGroupSync $directoryGroupSync,
        string $oidcAdminRoles,
        string $oidcRoleClaims,
        string $oidcClientId,
        private string $oidcDiscoveryUrl = '',
    ) {
        $this->adminRoleNames = array_map('strtolower', array_map('trim', explode(',', $oidcAdminRoles)));
        $this->roleClaimPaths = $this->claimResolver->paths($oidcRoleClaims, $oidcClientId);
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

        $user = $this->findBySub($claims) ?? $this->findByEmail($email);
        $isNewUser = false;

        if ($user) {
            // Strict isolation for enterprise users: if an email is already registered
            // via Google/GitHub/Local, we DO NOT merge it with a Keycloak login.
            if ('keycloak' !== $user->getProviderId()) {
                $this->logger->warning('OIDC login attempt for email registered to different provider', [
                    'email' => $email,
                    'existing_provider' => $user->getProviderId(),
                ]);
                throw new CustomUserMessageAuthenticationException('Authentication failed.');
            }

            $this->logger->info('Existing user logging in via OIDC', [
                'user_id' => $user->getId(),
                'original_provider' => $user->getProviderId(),
            ]);
        } else {
            $isNewUser = true;
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
        $lastSeen = $this->lastSeenForClaims($user, $claims);
        $this->upsertExternalIdentity($user, $claims);
        if ($this->directoryGroupSync->shouldRun($user, $refreshToken, $lastSeen)) {
            // Group reconciliation is best-effort: a malformed claim or a
            // transient write failure must not turn a valid login into an error.
            try {
                $this->directoryGroupSync->sync($user, $claims);
            } catch (\Throwable $e) {
                $this->logger->error('Directory group sync failed; login continues without group changes', [
                    'user_id' => $user->getId(),
                    'exception' => $e,
                ]);
            }
        }

        if ($isNewUser) {
            $this->modelConfigService->initializeNewUserDefaults($user->getId());
        }

        return $user;
    }

    private function syncRoles(User $user, array $claims): void
    {
        $oidcRoles = [];
        foreach ($this->roleClaimPaths as $segments) {
            $value = $this->claimResolver->resolve($claims, $segments);
            if (is_array($value)) {
                $oidcRoles = array_values(array_unique(array_merge($oidcRoles, $value)));
            }
        }

        if (empty($oidcRoles)) {
            return;
        }

        $userDetails = $user->getUserDetails();
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
     * Resolve by the configured issuer first so a colliding `sub` from another
     * IdP cannot log into the wrong local account. Legacy JSON `oidc_sub` is
     * the fallback for rows written before BEXTERNALIDENTITIES existed.
     *
     * @param array<string, mixed> $claims
     */
    private function findBySub(array $claims): ?User
    {
        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || '' === $sub) {
            return null;
        }

        $identity = $this->externalIdentityRepository->findOneByTriple(
            $this->oidcSource($claims),
            '',
            $sub,
        );
        if (null !== $identity) {
            $user = $this->userRepository->find($identity->getUserId());
            if ($user instanceof User) {
                return $user;
            }
        }

        $qb = $this->userRepository->createQueryBuilder('u');
        $qb->where('u.userDetails LIKE :pattern')
            ->setParameter('pattern', '%"oidc_sub":"'.addcslashes($sub, '"\\').'"%')
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function upsertExternalIdentity(User $user, array $claims): void
    {
        $userId = $user->getId();
        $sub = $claims['sub'] ?? null;
        if (null === $userId || !is_string($sub) || '' === $sub) {
            return;
        }

        $this->externalIdentityRepository->upsert(
            (int) $userId,
            $this->oidcSource($claims),
            $sub,
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function lastSeenForClaims(User $user, array $claims): int
    {
        $sub = $claims['sub'] ?? null;
        $userId = $user->getId();
        if (null === $userId || !is_string($sub) || '' === $sub) {
            return 0;
        }
        $identity = $this->externalIdentityRepository->findOneByTriple(
            $this->oidcSource($claims),
            '',
            $sub,
        );

        return $identity?->getLastSeen() ?? 0;
    }

    private function oidcSource(array $claims): string
    {
        return 'oidc:'.$this->claimResolver->issuer($claims, $this->oidcDiscoveryUrl);
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
        $userDetails = $user->getUserDetails();
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
