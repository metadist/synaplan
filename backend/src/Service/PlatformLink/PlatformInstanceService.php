<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\Exception\PlatformInstanceNotActiveException;
use App\Service\PlatformLink\Exception\PlatformInstanceNotFoundException;
use App\Service\PlatformLink\Exception\PlatformInstanceSecretException;
use App\Service\PlatformLink\Exception\PlatformLinkLimitException;
use App\Service\PlatformLink\Exception\PlatformLinkValidationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlatformInstanceService
{
    public const REGISTER_IP_LIMIT = 10;
    public const REGISTER_IP_WINDOW = 3600;

    /** Matches BEXTERNALIDENTITIES.BEXTERNALID (VARCHAR(191)). */
    public const EXTERNAL_ID_MAX_LENGTH = 191;
    /** Generous cap for the partner's CSRF nonce, which only round-trips. */
    public const STATE_MAX_LENGTH = 512;

    public function __construct(
        private PlatformInstanceRepository $instanceRepository,
        private ExternalIdentityRepository $externalIdentityRepository,
        private ApiKeyRepository $apiKeyRepository,
        private RedirectUriPolicy $redirectUriPolicy,
        private PlatformLinkRateLimiter $rateLimiter,
        private AuditLogWriter $auditLogWriter,
        private EntityManagerInterface $entityManager,
        private LinkCodeService $linkCodeService,
    ) {
    }

    /**
     * @param list<mixed> $redirectUris
     *
     * @return array{instance_id: string, instance_secret: string, status: string}
     */
    public function register(
        string $client,
        string $host,
        array $redirectUris,
        ?User $actor,
        string $ip,
    ): array {
        $client = strtolower(trim($client));
        if (!\in_array($client, PlatformInstance::CLIENTS, true) || PlatformInstance::CLIENT_OUTLOOK === $client) {
            throw new PlatformLinkValidationException('client must be nextcloud, owncloud or opencloud.');
        }

        $isAdmin = $actor instanceof User && $actor->isAdmin();
        if (!$isAdmin && !$this->rateLimiter->allow(
            'platform_link:register_attempt:'.sha1('' !== $ip ? $ip : 'unknown'),
            self::REGISTER_IP_LIMIT,
            self::REGISTER_IP_WINDOW,
        )) {
            throw new PlatformLinkLimitException('Too many instance registrations from this address. Please wait and try again.');
        }

        $normalizedHost = $this->redirectUriPolicy->normalizeHost($host);
        $normalizedUris = $this->redirectUriPolicy->normalizeRedirectUris($normalizedHost, $redirectUris);

        $instanceId = 'pi_'.bin2hex(random_bytes(12));
        $secret = bin2hex(random_bytes(32));
        $status = $isAdmin ? PlatformInstance::STATUS_ACTIVE : PlatformInstance::STATUS_PENDING;

        $instance = new PlatformInstance(
            $client,
            $instanceId,
            $normalizedHost,
            $normalizedUris,
            $status,
            $actor instanceof User ? (int) $actor->getId() : 0,
        );
        $instance->setSecretHash(password_hash($secret, \PASSWORD_DEFAULT));
        $this->instanceRepository->save($instance);

        $this->auditLogWriter->record(
            $actor instanceof User ? (int) $actor->getId() : 0,
            'platform_instance.registered',
            'platform_instance',
            $instanceId,
            ['client' => $client, 'host' => $normalizedHost, 'status' => $status],
            $ip,
        );

        return [
            'instance_id' => $instanceId,
            'instance_secret' => $secret,
            'status' => $status,
        ];
    }

    /**
     * @return array{status: string, host: string, client: string}
     */
    public function describeSelf(string $instanceId, string $secret): array
    {
        $instance = $this->requireInstance($instanceId);
        $this->assertSecret($instance, $secret);
        $instance->touchLastSeen();
        $this->instanceRepository->save($instance);

        return [
            'status' => $instance->getStatus(),
            'host' => $instance->getHost(),
            'client' => $instance->getClient(),
        ];
    }

    /**
     * @return array{client: string, host: string}
     */
    public function describePublic(string $instanceId): array
    {
        $instance = $this->requireInstance($instanceId);
        if (!$instance->isActive()) {
            throw new PlatformInstanceNotFoundException('Instance not found.');
        }

        return [
            'client' => $instance->getClient(),
            'host' => $instance->getHost(),
        ];
    }

    /**
     * @return list<array{id: string, client: string, host: string, status: string, registeredBy: int, created: int, lastSeen: int}>
     */
    public function listForAdmin(): array
    {
        $out = [];
        foreach ($this->instanceRepository->findAllOrdered() as $instance) {
            $out[] = [
                'id' => $instance->getInstanceId(),
                'client' => $instance->getClient(),
                'host' => $instance->getHost(),
                'status' => $instance->getStatus(),
                'registeredBy' => $instance->getRegisteredBy(),
                'created' => $instance->getCreated(),
                'lastSeen' => $instance->getLastSeen(),
            ];
        }

        return $out;
    }

    public function approve(string $instanceId, User $admin, string $ip): void
    {
        $instance = $this->requireInstance($instanceId);
        if (PlatformInstance::STATUS_PENDING !== $instance->getStatus()) {
            throw new PlatformLinkValidationException('Only pending instances can be approved.');
        }
        $instance->setStatus(PlatformInstance::STATUS_ACTIVE);
        $this->instanceRepository->save($instance);
        $this->auditLogWriter->record(
            (int) $admin->getId(),
            'platform_instance.approved',
            'platform_instance',
            $instanceId,
            ['host' => $instance->getHost()],
            $ip,
        );
    }

    public function revoke(string $instanceId, User $admin, string $ip): void
    {
        $instance = $this->requireInstance($instanceId);
        if (PlatformInstance::OUTLOOK_BUILTIN_ID === $instance->getInstanceId()) {
            throw new PlatformLinkValidationException('The Outlook built-in instance cannot be revoked.');
        }
        $instance->setStatus(PlatformInstance::STATUS_REVOKED);
        $this->instanceRepository->save($instance);

        foreach ($this->externalIdentityRepository->findByInstanceId($instanceId) as $identity) {
            $keyId = $identity->getApiKeyId();
            if (null !== $keyId) {
                $key = $this->apiKeyRepository->find($keyId);
                if (null !== $key) {
                    $this->apiKeyRepository->remove($key, false);
                }
            }
            $this->externalIdentityRepository->remove($identity, false);
        }
        $this->entityManager->flush();

        $this->auditLogWriter->record(
            (int) $admin->getId(),
            'platform_instance.revoked',
            'platform_instance',
            $instanceId,
            ['host' => $instance->getHost()],
            $ip,
        );
    }

    /**
     * @return array{redirect: string}
     */
    public function issueCode(
        User $user,
        string $instanceId,
        string $externalId,
        string $redirectUri,
        string $state,
        bool $withMemories,
        string $ip,
    ): array {
        $instance = $this->requireActiveForCodes($instanceId);
        $externalId = trim($externalId);
        $redirectUri = trim($redirectUri);
        $state = trim($state);
        if ('' === $externalId) {
            throw new PlatformLinkValidationException('external_id is required.');
        }
        if (mb_strlen($externalId) > self::EXTERNAL_ID_MAX_LENGTH) {
            throw new PlatformLinkValidationException(sprintf('external_id must be at most %d characters.', self::EXTERNAL_ID_MAX_LENGTH));
        }
        if ('' === $state) {
            throw new PlatformLinkValidationException('state is required.');
        }
        if (mb_strlen($state) > self::STATE_MAX_LENGTH) {
            throw new PlatformLinkValidationException(sprintf('state must be at most %d characters.', self::STATE_MAX_LENGTH));
        }
        if (!$this->redirectUriPolicy->matchesRegisteredPrefix($redirectUri, $instance->getHost(), $instance->getRedirectUris())) {
            $this->auditLogWriter->record(
                (int) $user->getId(),
                'platform_link.redirect_rejected',
                'platform_instance',
                $instanceId,
                ['redirect_uri' => $redirectUri],
                $ip,
            );
            throw new PlatformLinkValidationException('Redirect URI is not registered for this instance.');
        }

        $issued = $this->linkCodeService->create([
            'userId' => (int) $user->getId(),
            'instanceId' => $instance->getInstanceId(),
            'externalId' => $externalId,
            'redirectUri' => $redirectUri,
            'withMemories' => $withMemories,
        ]);

        return [
            'redirect' => $this->redirectUriPolicy->buildCallbackRedirect($redirectUri, $issued['code'], $state),
        ];
    }

    public function requireActiveForCodes(string $instanceId): PlatformInstance
    {
        $instance = $this->requireInstance($instanceId);
        if (PlatformInstance::CLIENT_OUTLOOK === $instance->getClient()) {
            throw new PlatformLinkValidationException('Outlook uses the add-in connect flow, not a link code.');
        }
        if (!$instance->isActive()) {
            throw new PlatformInstanceNotActiveException('This platform is waiting for approval by the Synaplan administrator.');
        }

        return $instance;
    }

    public function requireInstance(string $instanceId): PlatformInstance
    {
        $instance = $this->instanceRepository->findByInstanceId($instanceId);
        if (!$instance instanceof PlatformInstance) {
            throw new PlatformInstanceNotFoundException('Instance not found.');
        }

        return $instance;
    }

    public function assertSecret(PlatformInstance $instance, string $secret): void
    {
        $hash = $instance->getSecretHash();
        if ('' === $hash || !password_verify($secret, $hash)) {
            throw new PlatformInstanceSecretException('Invalid instance credentials.');
        }
    }
}
