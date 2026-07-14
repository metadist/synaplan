<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\UserRepository;
use App\Service\UserLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Programmatic user provisioning for trusted integrations (Nextcloud,
 * ownCloud, …) driven by an admin API key.
 *
 * An external system maps its own users into Synaplan by an
 * (external_source, external_id) pair — e.g. source="nextcloud",
 * external_id="<instance-id>:<nc-uid>". Provisioning is idempotent on that
 * pair: calling it twice for the same identity returns the same Synaplan user.
 *
 * The external identity is stored inside the User's userDetails JSON (the same
 * place the OIDC "sub" lives), so this needs NO schema migration and is safe on
 * the production Galera cluster.
 */
final class AdminUserProvisioningService
{
    /** Levels an integration may assign. ADMIN and ANONYMOUS are deliberately excluded. */
    private const ASSIGNABLE_LEVELS = ['NEW', 'PRO', 'TEAM', 'BUSINESS'];

    public const PROVIDER_ID = 'external';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ApiKeyRepository $apiKeyRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserLifecycleService $userLifecycleService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Find a user previously provisioned for the given external identity.
     */
    public function findByExternalIdentity(string $source, string $externalId): ?User
    {
        $source = trim($source);
        $externalId = trim($externalId);
        if ('' === $source || '' === $externalId) {
            return null;
        }

        // Match the two JSON markers we write in provision(). Doctrine has no
        // portable JSON operator here, so a LIKE on the serialized column is
        // used — exactly like OidcUserService::findBySub().
        $qb = $this->userRepository->createQueryBuilder('u');
        $qb->where('u.userDetails LIKE :src')
            ->andWhere('u.userDetails LIKE :ext')
            ->setParameter('src', '%"external_source":"'.$this->escapeLike($source).'"%')
            ->setParameter('ext', '%"external_id":"'.$this->escapeLike($externalId).'"%')
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Idempotently create (or fetch) a Synaplan user for an external identity.
     *
     * When $plainPassword is given, the new account can additionally sign in
     * via the regular email/password login (used e.g. by E2E test setup to
     * provision ready-to-use users without the email verification roundtrip).
     * The password is only set at creation time — an idempotent hit never
     * overwrites credentials.
     *
     * @return array{user: User, created: bool}
     */
    public function provision(
        string $source,
        string $externalId,
        string $email,
        ?string $displayName = null,
        string $level = 'NEW',
        ?string $plainPassword = null,
    ): array {
        $source = trim($source);
        $externalId = trim($externalId);
        $email = trim($email);
        $level = strtoupper(trim($level));

        if ('' === $source || '' === $externalId) {
            throw new \InvalidArgumentException('source and external_id are required');
        }
        if ('' === $email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email is required');
        }
        if (!in_array($level, self::ASSIGNABLE_LEVELS, true)) {
            throw new \InvalidArgumentException('level must be one of: '.implode(', ', self::ASSIGNABLE_LEVELS));
        }
        if (null !== $plainPassword && strlen($plainPassword) < 8) {
            throw new \InvalidArgumentException('password must be at least 8 characters');
        }

        $existing = $this->findByExternalIdentity($source, $externalId);
        if (null !== $existing) {
            $this->syncDetails($existing, $email, $displayName);
            $this->em->flush();

            return ['user' => $existing, 'created' => false];
        }

        // Guard against hijacking an email that already belongs to a different
        // account (local/google/keycloak/…). Mirrors OIDC strict isolation.
        $byEmail = $this->userRepository->findOneBy(['mail' => $email]);
        if (null !== $byEmail) {
            throw new UserProvisioningConflictException(sprintf('Email "%s" already belongs to a Synaplan account created via "%s". Refusing to attach the external identity.', $email, $byEmail->getProviderId() ?: 'unknown'));
        }

        $user = $this->userLifecycleService->createUser(
            email: $email,
            plainPassword: $plainPassword,
            providerId: self::PROVIDER_ID,
            userLevel: $level,
            emailVerified: true,
            userDetails: [
                'external_source' => $source,
                'external_id' => $externalId,
                'display_name' => $displayName ?? '',
                'provisioned_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            ],
        );

        $this->logger->info('Provisioned external user', [
            'user_id' => $user->getId(),
            'source' => $source,
            'external_id' => $externalId,
        ]);

        return ['user' => $user, 'created' => true];
    }

    /**
     * Mint a fresh per-user API key on behalf of a user. The plaintext key is
     * returned ONCE — the caller must store it; only a prefix is persisted for
     * display.
     *
     * @param string[] $scopes
     *
     * @return array{entity: ApiKey, plainKey: string}
     */
    public function mintApiKeyForUser(User $user, string $name, array $scopes): array
    {
        $plainKey = 'sk_'.bin2hex(random_bytes(29));

        $apiKey = new ApiKey();
        $apiKey->setOwner($user);
        $apiKey->setKey($plainKey);
        $apiKey->setName('' !== trim($name) ? trim($name) : 'external-integration');
        $apiKey->setStatus('active');
        $apiKey->setScopes([] !== $scopes ? array_values($scopes) : ['*']);

        $this->apiKeyRepository->save($apiKey);

        $this->logger->info('Minted API key on behalf of user', [
            'user_id' => $user->getId(),
            'key_id' => $apiKey->getId(),
            'scopes' => $apiKey->getScopes(),
        ]);

        return ['entity' => $apiKey, 'plainKey' => $plainKey];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeUser(User $user): array
    {
        $details = $user->getUserDetails();

        return [
            'id' => $user->getId(),
            'email' => $user->getMail(),
            'level' => $user->getUserLevel(),
            'providerId' => $user->getProviderId(),
            'external_source' => $details['external_source'] ?? null,
            'external_id' => $details['external_id'] ?? null,
            'display_name' => $details['display_name'] ?? null,
            'created' => $user->getCreated(),
            'isAdmin' => $user->isAdmin(),
        ];
    }

    private function syncDetails(User $user, string $email, ?string $displayName): void
    {
        $details = $user->getUserDetails();
        $changed = false;

        // Only update the email if it is still free — never silently move it
        // onto another existing account.
        if ('' !== $email && $email !== $user->getMail()) {
            $other = $this->userRepository->findOneBy(['mail' => $email]);
            if (null === $other) {
                $user->setMail($email);
                $changed = true;
            }
        }

        if (null !== $displayName && ($details['display_name'] ?? null) !== $displayName) {
            $details['display_name'] = $displayName;
            $user->setUserDetails($details);
            $changed = true;
        }

        if ($changed) {
            $this->logger->info('Synced provisioned user details', ['user_id' => $user->getId()]);
        }
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_"\\');
    }
}
